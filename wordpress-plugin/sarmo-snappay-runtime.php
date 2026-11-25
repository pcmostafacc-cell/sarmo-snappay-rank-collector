<?php
if (!defined('ABSPATH')) { exit; }

final class Sarmo_SnappPay_Runtime {
    private const SETTINGS = 'sarmo_monitor_bale_settings';
    private const ALERTS = 'sarmo_monitor_alert_states';
    private const REPORTS = 'sarmo_monitor_report_sent';
    private const HEALTH = 'sarmo_monitor_source_health';
    private const WEBHOOK_UPDATE = 'sarmo_monitor_bale_last_update_id';
    private const HOOK = 'sarmo_monitor_tick';
    private const KEYWORDS = 'sarmo_snappay_keywords';
    private const SCHEMA_VERSION = '0.7.0';
    private const MAX_KEYWORDS = 10;

    public static function init(): void {
        add_action('init', [self::class, 'maybe_upgrade']);
        add_filter('cron_schedules', [self::class, 'schedules']);
        add_action(self::HOOK, [self::class, 'tick']);
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_sarmo_monitor_bale_save', [self::class, 'save_settings']);
        add_action('admin_post_sarmo_monitor_bale_webhook', [self::class, 'set_webhook']);
        add_action('rest_api_init', [self::class, 'routes']);
    }

    public static function activate(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            query varchar(100) NOT NULL,
            observed_at datetime NOT NULL,
            source_valid tinyint(1) NOT NULL DEFAULT 0,
            grid_rank int(10) unsigned NULL,
            ad_rank int(10) unsigned NULL,
            absolute_ad_slot int(10) unsigned NULL,
            merchant_rank int(10) unsigned NULL,
            scanned_products int(10) unsigned NOT NULL DEFAULT 0,
            source_warning varchar(255) NOT NULL DEFAULT '',
            details longtext NULL,
            PRIMARY KEY  (id),
            KEY query_time (query,observed_at),
            KEY observed_at (observed_at)
        ) $charset;");
        if (get_option(self::SETTINGS, null) === null) {
            add_option(self::SETTINGS, [
                'token' => '', 'owner_chat_id' => '', 'webhook_secret' => bin2hex(random_bytes(32)),
            ], '', false);
        }
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + 60, 'sarmo_ten_minutes', self::HOOK);
        }
        if (!get_role('sarmo_collector')) {
            add_role('sarmo_collector', 'Sarmo Rank Collector', [
                'read' => true,
                'manage_sarmo_monitor' => true,
            ]);
        }
        $admin = get_role('administrator');
        if ($admin && !$admin->has_cap('manage_sarmo_monitor')) {
            $admin->add_cap('manage_sarmo_monitor');
        }
        update_option('sarmo_monitor_schema_version', self::SCHEMA_VERSION, false);
    }

    public static function maybe_upgrade(): void {
        if (get_option('sarmo_monitor_schema_version') !== self::SCHEMA_VERSION) {
            self::activate();
        }
    }

    public static function schedules(array $schedules): array {
        $schedules['sarmo_ten_minutes'] = ['interval' => 600, 'display' => 'Every 10 minutes (Sarmo)'];
        return $schedules;
    }

    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'sarmo_snappay_scans';
    }

    private static function settings(): array {
        return (array) get_option(self::SETTINGS, []);
    }

    private static function normalize(string $value): string {
        $value = str_replace(['ي', 'ك'], ['ی', 'ک'], sanitize_text_field($value));
        return mb_substr(preg_replace('/\s+/u', ' ', trim($value)) ?: '', 0, 100);
    }

    public static function record(array $row, WP_REST_Request $request): void {
        global $wpdb;
        $details = $request->get_param('details');
        if (!is_array($details)) { $details = []; }
        $details = [
            'own_products' => array_slice((array) ($details['own_products'] ?? []), 0, 30),
            'top_competitors' => array_slice((array) ($details['top_competitors'] ?? []), 0, 20),
            'scan_complete' => !empty($details['scan_complete']),
            'rank_scope' => sanitize_text_field((string) ($details['rank_scope'] ?? '')),
        ];
        $wpdb->insert(self::table(), [
            'query' => $row['query'], 'observed_at' => $row['observed_at'],
            'source_valid' => !empty($row['source_valid']) ? 1 : 0,
            'grid_rank' => $row['grid_rank'], 'ad_rank' => $row['ad_rank'],
            'absolute_ad_slot' => $row['absolute_ad_slot'],
            'merchant_rank' => $row['merchant_rank'],
            'scanned_products' => min(1000, max(0, (int) $request->get_param('scanned_products'))),
            'source_warning' => mb_substr((string) $row['source_warning'], 0, 255),
            'details' => wp_json_encode($details, JSON_UNESCAPED_UNICODE),
        ], ['%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s']);
        self::update_source_health($row);
        if (empty($row['source_valid'])) {
            self::break_alert_streak($row['query']);
            return;
        }
        // An incomplete scan can establish an observed rank, but cannot prove
        // that an advertisement is absent from the remaining results.
        if ($row['absolute_ad_slot'] === null && empty($details['scan_complete'])) {
            self::break_alert_streak($row['query']);
            return;
        }
        self::evaluate_alert($row);
    }

    private static function break_alert_streak(string $query): void {
        $states = (array) get_option(self::ALERTS, []);
        if (!isset($states[$query])) { return; }
        $states[$query]['loss_count'] = 0;
        $states[$query]['meet_count'] = 0;
        update_option(self::ALERTS, $states, false);
    }

    public static function reset_alert(string $query): void {
        $states = (array) get_option(self::ALERTS, []);
        unset($states[$query]);
        update_option(self::ALERTS, $states, false);
    }

    private static function update_source_health(array $row): void {
        $states = (array) get_option(self::HEALTH, []);
        $state = (array) ($states[$row['query']] ?? ['status' => 'ok']);
        if (empty($row['source_valid'])) {
            $warning = trim(sanitize_text_field((string) ($row['source_warning'] ?? '')));
            $warning = mb_substr($warning !== '' ? $warning : 'خطای نامشخص منبع پایش', 0, 180);
            $rate_limited = (bool) preg_match('/\b429\b|too\s+many|rate[ -]?limit|try\s+again\s+later|please\s+wait|تعداد\s+درخواست|بیش\s+از\s+حد|محدودیت|دوباره\s+تلاش\s+کنید|صبر\s+کن/iu', $warning);
            $signature = hash('sha256', $rate_limited ? 'snappay-provider-rate-limit' : $warning);
            if (!$rate_limited && ($state['status'] ?? 'ok') === 'failed' &&
                hash_equals((string) ($state['warning_signature'] ?? ''), $signature)) {
                return;
            }
            if ($rate_limited) {
                $reason = 'اسنپ‌پی درخواست ورود پیامکی را با محدودیت تعداد درخواست‌ها رد کرده (۴۲۹). OTP ارسال نشده و افت رتبه اعلام نمی‌شود؛ برای جلوگیری از تکرار، تلاش ورود تا پایان قفل بسته است. زمان مجازشدن را با /login_status ببین.';
            } else {
                $reason = "علت ثبت‌شده: $warning";
            }
            $last_global_rate_alert = (string) get_option('sarmo_monitor_rate_alert_signature', '');
            $last_global_rate_alert_at = (int) get_option('sarmo_monitor_rate_alert_at', 0);
            $already_reported = $rate_limited && hash_equals($last_global_rate_alert, $signature) &&
                time() - $last_global_rate_alert_at < 300;
            if ($already_reported || self::send("⚠️ منبع پایش «{$row['query']}» نامعتبر است؛ افت رتبه اعلام نمی‌شود.\n$reason")) {
                $states[$row['query']] = [
                    'status' => 'failed', 'since' => current_time('mysql', true),
                    'warning_signature' => $signature,
                ];
                if ($rate_limited && !$already_reported) {
                    update_option('sarmo_monitor_rate_alert_signature', $signature, false);
                    update_option('sarmo_monitor_rate_alert_at', time(), false);
                }
                update_option(self::HEALTH, $states, false);
            }
            return;
        }
        if (($state['status'] ?? 'ok') === 'failed' && self::send(
            "✅ منبع پایش «{$row['query']}» دوباره مشاهدهٔ معتبر ثبت کرد."
        )) {
            $states[$row['query']] = ['status' => 'ok', 'since' => current_time('mysql', true)];
            update_option(self::HEALTH, $states, false);
        }
    }

    private static function latest(string $query, bool $valid_only = false): ?array {
        global $wpdb;
        $table = self::table();
        $where = $valid_only ? ' AND source_valid = 1' : '';
        $item = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE query = %s$where ORDER BY id DESC LIMIT 1", $query
        ), ARRAY_A);
        return $item ?: null;
    }

    private static function evaluate_alert(array $row): void {
        $rules = (array) get_option(self::KEYWORDS, []);
        $query = $row['query'];
        if (empty($rules[$query]['campaign'])) { return; }
        $target = max(1, (int) ($rules[$query]['target'] ?? 1));
        $slot = $row['absolute_ad_slot'];
        $ok = $slot !== null && (int) $slot <= $target;
        $states = (array) get_option(self::ALERTS, []);
        $state = (array) ($states[$query] ?? ['status' => 'unknown', 'loss_count' => 0, 'meet_count' => 0]);
        if ($ok) {
            $state['loss_count'] = 0;
            $state['meet_count'] = (int) ($state['meet_count'] ?? 0) + 1;
            if ($state['meet_count'] >= 2 && $state['status'] === 'out_of_target') {
                if (self::send("✅ جایگاه آگهی «$query» به محدوده برگشت؛ جایگاه مطلق {$slot}، هدف حداکثر $target.")) {
                    $state['status'] = 'in_target';
                }
            } elseif ($state['meet_count'] >= 2 && $state['status'] === 'unknown') {
                $state['status'] = 'in_target';
            }
        } else {
            $state['meet_count'] = 0;
            $state['loss_count'] = (int) ($state['loss_count'] ?? 0) + 1;
            if ($state['loss_count'] >= 2 && $state['status'] !== 'out_of_target') {
                $rank = $slot === null ? 'آگهی سارمو در اسکن کامل یافت نشد' : (string) $slot;
                $message = $state['status'] === 'in_target'
                    ? "⚠️ جایگاه آگهی «$query» پس از دو مشاهدهٔ معتبر از هدف خارج شد. جایگاه مطلق: $rank؛ هدف: حداکثر $target."
                    : "ℹ️ هدف کمپین «$query» در دو مشاهدهٔ معتبر برقرار نبود. جایگاه مطلق: $rank؛ هدف: حداکثر $target.";
                if (self::send($message)) {
                    $state['status'] = 'out_of_target';
                }
            }
        }
        $states[$query] = $state;
        update_option(self::ALERTS, $states, false);
    }

    private static function send(string $message, ?string $chat_id = null): bool {
        $settings = self::settings();
        $token = (string) ($settings['token'] ?? '');
        $chat_id = $chat_id ?? (string) ($settings['owner_chat_id'] ?? '');
        if ($token === '' || $chat_id === '') { return false; }
        $response = wp_remote_post('https://tapi.bale.ai/bot' . rawurlencode($token) . '/sendMessage', [
            'timeout' => 15,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode(['chat_id' => $chat_id, 'text' => mb_substr($message, 0, 3500)]),
        ]);
        if (is_wp_error($response)) { return false; }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        return wp_remote_retrieve_response_code($response) === 200 && !empty($body['ok']);
    }

    public static function notify_owner(string $message): bool {
        return self::send($message);
    }

    public static function persistent_login(WP_REST_Request $request) {
        $state = $request->get_param('state');
        if (!in_array($state, ['required', 'scanned'], true)) {
            return new WP_Error('bad_login_state', 'Unknown login state', ['status' => 400]);
        }
        $pending = (bool) get_option('sarmo_monitor_persistent_login_pending', false);
        if ($pending === ($state === 'required')) {
            return rest_ensure_response(['ok' => true]);
        }
        if ($state === 'required') {
            $url = defined('SARMO_MONITOR_LOGIN_URL') ? SARMO_MONITOR_LOGIN_URL : '';
            $parts = is_string($url) ? wp_parse_url($url) : false;
            if (empty($parts['host']) || ($parts['scheme'] ?? '') !== 'https' ||
                isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
                return new WP_Error('missing_login_url', 'Configure a private HTTPS desktop gateway URL', ['status' => 503]);
            }
            $message = "اسنپ‌پی دوباره ورود می‌خواهد. این لینک را باز کن و در مرورگر جمع‌آورنده وارد شو. کد را مستقیم در خود اسنپ‌پی وارد کن.
" . $url;
        } else {
            $message = "✅ اولین اسکن معتبر پس از درخواست ورود ثبت شد.
" . self::report(false);
        }
        if (!self::notify_owner($message)) {
            return new WP_Error('bale_delivery_failed', 'Bale did not accept the notice', ['status' => 502]);
        }
        update_option('sarmo_monitor_persistent_login_pending', $state === 'required', false);
        return rest_ensure_response(['ok' => true]);
    }

    public static function tick(): void {
        $settings = self::settings();
        if (empty($settings['token']) || empty($settings['owner_chat_id'])) { return; }
        $tz = new DateTimeZone('Asia/Tehran');
        $now = new DateTimeImmutable('now', $tz);
        $hour = $now->format('Y-m-d-H');
        $day = $now->format('Y-m-d');
        $sent = (array) get_option(self::REPORTS, []);
        if (($sent['hourly'] ?? '') !== $hour) {
            if (self::send(self::report(false))) {
                $sent['hourly'] = $hour;
            }
        }
        if ($now->format('H') === '09' && ($sent['daily'] ?? '') !== $day) {
            if (self::send(self::report(true))) {
                $sent['daily'] = $day;
            }
        }
        update_option(self::REPORTS, $sent, false);
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . self::table() . ' WHERE observed_at < %s',
            gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS)
        ));
    }

    private static function report(bool $daily): string {
        $rules = (array) get_option(self::KEYWORDS, []);
        $lines = [$daily ? 'گزارش روزانهٔ جایگاه سارمو' : 'گزارش ساعتی جایگاه سارمو'];
        global $wpdb;
        foreach ($rules as $query => $rule) {
            $attempt = self::latest((string) $query, false);
            if ($attempt && empty($attempt['source_valid'])) {
                $lines[] = "$query: آخرین اسکن نامعتبر است؛ خطای منبع افت رتبه محسوب نمی‌شود";
            }
            if ($daily) {
                $since = gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS);
                $stats = $wpdb->get_row($wpdb->prepare(
                    'SELECT COUNT(*) AS valid_scans, MIN(grid_rank) AS best_grid, MIN(absolute_ad_slot) AS best_ad_slot FROM ' . self::table() . ' WHERE query = %s AND source_valid = 1 AND observed_at >= %s',
                    (string) $query, $since
                ), ARRAY_A);
                $validScans = (int) ($stats['valid_scans'] ?? 0);
                $bestGrid = ($stats['best_grid'] ?? null) === null ? 'نامشخص' : (string) $stats['best_grid'];
                $bestAd = ($stats['best_ad_slot'] ?? null) === null ? 'نامشخص' : (string) $stats['best_ad_slot'];
                $lines[] = "  ۲۴ ساعت: $validScans اسکن معتبر؛ بهترین گرید $bestGrid؛ بهترین جایگاه مطلق آگهی $bestAd";
            }
            $item = self::latest((string) $query, true);
            if (!$item) {
                $lines[] = "$query: دادهٔ معتبر نداریم";
                continue;
            }
            $age = time() - strtotime($item['observed_at'] . ' UTC');
            if ($age > 90 * MINUTE_IN_SECONDS) {
                $lines[] = "$query: داده قدیمی است (" . round($age / 60) . ' دقیقه)';
                continue;
            }
            $rank = $item['grid_rank'] === null ? 'تا عمق اسکن یافت نشد' : 'رتبهٔ ' . $item['grid_rank'];
            $ad = $item['absolute_ad_slot'] === null
                ? 'جایگاه مطلق آگهی نامشخص'
                : 'جایگاه مطلق آگهی ' . $item['absolute_ad_slot'];
            $adRank = $item['ad_rank'] === null ? 'رتبه بین آگهی‌ها نامشخص' : 'رتبه بین آگهی‌ها ' . $item['ad_rank'];
            $lines[] = "$query: $rank؛ $ad؛ $adRank؛ اسکن {$item['scanned_products']} محصول";
        }
        $lines[] = 'زمان: ' . wp_date('Y/m/d H:i', time(), new DateTimeZone('Asia/Tehran'));
        return implode("\n", $lines);
    }

    public static function routes(): void {
        register_rest_route('sarmo-monitor/v1', '/bale/(?P<secret>[a-f0-9]{64})', [
            'methods' => 'POST', 'callback' => [self::class, 'webhook'],
            'permission_callback' => static function (WP_REST_Request $request): bool {
                $settings = self::settings();
                return !empty($settings['webhook_secret']) && hash_equals(
                    (string) $settings['webhook_secret'], (string) $request['secret']
                );
            },
        ]);
    }

    public static function webhook(WP_REST_Request $request): WP_REST_Response {
        $body = $request->get_json_params();
        $message = (array) ($body['message'] ?? []);
        $chat_id = (string) ($message['chat']['id'] ?? '');
        $settings = self::settings();
        if ($chat_id === '' || !hash_equals((string) ($settings['owner_chat_id'] ?? ''), $chat_id)) {
            return new WP_REST_Response(['ok' => true]);
        }
        $update_id = absint($body['update_id'] ?? 0);
        $last_update_id = absint(get_option(self::WEBHOOK_UPDATE, 0));
        if ($update_id > 0 && $update_id <= $last_update_id) {
            return new WP_REST_Response(['ok' => true]);
        }
        if ($update_id > 0) {
            update_option(self::WEBHOOK_UPDATE, $update_id, false);
        }
        $text = trim((string) ($message['text'] ?? ''));
        $normalized_text = strtr($text, [
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
        ]);
        $parts = preg_split('/\s+/u', $normalized_text, 2);
        $command = strtolower((string) ($parts[0] ?? ''));
        $remainder = trim((string) ($parts[1] ?? ''));
        if (preg_match('/^[0-9]{4,8}$/', $normalized_text)) {
            $command = '/otp';
            $remainder = $normalized_text;
        }
        $query = self::normalize($remainder);
        $rules = (array) get_option(self::KEYWORDS, []);
        $reply = '';
        switch ($command) {
            case '/start': case '/help': case '/panel':
                $reply = "/list\n/add کلیدواژه\n/remove کلیدواژه\n/report [کلیدواژه]\n/rank بازه کلیدواژه\n/ads [صفحه]\n/ad نام کمپین\n/bid_preview کمپین تومان\n/competitors کلیدواژه\n/campaign کلیدواژه جایگاه\n/campaign_off کلیدواژه\n/campaign_report [کلیدواژه]\n/catalog\n/recommend [کلیدواژه]\n/login شمارهٔ موبایل اسنپ‌پی\n/otp کد پیامک (فقط پس از درخواست ربات)\n/login_status\n/status";
                break;
            case '/list':
                $lines = ['واژه‌های پایش:'];
                foreach ($rules as $name => $rule) {
                    $lines[] = $name . (!empty($rule['campaign']) ? ' | هدف جایگاه مطلق ' . (int) $rule['target'] : ' | عادی');
                }
                $reply = implode("\n", $lines);
                break;
            case '/add':
                if ($query !== '') {
                    if (!isset($rules[$query]) && count($rules) >= self::MAX_KEYWORDS) {
                        $reply = 'برای کنترل مصرف، حداکثر ' . self::MAX_KEYWORDS . ' کلیدواژه می‌توان داشت.';
                    } else {
                        $rules[$query] = $rules[$query] ?? ['target' => 1, 'campaign' => false];
                        update_option(self::KEYWORDS, $rules, false);
                        $reply = "«$query» به پایش اضافه شد.";
                    }
                }
                break;
            case '/remove':
                if ($query !== '') {
                    unset($rules[$query]);
                    update_option(self::KEYWORDS, $rules, false);
                    self::reset_alert($query);
                    $reply = "«$query» از پایش حذف شد.";
                }
                break;
            case '/campaign':
                $target = 0;
                $campaign_query = $query;
                if (preg_match('/^(.*?)\s+([0-9]{1,3})$/u', $remainder, $matches)) {
                    $campaign_query = self::normalize((string) $matches[1]);
                    $target = (int) $matches[2];
                }
                if ($campaign_query !== '' && !isset($rules[$campaign_query]) && count($rules) >= self::MAX_KEYWORDS) {
                    $reply = 'برای کنترل مصرف، حداکثر ' . self::MAX_KEYWORDS . ' کلیدواژه می‌توان داشت.';
                    break;
                }
                if ($campaign_query !== '' && $target >= 1 && $target <= 100) {
                    $rules[$campaign_query] = ['target' => $target, 'campaign' => true];
                    update_option(self::KEYWORDS, $rules, false);
                    self::reset_alert($campaign_query);
                    $reply = "هدف «$campaign_query» حداکثر جایگاه مطلق آگهی $target ثبت شد.";
                }
                break;
            case '/campaign_off':
                if ($query !== '' && isset($rules[$query])) {
                    $rules[$query]['campaign'] = false;
                    update_option(self::KEYWORDS, $rules, false);
                    self::reset_alert($query);
                    $reply = "هشدار کمپین «$query» خاموش شد.";
                }
                break;
            case '/campaign_report':
                $reply = self::campaign_report($query);
                break;
            case '/competitors':
                $reply = self::competitors_report($query);
                break;
            case '/catalog':
                $reply = 'فید کاتالوگ سارمو به افزونه وصل نشده است؛ تشخیص فعلی از کارت‌های جست‌وجو و فروشنده انجام می‌شود.';
                break;
            case '/recommend':
                $reply = 'منبع کمپین و هزینهٔ Search Ads هنوز وصل نیست؛ پیشنهاد قیمت یا بودجه ساخته نمی‌شود. فعلاً از /report و /campaign_report برای جایگاه استفاده کن.';
                break;
            case '/rank':
                $reply = self::rank_window($remainder);
                break;
            case '/ads':
                $reply = Sarmo_SnappPay_Ads::active_list($remainder);
                break;
            case '/ad':
                $reply = Sarmo_SnappPay_Ads::detail($remainder, static fn(string $keyword): string => self::single_report($keyword));
                break;
            case '/bid_preview':
                $reply = Sarmo_SnappPay_Ads::bid_preview($remainder);
                break;
            case '/report':
                $reply = $query === '' ? self::report(false) : self::single_report($query);
                break;
            case '/status':
                $reply = "وضعیت پایش:\n" . self::report(false) . "\n\nورود: " . Sarmo_SnappPay_Auth::status() . "\nSearch Ads: منبع کمپین/هزینه متصل نیست.";
                break;
            case '/login':
                if (Sarmo_SnappPay_Auth::set_phone($remainder)) {
                    $reply = 'یک تلاش ورود برای ۳۰ دقیقه مجاز شد. شماره فقط یک‌بار فرستاده می‌شود؛ کد را فقط اگر پیامک همان تلاش رسید بفرست.';
                } else {
                    $reply = "تلاش ورود مجاز نشد.\n" . Sarmo_SnappPay_Auth::status() . "\nقالب دستور: /login 09xxxxxxxxx";
                }
                break;
            case '/otp':
                $code = $remainder;
                $reply = Sarmo_SnappPay_Auth::receive_otp($code) ?
                    'کد دریافت شد و برای ورود استفاده می‌شود.' :
                    "کد ثبت نشد یا درخواست ورود فعال نیست.\n" . Sarmo_SnappPay_Auth::status();
                break;
            case '/login_status':
                $reply = Sarmo_SnappPay_Auth::status();
                break;
        }
        if ($reply === '') { $reply = 'فرمان نامعتبر است. /help'; }
        self::send($reply, $chat_id);
        return new WP_REST_Response(['ok' => true]);
    }

    private static function rank_window(string $argument): string {
        $windows = ['1h' => 3600, '6h' => 21600, '24h' => 86400, '7d' => 604800, '30d' => 2592000];
        $parts = preg_split('/\s+/u', trim($argument), 2);
        $key = strtolower($parts[0] ?? '');
        $query = self::normalize($parts[1] ?? '');
        if ((!isset($windows[$key]) && $key !== 'today') || $query === '') {
            return 'قالب: /rank 6h عبا | بازه‌ها: 1h، 6h، 24h، 7d، 30d، today';
        }
        $rules = (array) get_option(self::KEYWORDS, []);
        if (!isset($rules[$query])) { return "«$query» در لیست پایش نیست."; }
        $now = time();
        $tz = new DateTimeZone('Asia/Tehran');
        $since = $key === 'today' ? (new DateTimeImmutable('today', $tz))->getTimestamp() : $now - $windows[$key];
        global $wpdb;
        $table = self::table();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT observed_at, source_valid, grid_rank, absolute_ad_slot, details FROM $table
            WHERE query = %s AND observed_at >= %s AND observed_at <= %s ORDER BY observed_at DESC, id DESC",
            $query, gmdate('Y-m-d H:i:s', $since), gmdate('Y-m-d H:i:s', $now)
        ), ARRAY_A);
        if (!is_array($rows) || !$rows) { return "«$query» در بازهٔ $key اسکن ثبت‌شده ندارد."; }
        $valid = 0;
        $measured = 0;
        $top3 = 0;
        $best = null;
        $latest = null;
        foreach ($rows as $row) {
            if ((int) $row['source_valid'] !== 1) { continue; }
            $valid++;
            $latest ??= $row;
            $slot = (int) $row['absolute_ad_slot'];
            $details = json_decode((string) $row['details'], true);
            if ($slot <= 0 && empty($details['scan_complete'])) { continue; }
            $measured++;
            if ($slot > 0) {
                $best = $best === null ? $slot : min($best, $slot);
                if ($slot <= 3) { $top3++; }
            }
        }
        $lines = ["جایگاه در جست‌وجوی «$query» | $key", 'نمونه‌های معتبر: ' . $valid . ' از ' . count($rows)];
        if ((int) $rows[0]['source_valid'] !== 1) { $lines[] = 'جدیدترین تلاش نامعتبر بود.'; }
        if ($latest) {
            $time = strtotime($latest['observed_at'] . ' UTC');
            $grid = (int) $latest['grid_rank'];
            $slot = (int) $latest['absolute_ad_slot'];
            $lines[] = 'آخرین اسکن معتبر ' . wp_date('Y/m/d H:i', $time, $tz) . ' تهران';
            $lines[] = 'جایگاه در کل نتایج: ' . ($grid > 0 ? $grid : 'مشاهده نشد') .
                ' | جایگاه مطلق آگهی: ' . ($slot > 0 ? $slot : 'مشاهده نشد');
        }
        $lines[] = 'بهترین جایگاه مطلق آگهی: ' . ($best ?? 'نامشخص') .
            ' | حضور در ۳ رتبهٔ اول: ' . ($measured ? "$top3 از $measured اسکن قابل قضاوت" : 'نمونهٔ قابل قضاوت ندارد');
        return implode("\n", $lines);
    }

    private static function single_report(string $query): string {
        $item = self::latest($query, true);
        if (!$item) { return "برای «$query» مشاهدهٔ معتبر ثبت نشده است."; }
        $age = max(0, time() - strtotime($item['observed_at'] . ' UTC'));
        $rank = $item['grid_rank'] === null ? 'تا عمق اسکن یافت نشد' : 'رتبهٔ ' . $item['grid_rank'];
        $slot = $item['absolute_ad_slot'] === null ? 'نامشخص' : (string) $item['absolute_ad_slot'];
        $adRank = $item['ad_rank'] === null ? 'نامشخص' : (string) $item['ad_rank'];
        return "«$query»: $rank، جایگاه مطلق آگهی $slot، رتبه بین آگهی‌ها $adRank" .
            '، تعداد کارت‌های اسکن‌شده ' . $item['scanned_products'] .
            '، سن داده ' . round($age / 60) . ' دقیقه.';
    }

    private static function campaign_report(string $query = ''): string {
        $rules = (array) get_option(self::KEYWORDS, []);
        $lines = ['وضعیت جایگاه کمپین‌های سارمو'];
        $found = false;
        foreach ($rules as $name => $rule) {
            if (empty($rule['campaign']) || ($query !== '' && $query !== $name)) { continue; }
            $found = true;
            $target = max(1, (int) ($rule['target'] ?? 1));
            $item = self::latest((string) $name, true);
            if (!$item) {
                $lines[] = "$name: هدف حداکثر جایگاه $target؛ هنوز اسکن معتبر نداریم.";
                continue;
            }
            $age = max(0, time() - strtotime($item['observed_at'] . ' UTC'));
            $slot = $item['absolute_ad_slot'] === null ? 'در اسکن کامل یافت نشد' : (string) $item['absolute_ad_slot'];
            $state = (array) get_option(self::ALERTS, []);
            $status = (string) ($state[$name]['status'] ?? 'unknown');
            $lines[] = "$name: جایگاه مطلق $slot؛ هدف حداکثر $target؛ وضعیت $status؛ سن داده " . round($age / 60) . ' دقیقه.';
        }
        if (!$found) {
            return $query === '' ? 'هیچ کلیدواژه‌ای با هشدار کمپین فعال نیست.' : "برای «$query» هشدار کمپین فعال نیست.";
        }
        $lines[] = 'هزینه، bid و آمار پنل Ads هنوز متصل نیستند؛ این گزارش فقط جایگاه جست‌وجو را نشان می‌دهد.';
        return implode("\n", $lines);
    }

    private static function competitors_report(string $query): string {
        if ($query === '') { return 'قالب: /competitors کلیدواژه'; }
        $item = self::latest($query, true);
        if (!$item) { return "برای «$query» مشاهدهٔ معتبر نداریم."; }
        $details = json_decode((string) ($item['details'] ?? ''), true);
        $own_products = array_slice((array) ($details['own_products'] ?? []), 0, 5);
        $competitors = array_slice((array) ($details['top_competitors'] ?? []), 0, 8);
        if (!$own_products && !$competitors) { return "برای «$query» دادهٔ محصولات در آخرین اسکن موجود نیست."; }
        $observed_at = (string) ($item['observed_at'] ?? '');
        if ($observed_at === '') {
            $observed_at = 'نامشخص';
        } else {
            try {
                $observed_at = (new DateTimeImmutable($observed_at, new DateTimeZone('UTC')))
                    ->setTimezone(new DateTimeZone('Asia/Tehran'))->format('Y/m/d H:i');
            } catch (Throwable $error) {
                $observed_at = 'نامشخص';
            }
        }
        $lines = ["مقایسهٔ کارت‌های جست‌وجو برای «$query»؛ {$item['scanned_products']} کارت؛ زمان تهران: $observed_at"];
        $lines[] = 'محصولات سارمو:';
        if ($own_products) {
            foreach ($own_products as $product) {
                $rank = max(0, (int) ($product['rank'] ?? 0));
                $title = sanitize_text_field((string) ($product['title'] ?? 'محصول بدون عنوان'));
                $merchant = sanitize_text_field((string) ($product['merchant'] ?? 'سارمو استایل'));
                $ad = !empty($product['isAd']) ? ' | آگهی' : ' | نمایش عادی';
                $signals = self::card_signals((array) $product);
                $lines[] = "$rank. $title — $merchant$ad" . ($signals ? ' | ' . implode(' | ', $signals) : '');
            }
        } elseif (!empty($details['scan_complete'])) {
            $lines[] = 'در اسکن کامل دیده نشد.';
        } else {
            $lines[] = 'در کارت‌های اسکن‌شده دیده نشد؛ چون اسکن کامل نیست، نبودن محصول نتیجه‌گیری قطعی نیست.';
        }
        $lines[] = 'رقبا:';
        foreach ($competitors as $competitor) {
            $rank = max(0, (int) ($competitor['rank'] ?? 0));
            $merchant = sanitize_text_field((string) ($competitor['merchant'] ?? 'فروشنده نامشخص'));
            $title = sanitize_text_field((string) ($competitor['title'] ?? 'محصول بدون عنوان'));
            $ad = !empty($competitor['isAd']) ? ' | آگهی' : '';
            $signals = self::card_signals((array) $competitor);
            $lines[] = "$rank. $title — $merchant$ad" . ($signals ? ' | ' . implode(' | ', $signals) : '');
        }
        $lines[] = 'امتیاز، قیمت و تخفیف فقط در صورتی درج می‌شود که روی کارت جست‌وجو دیده شود.';
        $lines[] = 'قیمت کارت، قیمت خود محصول است؛ صفحهٔ فروشگاهی اسنپ‌پی هزینهٔ تبلیغ یا کلیک این جایگاه‌ها را نشان نمی‌دهد. برای هزینه باید گزارش پنل Search Ads وصل شود.';
        $lines[] = !empty($details['scan_complete'])
            ? 'اسکن تا انتهای نتایج قابل‌بارگذاری کامل بود.'
            : 'این مقایسه فقط کارت‌های بارگذاری‌شده را پوشش می‌دهد و اسکن کامل نشده است.';
        return implode("\n", $lines);
    }

    private static function card_signals(array $card): array {
        $price = sanitize_text_field((string) ($card['price'] ?? ''));
        $rating = sanitize_text_field((string) ($card['rating'] ?? ''));
        $review_count = sanitize_text_field((string) ($card['reviewCount'] ?? ''));
        $discount = sanitize_text_field((string) ($card['discount'] ?? ''));
        $availability = sanitize_text_field((string) ($card['availability'] ?? ''));
        return array_values(array_filter([
            $price !== '' ? 'قیمت کالا ' . $price : '',
            $rating !== '' ? 'امتیاز/نمرهٔ کارت ' . $rating : '',
            $review_count !== '' ? 'تعداد نظر ' . $review_count : '',
            $discount !== '' ? 'تخفیف ' . $discount : '',
            $availability !== '' ? 'موجودی ' . $availability : '',
        ]));
    }

    public static function menu(): void {
        add_management_page('اتصال بله سارمو', 'اتصال بله سارمو', 'manage_options',
            'sarmo-monitor-bale', [self::class, 'page']);
    }

    public static function save_settings(): void {
        if (!current_user_can('manage_options')) { wp_die('Forbidden', '', ['response' => 403]); }
        check_admin_referer('sarmo_monitor_bale_save');
        $settings = self::settings();
        $token = trim((string) wp_unslash($_POST['token'] ?? ''));
        if ($token !== '') { $settings['token'] = $token; }
        $settings['owner_chat_id'] = preg_replace('/[^0-9-]/', '', (string) ($_POST['owner_chat_id'] ?? ''));
        update_option(self::SETTINGS, $settings, false);
        wp_safe_redirect(admin_url('tools.php?page=sarmo-monitor-bale&saved=1'));
        exit;
    }

    public static function set_webhook(): void {
        if (!current_user_can('manage_options')) { wp_die('Forbidden', '', ['response' => 403]); }
        check_admin_referer('sarmo_monitor_bale_webhook');
        $settings = self::settings();
        if (empty($settings['token']) || empty($settings['owner_chat_id'])) {
            wp_safe_redirect(admin_url('tools.php?page=sarmo-monitor-bale&webhook=missing'));
            exit;
        }
        $url = rest_url('sarmo-monitor/v1/bale/' . $settings['webhook_secret']);
        $response = wp_remote_post('https://tapi.bale.ai/bot' . rawurlencode($settings['token']) . '/setWebhook', [
            'timeout' => 15, 'body' => ['url' => $url],
        ]);
        $body = is_wp_error($response) ? [] : json_decode(wp_remote_retrieve_body($response), true);
        $status = !empty($body['ok']) ? 'ok' : 'failed';
        wp_safe_redirect(admin_url('tools.php?page=sarmo-monitor-bale&webhook=' . $status));
        exit;
    }

    public static function page(): void {
        if (!current_user_can('manage_options')) { wp_die('Forbidden', '', ['response' => 403]); }
        $settings = self::settings();
        echo '<div class="wrap"><h1>اتصال بله و گزارش‌های سارمو</h1>';
        echo '<p>گزارش ساعتی و گزارش روزانهٔ ساعت ۹ تهران فقط با دادهٔ معتبر ارسال می‌شوند. خطای منبع به‌عنوان افت رتبه حساب نمی‌شود.</p>';
        if (isset($_GET['saved'])) { echo '<div class="notice notice-success"><p>تنظیمات ذخیره شد.</p></div>'; }
        if (isset($_GET['webhook'])) { echo '<div class="notice notice-info"><p>وضعیت ثبت وب‌هوک: ' . esc_html((string) $_GET['webhook']) . '</p></div>'; }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="sarmo_monitor_bale_save">';
        wp_nonce_field('sarmo_monitor_bale_save');
        echo '<p><label>توکن ربات بله <input type="password" name="token" autocomplete="new-password" size="48" placeholder="برای حفظ توکن قبلی خالی بگذارید"></label></p>';
        echo '<p><label>شناسهٔ گفتگوی مالک <input name="owner_chat_id" value="' . esc_attr((string) ($settings['owner_chat_id'] ?? '')) . '"></label></p>';
        echo '<p><button class="button button-primary">ذخیره</button></p></form>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="sarmo_monitor_bale_webhook">';
        wp_nonce_field('sarmo_monitor_bale_webhook');
        echo '<p><button class="button">ثبت وب‌هوک این وردپرس در بله</button></p></form>';
        echo '<p>تازه‌ترین وضعیت: ' . esc_html(self::report(false)) . '</p>';
        echo '<p>تا وقتی جمع‌آورندهٔ مرورگری متصل نشده باشد، رتبهٔ زنده نمایش داده نمی‌شود.</p></div>';
    }
}
