<?php
if (!defined('ABSPATH')) { exit; }

final class Sarmo_SnappPay_Auth {
    private const OPTION = 'sarmo_monitor_snappay_auth';
    private const CODESPACE = 'sarmo_monitor_codespace_name';
    private const CODESPACE_GRANT = 'sarmo_monitor_codespace_grant';
    private const ADS_SESSION = 'sarmo_monitor_ads_session';
    private const CODESPACE_WAITING = 'sarmo_monitor_codespace_waiting';
    private const CODESPACE_GRANT_PREFIX = 'sarmo_monitor_login_grant_';
    private const CLAIM_LOCK = 'sarmo_monitor_snappay_login_claim_lock';
    private const CHALLENGE_TTL = 300;
    private const LOGIN_ARM_TTL = 1800;
    private const LOGIN_COOLDOWN = 600;
    private const PROVIDER_RATE_LIMIT_COOLDOWN = 43200;

    public static function init(): void {
        add_action('rest_api_init', [self::class, 'routes']);
        add_action('admin_post_sarmo_monitor_codespace', [self::class, 'codespace_admin']);
    }

    private static function data(): array {
        return (array) get_option(self::OPTION, []);
    }

    private static function save(array $data): void {
        update_option(self::OPTION, $data, false);
    }

    private static function provider_rate_limit_until(array $data): int {
        $stored_until = (int) ($data['rate_limited_until'] ?? 0);
        if ($stored_until > time()) { return $stored_until; }

        // Recover a 429 lock from the sanitized collector observations after a
        // plugin update, even if the rate-limit callback did not reach WordPress.
        global $wpdb;
        if (!isset($wpdb) || !method_exists($wpdb, 'prepare') || !method_exists($wpdb, 'get_var')) {
            return 0;
        }
        $table = $wpdb->prefix . 'sarmo_snappay_scans';
        $observed_at = $wpdb->get_var($wpdb->prepare(
            "SELECT observed_at FROM $table WHERE source_warning LIKE %s ORDER BY id DESC LIMIT 1",
            '%429%'
        ));
        $observed_ts = $observed_at ? strtotime((string) $observed_at . ' UTC') : false;
        if ($observed_ts === false || time() - $observed_ts >= self::PROVIDER_RATE_LIMIT_COOLDOWN) {
            return 0;
        }
        $until = $observed_ts + self::PROVIDER_RATE_LIMIT_COOLDOWN;
        $data['rate_limited_until'] = $until;
        self::save($data);
        return $until;
    }

    private static function digits(string $value): string {
        return strtr($value, [
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
        ]);
    }

    public static function arm_test(): bool {
        $data = self::data();
        return self::set_phone((string) ($data['phone'] ?? ''), true);
    }

    private static function test_armed(array $data): bool {
        return !empty($data['test_login']) && (int) ($data['login_armed_until'] ?? 0) >= time();
    }

    public static function set_phone(string $phone, bool $test = false): bool {
        if (self::codespace_name() !== '') { return false; }
        $phone = preg_replace('/\D/', '', self::digits($phone));
        if (!preg_match('/^09[0-9]{9}$/', $phone)) { return false; }
        $data = self::data();
        if (!empty($data['challenge_at']) && time() - (int) $data['challenge_at'] <= self::CHALLENGE_TTL) {
            return false;
        }
        if (!$test && self::provider_rate_limit_until($data) > time()) { return false; }
        if (!empty($data['last_login_attempt_at']) && time() - (int) $data['last_login_attempt_at'] < self::LOGIN_COOLDOWN) {
            return false;
        }
        $data['phone'] = $phone;
        $data['test_login'] = $test;
        $data['login_armed_until'] = time() + self::LOGIN_ARM_TTL;
        unset($data['test_challenge'], $data['otp'], $data['otp_at'], $data['challenge_at'], $data['otp_consumed_at']);
        self::save($data);
        return true;
    }

    public static function receive_otp(string $code): bool {
        $code = preg_replace('/\D/', '', self::digits($code));
        $data = self::data();
        if ((empty($data['test_challenge']) && self::provider_rate_limit_until($data) > time()) || !preg_match('/^[0-9]{4,8}$/', $code) ||
            empty($data['challenge_at']) || time() - (int) $data['challenge_at'] > self::CHALLENGE_TTL) {
            return false;
        }
        $data['otp'] = $code;
        $data['otp_at'] = time();
        self::save($data);
        return true;
    }

    public static function status(): string {
        if (self::codespace_name() !== '') {
            return 'ورود دستی Codespaces فعال است. اگر اسنپ‌پی ورود بخواهد، از پیوند پنل پایش استفاده کن.';
        }
        $data = self::data();
        $rate_limited_until = self::provider_rate_limit_until($data);
        if (self::test_armed($data)) {
            return 'یک تلاش آزمایشی برای اجرای بعدی جمع‌آورنده مجاز است.';
        }
        if (!empty($data['test_challenge']) && !empty($data['challenge_at']) &&
            time() - (int) $data['challenge_at'] <= self::CHALLENGE_TTL) {
            return 'تلاش آزمایشی در جریان است. اگر پیامک همین تلاش رسید، کد را بفرست.';
        }
        if ($rate_limited_until > time()) {
            $time = wp_date('Y/m/d H:i', $rate_limited_until, new DateTimeZone('Asia/Tehran'));
            return "اسنپ‌پی درخواست کد را با خطای ۴۲۹ محدود کرده است؛ تا $time هیچ درخواست تازه‌ای فرستاده نمی‌شود.";
        }
        if (!empty($data['last_login_attempt_at']) && time() - (int) $data['last_login_attempt_at'] < self::LOGIN_COOLDOWN) {
            $until = (int) $data['last_login_attempt_at'] + self::LOGIN_COOLDOWN;
            $time = wp_date('Y/m/d H:i', $until, new DateTimeZone('Asia/Tehran'));
            return "برای جلوگیری از درخواست پیاپی، تلاش تازه تا $time بسته است.";
        }
        if (!empty($data['challenge_at']) && time() - (int) $data['challenge_at'] <= self::CHALLENGE_TTL) {
            return 'درخواست ورود فعال است؛ فقط کد تازهٔ همین درخواست را بفرست. مهلت ۵ دقیقه است.';
        }
        if (!empty($data['login_armed_until']) && (int) $data['login_armed_until'] >= time()) {
            return 'یک تلاش ورود مجاز شده است و تا اجرای جمع‌آورنده معتبر می‌ماند؛ پیامک فقط یک‌بار درخواست می‌شود.';
        }
        if (!empty($data['session'])) {
            return 'نشست ذخیره شده است؛ اعتبارش در اسکن بعدی بررسی می‌شود.';
        }
        return 'نشست معتبر یا اجازهٔ ورود نداریم. برای یک تلاش ورود تازه، /login شماره‌موبایل را بفرست.';
    }

    public static function codespace_name(): string {
        return (string) get_option(self::CODESPACE, '');
    }

    private static function codespace_origin(): string {
        return 'https://' . self::codespace_name() . '-6080.app.github.dev';
    }

    public static function codespace_admin(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Access denied', '', ['response' => 403]);
        }
        check_admin_referer('sarmo_monitor_codespace');
        $panel = admin_url('tools.php?page=sarmo-snappay-monitor');
        $prior = (string) get_option(self::CODESPACE_GRANT, '');
        if ($prior !== '') { delete_option(self::CODESPACE_GRANT_PREFIX . $prior); }
        delete_option(self::CODESPACE_GRANT);
        if (isset($_POST['codespace_name'])) {
            $name = trim((string) wp_unslash($_POST['codespace_name']));
            if ($name !== '' && !preg_match('/^[a-z0-9][a-z0-9-]{0,80}$/D', $name)) {
                wp_die('Invalid GitHub Codespace name', '', ['response' => 400]);
            }
            update_option(self::CODESPACE, $name, false);
            delete_option(self::CODESPACE_WAITING);
            wp_safe_redirect($panel);
            exit;
        }
        if (self::codespace_name() === '') {
            wp_safe_redirect($panel);
            exit;
        }
        $scope = ($_POST['login_scope'] ?? '') === 'ads' ? 'ads' : 'market';
        $grant = bin2hex(random_bytes(32));
        $hash = hash('sha256', $grant);
        if (!add_option(self::CODESPACE_GRANT_PREFIX . $hash, ['expires' => time() + 720, 'scope' => $scope], '', false)) {
            wp_die('Could not create a login authorization', '', ['response' => 500]);
        }
        update_option(self::CODESPACE_GRANT, $hash, false);
        nocache_headers();
        header('Referrer-Policy: no-referrer');
        // The destination is derived from the validated Codespace name. The grant
        // is in the fragment so it does not appear in GitHub HTTP request logs.
        wp_redirect(self::codespace_origin() . '/#grant=' . $grant, 303);
        exit;
    }

    public static function receive_codespace_session(WP_REST_Request $request) {
        $grant = $request->get_param('grant');
        if (self::codespace_name() === '' || !is_string($grant) || !preg_match('/^[a-f0-9]{64}$/D', $grant)) {
            return new WP_Error('bad_grant', 'Invalid login authorization', ['status' => 403]);
        }
        $hash = hash('sha256', $grant);
        $option = self::CODESPACE_GRANT_PREFIX . $hash;
        $grantData = get_option($option, 0);
        $scope = is_array($grantData) && ($grantData['scope'] ?? '') === 'ads' ? 'ads' : 'market';
        $expires = is_array($grantData) ? (int) ($grantData['expires'] ?? 0) : (int) $grantData;
        if (!hash_equals((string) get_option(self::CODESPACE_GRANT, ''), $hash) || $expires <= time()) {
            return new WP_Error('bad_grant', 'Invalid or expired login authorization', ['status' => 403]);
        }
        if (strlen($request->get_body()) > 151000) {
            return new WP_Error('too_large', 'Session document exceeds the limit', ['status' => 413]);
        }
        if ($request->get_param('check') === true) {
            $response = new WP_REST_Response(['ok' => true, 'scope' => $scope]);
            $response->header('Cache-Control', 'no-store');
            return $response;
        }
        $state = $request->get_param('session');
        if (!self::valid_codespace_session($state, $scope)) {
            return new WP_Error('bad_session', 'Invalid SnappPay session', ['status' => 400]);
        }
        $encrypted = self::encrypt($state);
        if ($encrypted === '') {
            return new WP_Error('bad_session', 'Could not store the session', ['status' => 400]);
        }
        if (!delete_option($option)) {
            return new WP_Error('bad_grant', 'Login authorization was already used', ['status' => 403]);
        }
        if ($scope === 'ads') {
            update_option(self::ADS_SESSION, $encrypted, false);
        } else {
            $data = self::data();
            $data['session'] = $encrypted;
            $data['last_auth_success_at'] = time();
            unset($data['login_armed_until'], $data['test_login'], $data['test_challenge'], $data['challenge_at'], $data['otp'], $data['otp_at'], $data['otp_consumed_at']);
            self::save($data);
        }
        update_option(self::waiting_option($scope), 'imported', false);
        $response = new WP_REST_Response(['ok' => true]);
        $response->header('Cache-Control', 'no-store');
        return $response;
    }

    private static function valid_codespace_session($state, string $scope): bool {
        $site = $scope === 'ads' ? 'ads.snapppay.ir' : 'app.snapppay.ir';
        $hosts = ['snapppay.ir', $site, 'sso.snapppay.ir'];
        if (!is_array($state) || !is_array($state['cookies'] ?? null) || !$state['cookies'] || !is_array($state['origins'] ?? null)) {
            return false;
        }
        foreach ($state['cookies'] as $cookie) {
            if (!is_array($cookie) || !is_string($cookie['domain'] ?? null) ||
                !in_array(ltrim($cookie['domain'], '.'), $hosts, true)) { return false; }
        }
        foreach ($state['origins'] as $entry) {
            if (!is_array($entry) || !is_string($entry['origin'] ?? null) ||
                !in_array($entry['origin'], array_map(static fn($host) => 'https://' . $host, $hosts), true)) { return false; }
        }
        return true;
    }

    private static function waiting_option(string $scope): string {
        return $scope === 'ads' ? self::CODESPACE_WAITING . '_ads' : self::CODESPACE_WAITING;
    }

    public static function ads_session(): WP_REST_Response {
        $response = new WP_REST_Response([
            'configured' => self::codespace_name() !== '',
            'session' => self::decrypt((string) get_option(self::ADS_SESSION, '')),
        ]);
        $response->header('Cache-Control', 'no-store, private');
        return $response;
    }

    public static function codespace_status(string $state, string $scope = 'market'): bool {
        $option = self::waiting_option($scope);
        $waiting = (string) get_option($option, '');
        if ($state === 'required') {
            if ($waiting === 'notified') { return true; }
            $panel = admin_url('tools.php?page=sarmo-snappay-monitor');
            $startup = 'https://github.com/codespaces/' . self::codespace_name();
            $title = $scope === 'ads' ? 'پنل تبلیغات اسنپ‌پی' : 'بازار اسنپ‌پی';
            $text = "$title دوباره ورود می‌خواهد. Codespace را از این لینک باز کن:\n$startup\nسپس در پنل پایش روی دکمهٔ ورودِ همان سرویس بزن و کد را در خود اسنپ‌پی وارد کن:\n$panel";
        } elseif ($state === 'scanned' && $waiting === 'imported') {
            $text = $scope === 'ads'
                ? '✅ اولین گزارش معتبر پنل Ads ثبت شد. برای دیدن کمپین‌های فعال /ads را بفرست.'
                : '✅ اولین اسکن معتبر با نشست جدید ثبت شد. برای دیدن رتبه‌ها /report را بفرست.';
        } else {
            return true;
        }
        if (!Sarmo_SnappPay_Runtime::notify_owner($text)) { return false; }
        update_option($option, $state === 'required' ? 'notified' : '', false);
        return true;
    }

    public static function routes(): void {
        register_rest_route('sarmo-monitor/v1', '/codespaces-session', [
            'methods' => 'POST',
            'callback' => [self::class, 'receive_codespace_session'],
            'permission_callback' => '__return_true', // The callback consumes an admin-issued one-time grant.
        ]);
        register_rest_route('sarmo-monitor/v1', '/codespaces-status', [
            'methods' => 'POST',
            'callback' => static function (WP_REST_Request $request) {
                $state = $request->get_param('state');
                if (self::codespace_name() === '' || !in_array($state, ['required', 'scanned'], true)) {
                    return new WP_Error('bad_state', 'Invalid login state', ['status' => 400]);
                }
                if (!self::codespace_status($state)) {
                    return new WP_Error('bale_delivery_failed', 'Bale did not accept the notice', ['status' => 502]);
                }
                return rest_ensure_response(['ok' => true]);
            },
            'permission_callback' => static fn() => current_user_can('manage_sarmo_monitor'),
        ]);
        register_rest_route('sarmo-monitor/v1', '/collector-auth', [
            'methods' => 'GET',
            'callback' => [self::class, 'get_auth'],
            'permission_callback' => static fn() => current_user_can('manage_sarmo_monitor'),
        ]);
        register_rest_route('sarmo-monitor/v1', '/collector-auth', [
            'methods' => 'POST',
            'callback' => [self::class, 'post_auth'],
            'permission_callback' => static fn() => current_user_can('manage_sarmo_monitor'),
        ]);
    }

    private static function key(): string {
        return hash('sha256', wp_salt('auth'), true);
    }

    private static function encrypt(array $state): string {
        if (!function_exists('openssl_encrypt')) { return ''; }
        $json = wp_json_encode($state);
        if (!is_string($json) || strlen($json) > 150000) { return ''; }
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($json, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return is_string($cipher) ? base64_encode($iv . $tag . $cipher) : '';
    }

    private static function decrypt(string $encoded): ?array {
        if ($encoded === '' || !function_exists('openssl_decrypt')) { return null; }
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 29) { return null; }
        $json = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key(),
            OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if (!is_string($json)) { return null; }
        $state = json_decode($json, true);
        return is_array($state) && isset($state['cookies']) && isset($state['origins']) ? $state : null;
    }

    public static function get_auth(): WP_REST_Response {
        $data = self::data();
        $rate_limited_until = self::provider_rate_limit_until($data);
        $rate_limited = $rate_limited_until > time() && !self::test_armed($data);
        $armed = !$rate_limited && !empty($data['login_armed_until']) && (int) $data['login_armed_until'] >= time();
        $response = new WP_REST_Response([
            'manual_browser_login' => self::codespace_name() !== '',
            'phone' => $armed ? (string) ($data['phone'] ?? '') : '',
            'login_armed' => $armed,
            'session' => self::decrypt((string) ($data['session'] ?? '')),
            'rate_limited_until' => $rate_limited ? $rate_limited_until : 0,
        ]);
        $response->header('Cache-Control', 'no-store, private');
        return $response;
    }

    private static function claim_login(): WP_REST_Response|WP_Error {
        if (self::codespace_name() !== '') {
            return new WP_Error('manual_login_only', 'Use the private login browser', ['status' => 403]);
        }
        $lock_at = (int) get_option(self::CLAIM_LOCK, 0);
        if ($lock_at && time() - $lock_at < 120) {
            return new WP_Error('login_claim_busy', 'A login attempt is already being claimed', ['status' => 409]);
        }
        if ($lock_at) { delete_option(self::CLAIM_LOCK); }
        if (!add_option(self::CLAIM_LOCK, time(), '', false)) {
            return new WP_Error('login_claim_busy', 'A login attempt is already being claimed', ['status' => 409]);
        }
        try {
            $data = self::data();
            $now = time();
            if (!self::test_armed($data) && self::provider_rate_limit_until($data) > $now) {
                return new WP_Error('snappay_rate_limited', 'SnappPay has rate-limited SMS login; wait before retrying', ['status' => 429]);
            }
            if (empty($data['phone']) || empty($data['login_armed_until']) || (int) $data['login_armed_until'] < $now) {
                return new WP_Error('login_not_armed', 'Send /login in the owner Bale chat before requesting OTP', ['status' => 403]);
            }
            if (!empty($data['challenge_at']) && $now - (int) $data['challenge_at'] <= self::CHALLENGE_TTL) {
                return new WP_Error('challenge_active', 'An OTP challenge is already active', ['status' => 409]);
            }
            if (!empty($data['last_login_attempt_at']) && $now - (int) $data['last_login_attempt_at'] < self::LOGIN_COOLDOWN) {
                return new WP_Error('login_cooldown', 'Wait before requesting another SnappPay login code', ['status' => 429]);
            }

            $data['test_challenge'] = self::test_armed($data);
            // Consume the one-time Bale authorization before the browser can request an SMS.
            unset($data['test_login'], $data['login_armed_until'], $data['otp'], $data['otp_at'], $data['otp_consumed_at']);
            $data['challenge_at'] = $now;
            $data['last_login_attempt_at'] = $now;
            self::save($data);
            if (!Sarmo_SnappPay_Runtime::notify_owner('یک تلاش ورود به collector رسید؛ شماره فقط یک‌بار ارسال می‌شود. فقط اگر پیامک همین تلاش به دستت رسید، کد را در همین گفتگو بفرست.')) {
                unset($data['test_challenge'], $data['challenge_at']);
                self::save($data);
                return new WP_Error('bale_delivery_failed', 'Could not notify the owner in Bale', ['status' => 502]);
            }
            return new WP_REST_Response(['ok' => true, 'phone' => (string) $data['phone']]);
        } finally {
            delete_option(self::CLAIM_LOCK);
        }
    }

    public static function post_auth(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $action = (string) $request->get_param('action');
        $data = self::data();
        if ($action === 'claim_login_attempt') {
            return self::claim_login();
        }
        if ($action === 'mark_rate_limited') {
            $data['rate_limited_until'] = max(
                (int) ($data['rate_limited_until'] ?? 0),
                time() + self::PROVIDER_RATE_LIMIT_COOLDOWN
            );
            unset($data['test_login'], $data['test_challenge'], $data['login_armed_until'], $data['otp'], $data['otp_at'], $data['challenge_at'], $data['otp_consumed_at']);
            self::save($data);
            return new WP_REST_Response(['ok' => true, 'retry_after' => $data['rate_limited_until']]);
        }
        if ($action === 'take_otp') {
            $code = '';
            if (!empty($data['challenge_at']) && time() - (int) $data['challenge_at'] <= self::CHALLENGE_TTL &&
                !empty($data['otp_at']) && time() - (int) $data['otp_at'] <= self::CHALLENGE_TTL) {
                $code = (string) ($data['otp'] ?? '');
            }
            if ($code !== '') {
                unset($data['test_challenge'], $data['otp'], $data['otp_at'], $data['challenge_at']);
                $data['otp_consumed_at'] = time();
                self::save($data);
            }
            return new WP_REST_Response(['code' => $code]);
        }
        if ($action === 'abort_challenge') {
            unset($data['test_challenge'], $data['otp'], $data['otp_at'], $data['challenge_at'], $data['otp_consumed_at']);
            self::save($data);
            return new WP_REST_Response(['ok' => true]);
        }
        if ($action === 'save_session') {
            if (empty($data['otp_consumed_at']) || time() - (int) $data['otp_consumed_at'] > self::CHALLENGE_TTL) {
                return new WP_Error('otp_not_consumed', 'A fresh OTP challenge is required before saving a session', ['status' => 403]);
            }
            $state = $request->get_param('session');
            $encrypted = is_array($state) ? self::encrypt($state) : '';
            if ($encrypted === '') {
                return new WP_Error('bad_session', 'Session could not be saved', ['status' => 400]);
            }
            $data['session'] = $encrypted;
            $data['last_auth_success_at'] = time();
            unset($data['test_challenge'], $data['otp'], $data['otp_at'], $data['challenge_at'], $data['otp_consumed_at'], $data['login_armed_until']);
            self::save($data);
            Sarmo_SnappPay_Runtime::notify_owner('✅ ورود اسنپ‌پی تأیید شد و نشست ذخیره شد. پایش می‌تواند ادامه یابد.');
            return new WP_REST_Response(['ok' => true]);
        }
        if ($action === 'clear_session') {
            unset($data['session'], $data['otp'], $data['otp_at'], $data['challenge_at'], $data['otp_consumed_at'], $data['login_armed_until']);
            self::save($data);
            return new WP_REST_Response(['ok' => true]);
        }
        return new WP_Error('bad_action', 'Unknown action', ['status' => 400]);
    }
}

Sarmo_SnappPay_Auth::init();
