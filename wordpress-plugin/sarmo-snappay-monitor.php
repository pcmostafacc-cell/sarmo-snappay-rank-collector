<?php
/**
 * Plugin Name: Sarmo SnappPay Monitor
 * Description: Keyword targets, verified rank observations, and Bale reports for Sarmo.
 * Version: 0.12.0
 * Requires PHP: 8.0
 * Text Domain: sarmo-snappay-monitor
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/sarmo-snappay-ads.php';
require_once __DIR__ . '/sarmo-snappay-bids.php';
require_once __DIR__ . '/sarmo-snappay-runtime.php';
require_once __DIR__ . '/sarmo-snappay-auth.php';

final class Sarmo_SnappPay_Monitor {
    private const KEYWORDS = 'sarmo_snappay_keywords';
    private const OBSERVATIONS = 'sarmo_snappay_observations';
    private const PAGE = 'sarmo-snappay-monitor';
    private const MAX_KEYWORDS = 10;

    public static function init(): void {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_sarmo_monitor_test_login', [self::class, 'test_login']);
        add_action('admin_post_sarmo_monitor_save_keyword', [self::class, 'save_keyword']);
        add_action('admin_post_sarmo_monitor_remove_keyword', [self::class, 'remove_keyword']);
        add_action('rest_api_init', [self::class, 'routes']);
        Sarmo_SnappPay_Runtime::init();
        Sarmo_SnappPay_Ads::init();
        add_action('rest_api_init', [Sarmo_SnappPay_Bids::class, 'routes']);
    }

    public static function activate(): void {
        if (get_option(self::KEYWORDS, null) === null) {
            add_option(self::KEYWORDS, [
                'روسری' => ['target' => 1, 'campaign' => true],
                'عبا' => ['target' => 1, 'campaign' => false],
                'مانتو' => ['target' => 1, 'campaign' => false],
            ], '', false);
        }
        if (get_option(self::OBSERVATIONS, null) === null) {
            add_option(self::OBSERVATIONS, [], '', false);
        }
        Sarmo_SnappPay_Runtime::activate();
    }

    public static function menu(): void {
        add_management_page('پایش اسنپ‌پی سارمو', 'پایش اسنپ‌پی', 'manage_options', self::PAGE, [self::class, 'page']);
    }

    private static function keyword(string $value): string {
        $value = str_replace(['ي', 'ك'], ['ی', 'ک'], sanitize_text_field($value));
        $value = preg_replace('/\s+/u', ' ', trim($value));
        return mb_substr($value ?? '', 0, 100);
    }

    private static function redirect(): void {
        wp_safe_redirect(admin_url('tools.php?page=' . self::PAGE));
        exit;
    }

    public static function save_keyword(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Access denied', '', ['response' => 403]);
        }
        check_admin_referer('sarmo_monitor_keyword');
        $query = self::keyword((string) wp_unslash($_POST['query'] ?? ''));
        if ($query === '') {
            self::redirect();
        }
        $keywords = (array) get_option(self::KEYWORDS, []);
        if (!isset($keywords[$query]) && count($keywords) >= self::MAX_KEYWORDS) {
            wp_die('برای کنترل مصرف، حداکثر ' . self::MAX_KEYWORDS . ' کلیدواژه می‌توان داشت.', '', ['response' => 400]);
        }
        $keywords[$query] = [
            'target' => min(100, max(1, (int) ($_POST['target'] ?? 1))),
            'campaign' => !empty($_POST['campaign']),
        ];
        update_option(self::KEYWORDS, $keywords, false);
        Sarmo_SnappPay_Runtime::reset_alert($query);
        self::redirect();
    }

    public static function remove_keyword(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Access denied', '', ['response' => 403]);
        }
        check_admin_referer('sarmo_monitor_remove');
        $query = self::keyword((string) wp_unslash($_POST['query'] ?? ''));
        $keywords = (array) get_option(self::KEYWORDS, []);
        unset($keywords[$query]);
        update_option(self::KEYWORDS, $keywords, false);
        Sarmo_SnappPay_Runtime::reset_alert($query);
        self::redirect();
    }

    public static function routes(): void {
        register_rest_route('sarmo-monitor/v1', '/collector-status', [
            'methods' => 'GET',
            'callback' => static function () {
                return rest_ensure_response([
                    'api_version' => '0.7.0',
                    'plugin_version' => '0.12.0',
                    'persistent_login' => true,
                    'max_keywords' => self::MAX_KEYWORDS,
                    'rank_fields' => ['grid_rank', 'ad_rank', 'absolute_ad_slot', 'merchant_rank'],
                    'otp_mode' => 'owner-armed-rate-limit-aware-one-time',
                ]);
            },
            'permission_callback' => static function () {
                return current_user_can('manage_sarmo_monitor');
            },
        ]);
        register_rest_route('sarmo-monitor/v1', '/keywords', [
            'methods' => 'GET',
            'callback' => static function () {
                return rest_ensure_response((array) get_option(self::KEYWORDS, []));
            },
            'permission_callback' => static function () {
                return current_user_can('manage_sarmo_monitor');
            },
        ]);
        register_rest_route('sarmo-monitor/v1', '/persistent-login', [
            'methods' => 'POST',
            'callback' => [Sarmo_SnappPay_Runtime::class, 'persistent_login'],
            'permission_callback' => static fn() => current_user_can('manage_sarmo_monitor'),
        ]);
        register_rest_route('sarmo-monitor/v1', '/observations', [
            'methods' => 'POST',
            'callback' => [self::class, 'record_observation'],
            'permission_callback' => static function () {
                return current_user_can('manage_sarmo_monitor');
            },
        ]);
    }

    public static function record_observation(WP_REST_Request $request) {
        $query = self::keyword((string) $request->get_param('query'));
        $keywords = (array) get_option(self::KEYWORDS, []);
        if ($query === '' || !array_key_exists($query, $keywords)) {
            return new WP_Error('unknown_keyword', 'Keyword is not monitored', ['status' => 400]);
        }
        $source_valid = $request->get_param('source_valid') === true;
        $seller = self::keyword((string) $request->get_param('seller'));
        $rank = $request->get_param('grid_rank');
        $rank = is_numeric($rank) && (int) $rank > 0 ? (int) $rank : null;
        $warning = sanitize_text_field((string) $request->get_param('source_warning'));
        if ($warning !== '') { $source_valid = false; }
        $row = [
            'query' => $query,
            'observed_at' => current_time('mysql', true),
            'source_valid' => $source_valid,
            'seller' => $seller,
            'grid_rank' => $source_valid && $seller === 'سارمو استایل' ? $rank : null,
            'ad_rank' => $source_valid && $seller === 'سارمو استایل' && (int) $request->get_param('ad_rank') > 0 ? (int) $request->get_param('ad_rank') : null,
            'absolute_ad_slot' => $source_valid && $seller === 'سارمو استایل' && (int) $request->get_param('absolute_ad_slot') > 0
                ? (int) $request->get_param('absolute_ad_slot') : null,
            'merchant_rank' => $source_valid && (int) $request->get_param('merchant_rank') > 0 ? (int) $request->get_param('merchant_rank') : null,
            'source_warning' => mb_substr($warning, 0, 255),
        ];
        $rows = (array) get_option(self::OBSERVATIONS, []);
        array_unshift($rows, $row);
        update_option(self::OBSERVATIONS, array_slice($rows, 0, 500), false);
        Sarmo_SnappPay_Runtime::record($row, $request);
        return rest_ensure_response($row);
    }

    public static function test_login(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Access denied', '', ['response' => 403]);
        }
        check_admin_referer('sarmo_monitor_test_login');
        $status = Sarmo_SnappPay_Auth::arm_test() ? 'armed' : 'unavailable';
        wp_safe_redirect(add_query_arg('test_login', $status, admin_url('tools.php?page=' . self::PAGE)));
        exit;
    }

    public static function page(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Access denied', '', ['response' => 403]);
        }
        $keywords = (array) get_option(self::KEYWORDS, []);
        $rows = (array) get_option(self::OBSERVATIONS, []);
        echo '<div class="wrap"><h1>پایش جایگاه اسنپ‌پی</h1>';
        echo '<p>رتبه فقط از مشاهدهٔ معتبر ثبت می‌شود. «جایگاه مطلق آگهی» شمارهٔ کارت در کل نتایج و «رتبه بین آگهی‌ها» شمارهٔ آن میان کارت‌های تبلیغاتی است.</p>';
        echo '<p>' . esc_html(Sarmo_SnappPay_Auth::status()) . '</p>';
        if (($_GET['test_login'] ?? '') === 'unavailable') {
            echo '<p>تلاش آزمایشی ثبت نشد. شمارهٔ قبلی یا وضعیت ورود را بررسی کنید.</p>';
        }
        if (!Sarmo_SnappPay_Auth::manual_browser_ready()) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="sarmo_monitor_test_login">';
            wp_nonce_field('sarmo_monitor_test_login');
            echo '<p><button class="button">اجازهٔ یک تلاش آزمایشی ورود با شمارهٔ ثبت‌شده</button></p></form>';
        }
        $scope = isset($_GET['login_source']) && $_GET['login_source'] === 'ads' ? 'ads' :
            (isset($_GET['login_source']) && $_GET['login_source'] === 'market' ? 'market' : '');
        $codespace = Sarmo_SnappPay_Auth::codespace_name();
        $admin_post = esc_url(admin_url('admin-post.php'));
        echo '<h2>ورود دستی با GitHub Codespaces</h2>';
        echo '<form method="post" action="' . $admin_post . '">';
        echo '<input type="hidden" name="action" value="sarmo_monitor_codespace">';
        wp_nonce_field('sarmo_monitor_codespace');
        echo '<label>نام Codespace <input name="codespace_name" value="' . esc_attr($codespace) . '"></label> ';
        echo '<button class="button">ذخیرهٔ نام</button></form>';
        if ($codespace !== '') {
            echo '<form method="post" action="' . $admin_post . '">';
            echo '<input type="hidden" name="action" value="sarmo_monitor_codespace">';
            wp_nonce_field('sarmo_monitor_codespace');
            echo '<p><a href="' . esc_url('https://github.com/codespaces/' . $codespace) . '">روشن‌کردن Codespace در GitHub</a></p>';
            if ($scope !== 'ads') {
                echo '<button class="button button-primary" name="login_scope" value="market">ورود به بازار اسنپ‌پی</button> ';
            }
            if ($scope !== 'market') {
                echo '<button class="button button-primary" name="login_scope" value="ads">ورود به پنل Ads</button>';
            }
            echo '</form>';
        }
        echo '<h2>ورود دستی با مرورگر همین دستگاه</h2>';
        echo '<p>پس از شروع برنامهٔ ورود محلی سارمو، در پنجرهٔ رسمی اسنپ‌پی وارد شوید. پس از انتقال نشست می‌توانید برنامه را ببندید.</p>';
        echo '<form method="post" action="' . $admin_post . '">';
        echo '<input type="hidden" name="action" value="sarmo_monitor_codespace"><input type="hidden" name="login_transport" value="local">';
        wp_nonce_field('sarmo_monitor_codespace');
        if ($scope !== 'ads') {
            echo '<button class="button' . ($codespace === '' ? ' button-primary' : '') . '" name="login_scope" value="market">ورود محلی به بازار اسنپ‌پی</button> ';
        }
        if ($scope !== 'market') {
            echo '<button class="button' . ($codespace === '' ? ' button-primary' : '') . '" name="login_scope" value="ads">ورود محلی به پنل Ads</button>';
        }
        echo '</form>';
        Sarmo_SnappPay_Ads::admin_panel();
        if (isset($_POST['sarmo_probe'])) {
            check_admin_referer('sarmo_monitor_probe');
            $result = wp_safe_remote_get('https://app.snapppay.ir/search/result?referrer=MAIN', [
                'timeout' => 12,
                'redirection' => 0,
                'limit_response_size' => 2048,
            ]);
            if (is_wp_error($result)) {
                echo '<div class="notice notice-warning"><p>آزمون اتصال: ' . esc_html($result->get_error_message()) . '</p></div>';
            } else {
                $status = wp_remote_retrieve_response_code($result);
                $location = wp_remote_retrieve_header($result, 'location');
                echo '<div class="notice notice-info"><p>پاسخ سرور اسنپ‌پی: HTTP ' . esc_html((string) $status);
                if ($location) {
                    echo '؛ مقصد تغییر مسیر: ' . esc_html((string) $location);
                }
                echo '. این آزمون به‌تنهایی دسترسی به نتایج محصول را ثابت نمی‌کند.</p></div>';
            }
        }
        echo '<h2>آزمون منبع داده</h2><form method="post">';
        wp_nonce_field('sarmo_monitor_probe');
        echo '<button class="button" name="sarmo_probe" value="1">تست اتصال سرور به جست‌وجوی اسنپ‌پی</button></form>';
        echo '<h2>کلیدواژه‌ها</h2><table class="widefat striped"><thead><tr><th>واژه</th><th>حداکثر جایگاه مطلق آگهی</th><th>کمپین</th><th>آخرین مشاهدهٔ معتبر</th><th>آخرین اسکن</th><th></th></tr></thead><tbody>';
        foreach ($keywords as $query => $rule) {
            $latest = null;
            $last_attempt = null;
            foreach ($rows as $row) {
                if (($row['query'] ?? '') === $query && $last_attempt === null) {
                    $last_attempt = $row;
                }
                if (($row['query'] ?? '') === $query && !empty($row['source_valid'])) {
                    $latest = $row;
                    break;
                }
            }
            echo '<tr><td>' . esc_html($query) . '</td><td>' . esc_html((string) ($rule['target'] ?? 1)) . '</td><td>' . (!empty($rule['campaign']) ? 'بله' : 'خیر') . '</td><td>';
            if ($latest) {
                $grid = ($latest['grid_rank'] ?? null) === null ? 'محصول در اسکن کامل یافت نشد' : 'گرید ' . $latest['grid_rank'];
                $adSlot = ($latest['absolute_ad_slot'] ?? null) === null ? 'جایگاه مطلق آگهی نامشخص' : 'جایگاه مطلق آگهی ' . $latest['absolute_ad_slot'];
                $adRank = ($latest['ad_rank'] ?? null) === null ? 'رتبه بین آگهی‌ها نامشخص' : 'رتبه بین آگهی‌ها ' . $latest['ad_rank'];
                echo esc_html($grid . ' — ' . $adSlot . ' — ' . $adRank . ' — ' . $latest['observed_at']);
            } else {
                echo 'بدون داده';
            }
            echo '</td><td>';
            if ($last_attempt) {
                $attempt_status = !empty($last_attempt['source_valid']) ? 'معتبر' : 'نامعتبر';
                $attempt_warning = trim((string) ($last_attempt['source_warning'] ?? ''));
                echo esc_html((string) $last_attempt['observed_at'] . ' | ' . $attempt_status);
                if ($attempt_warning !== '') { echo '<br>' . esc_html($attempt_warning); }
            } else {
                echo 'هنوز اسکن نشده';
            }
            echo '</td><td><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="sarmo_monitor_remove_keyword"><input type="hidden" name="query" value="' . esc_attr($query) . '">';
            wp_nonce_field('sarmo_monitor_remove');
            echo '<button class="button">حذف از پایش</button></form></td></tr>';
        }
        echo '</tbody></table><h2>افزودن یا ویرایش واژه</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="sarmo_monitor_save_keyword">';
        wp_nonce_field('sarmo_monitor_keyword');
        echo '<p><label>کلیدواژه <input name="query" required maxlength="100"></label> <label>حداکثر جایگاه مطلق آگهی <input name="target" type="number" min="1" max="100" value="1"></label> <label><input name="campaign" type="checkbox" value="1"> هشدار کمپین فعال</label></p>';
        echo '<p><button class="button button-primary">ذخیره</button></p></form>';
        echo '<h2>وضعیت اتصال</h2><p>جمع‌آورنده فقط به دسترسی <code>manage_sarmo_monitor</code> نیاز دارد. افزونه نقش محدود «Sarmo Rank Collector» را می‌سازد؛ برای GitHub Actions کاربر جداگانه با همین نقش و Application Password بسازید. حد پایش برای کنترل مصرف ۱۰ کلیدواژه است.</p>';
        echo '<p>در بله با <code>/bid_preview</code> قیمت پیشنهادی را پیش‌نمایش بگیرید. ثبت سفارش تغییر قیمت به سقف <code>/bid_cap</code> و تأیید جداگانهٔ هر مبلغ نیاز دارد.</p></div>';
    }
}

register_activation_hook(__FILE__, [Sarmo_SnappPay_Monitor::class, 'activate']);
Sarmo_SnappPay_Monitor::init();
