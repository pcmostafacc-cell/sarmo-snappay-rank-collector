<?php
if (!defined('ABSPATH')) { exit; }

final class Sarmo_SnappPay_Ads {
    private const SNAPSHOT = 'sarmo_monitor_ads_snapshot';
    private const PAGE_SIZE = 6;
    private const MAX_AGE = 1800;
    private const NUMBERS = [
        'bid_toman', 'actual_cpc_toman', 'daily_limit_toman', 'daily_spend_toman',
        'total_limit_toman', 'total_spend_toman', 'products', 'clicks', 'views',
    ];

    public static function init(): void {
        add_action('admin_post_sarmo_monitor_ads_import', [self::class, 'import']);
    }

    private static function data(): array {
        return (array) get_option(self::SNAPSHOT, []);
    }

    private static function normalize(string $name): string {
        $name = strtr($name, [
            'ي' => 'ی', 'ك' => 'ک', '۰'=>'0', '۱'=>'1', '۲'=>'2', '۳'=>'3', '۴'=>'4',
            '۵'=>'5', '۶'=>'6', '۷'=>'7', '۸'=>'8', '۹'=>'9',
        ]);
        return mb_substr(trim(preg_replace('/\s+/u', ' ', sanitize_text_field($name)) ?? ''), 0, 100);
    }

    private static function amount($value): ?int {
        return is_int($value) && $value >= 0 && $value <= 1000000000000 ? $value : null;
    }

    public static function snapshot(array $input, array $keywords): ?array {
        $stamp = $input['observed_at'] ?? '';
        if (!is_string($stamp) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $stamp)) { return null; }
        $time = strtotime($stamp);
        if ($time === false || $time > time() + 120 || gmdate('Y-m-d\TH:i:s\Z', $time) !== $stamp) { return null; }
        $items = $input['campaigns'] ?? null;
        if (!is_array($items) || count($items) > 250 || ($input['complete'] ?? null) !== true) { return null; }
        $rows = [];
        foreach ($items as $item) {
            if (!is_array($item) || !is_string($item['id'] ?? null) || !is_string($item['name'] ?? null) ||
                !is_bool($item['active'] ?? null)) { return null; }
            $id = strtolower($item['id']);
            $name = self::normalize($item['name']);
            if (!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D', $id) ||
                $name === '' || isset($rows[$id])) { return null; }
            $row = ['id' => $id, 'name' => $name, 'active' => $item['active']];
            $keyword = is_string($item['keyword'] ?? null) ? self::normalize($item['keyword']) : '';
            $row['keyword'] = array_key_exists($keyword, $keywords) ? $keyword : '';
            $row['period'] = in_array($item['period'] ?? null, ['1h','6h','24h','7d','30d','lifetime'], true)
                ? $item['period'] : '';
            foreach (self::NUMBERS as $field) {
                $row[$field] = self::amount($item[$field] ?? null);
            }
            $rows[$id] = $row;
        }
        return ['observed_at' => $time, 'balance_toman' => self::amount($input['balance_toman'] ?? null), 'campaigns' => $rows];
    }

    public static function import(): void {
        if (!current_user_can('manage_options')) { wp_die('Access denied', '', ['response' => 403]); }
        check_admin_referer('sarmo_monitor_ads_import');
        $raw = wp_unslash($_POST['ads_snapshot'] ?? '');
        $input = is_string($raw) && strlen($raw) <= 180000 ? json_decode($raw, true) : null;
        $snapshot = is_array($input) ? self::snapshot($input, (array) get_option('sarmo_snappay_keywords', [])) : null;
        if (!$snapshot) { wp_die('ساختار گزارش Ads یا زمان برداشت نامعتبر است.', '', ['response' => 400]); }
        if ($snapshot['observed_at'] < (int) (self::data()['observed_at'] ?? 0)) {
            wp_die('این گزارش از نسخهٔ ثبت‌شده قدیمی‌تر است.', '', ['response' => 409]);
        }
        update_option(self::SNAPSHOT, $snapshot, false);
        wp_safe_redirect(admin_url('tools.php?page=sarmo-snappay-monitor'));
        exit;
    }

    public static function admin_panel(): void {
        echo '<h2>گزارش کمپین‌های اسنپ‌پی Ads</h2>';
        echo '<p>' . esc_html(self::source_status()) . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="sarmo_monitor_ads_import">';
        wp_nonce_field('sarmo_monitor_ads_import');
        echo '<p><label for="sarmo-ads-snapshot">گزارش کامل کمپین‌ها (JSON)</label></p>';
        echo '<textarea id="sarmo-ads-snapshot" name="ads_snapshot" rows="3" cols="72" required></textarea>';
        echo '<p><button class="button">ثبت گزارش Ads</button></p></form>';
    }

    private static function money(?int $value): string {
        return $value === null ? 'نامشخص' : number_format($value) . ' تومان';
    }

    private static function remaining(array $row, string $kind): string {
        $limit = $row[$kind . '_limit_toman'];
        $spend = $row[$kind . '_spend_toman'];
        return self::money($limit === null || $spend === null ? null : max(0, $limit - $spend));
    }

    public static function source_status(): string {
        $time = (int) (self::data()['observed_at'] ?? 0);
        if (!$time) { return 'دادهٔ Ads هنوز وارد نشده است.'; }
        $stamp = wp_date('Y/m/d H:i', $time, new DateTimeZone('Asia/Tehran'));
        return 'آخرین برداشت Ads: ' . $stamp . ' تهران' . (time() - $time > self::MAX_AGE ? ' (قدیمی)' : '');
    }

    public static function active_list(string $argument): string {
        $data = self::data();
        if (empty($data['observed_at'])) { return self::source_status(); }
        if ($argument !== '' && !preg_match('/^[1-9][0-9]{0,2}$/D', $argument)) { return 'قالب: /ads [شماره صفحه]'; }
        $page = max(1, (int) $argument);
        $items = array_values(array_filter($data['campaigns'], static fn($row) => $row['active']));
        $pages = max(1, (int) ceil(count($items) / self::PAGE_SIZE));
        if ($page > $pages) { return "آخرین صفحه: $pages"; }
        $lines = ['کمپین‌های فعال در آخرین برداشت (' . count($items) . ')', self::source_status(),
            'موجودی حساب: ' . self::money($data['balance_toman'])];
        foreach (array_slice($items, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE) as $row) {
            $lines[] = $row['name'] . ' | قیمت پیشنهادی ' . self::money($row['bid_toman']) .
                ' | ماندهٔ امروز ' . self::remaining($row, 'daily');
        }
        $lines[] = "صفحهٔ $page از $pages | جزئیات: /ad نام کمپین";
        return implode("\n", $lines);
    }

    private static function find(string $reference): ?array {
        $name = self::normalize($reference);
        if ($name === '') { return null; }
        $rows = self::data()['campaigns'] ?? [];
        if (isset($rows[strtolower($name)])) { return $rows[strtolower($name)]; }
        $matches = array_values(array_filter($rows, static fn($row) => $row['name'] === $name));
        return count($matches) === 1 ? $matches[0] : null;
    }

    public static function detail(string $reference, callable $rank): string {
        $row = self::find($reference);
        if (!$row) { return self::source_status() . "\nکمپین یکتا پیدا نشد. /ads یا /ad شناسهٔ کمپین"; }
        $lines = [$row['name'] . ($row['active'] ? ' | فعال' : ' | غیرفعال'), self::source_status(),
            'قیمت پیشنهادی: ' . self::money($row['bid_toman']),
            'امروز: ' . self::money($row['daily_spend_toman']) . ' از ' . self::money($row['daily_limit_toman']) . ' | مانده ' . self::remaining($row, 'daily'),
            'کل: ' . self::money($row['total_spend_toman']) . ' از ' . self::money($row['total_limit_toman']) . ' | مانده ' . self::remaining($row, 'total'),
        ];
        $ctr = $row['views'] > 0 && $row['clicks'] !== null ? number_format($row['clicks'] / $row['views'] * 100, 2) . '٪' : 'نامشخص';
        $lines[] = 'عملکرد (' . ($row['period'] ?: 'بازه نامشخص') . '): ' .
            ($row['clicks'] ?? 'نامشخص') . ' کلیک | ' . ($row['views'] ?? 'نامشخص') . ' بازدید | CTR ' . $ctr;
        $lines[] = 'میانگین هزینهٔ پرداخت‌شده در این بازه: ' . self::money($row['actual_cpc_toman']);
        $lines[] = $row['keyword'] === '' ? 'کلیدواژهٔ مرتبط هنوز ثبت نشده است.' : $rank($row['keyword']);
        $lines[] = 'https://ads.snapppay.ir/campaign/' . $row['id'];
        return implode("\n", $lines);
    }

    public static function bid_preview(string $argument): string {
        if (!preg_match('/^(.*?)\s+([1-9][0-9]{0,9})$/D', $argument, $parts)) { return 'قالب: /bid_preview نام کمپین مبلغ به تومان'; }
        $row = self::find($parts[1]);
        if (!$row || !$row['active'] || $row['bid_toman'] === null) { return 'کمپین فعال یکتا یا قیمت فعلی آن پیدا نشد.'; }
        if (time() - (int) (self::data()['observed_at'] ?? 0) > self::MAX_AGE) {
            return self::source_status() . "\nبرای پیش‌نمایش، گزارش تازهٔ Ads لازم است.";
        }
        return 'پیش‌نمایش «' . $row['name'] . '»' . "\n" .
            'قیمت فعلی ' . self::money($row['bid_toman']) . ' ← قیمت درخواستی ' . self::money((int) $parts[2]) . "\n" .
            'ماندهٔ امروز ' . self::remaining($row, 'daily') . ' | ماندهٔ کل ' . self::remaining($row, 'total') . "\n" .
            'این فرمان پیش‌نمایش است. اعمال قیمت در پنل هنوز متصل نیست.';
    }
}
