<?php
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');
function sanitize_text_field(string $value): string { return trim(strip_tags($value)); }
function get_option(string $key, $default = null) { return $GLOBALS['options'][$key] ?? $default; }
function update_option(string $key, $value, bool $autoload): bool { $GLOBALS['options'][$key] = $value; return true; }
function wp_date(string $format, int $time, DateTimeZone $zone): string {
    return (new DateTimeImmutable('@' . $time))->setTimezone($zone)->format($format);
}
require_once __DIR__ . '/../wordpress-plugin/sarmo-snappay-ads.php';

$id = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
$input = [
    'observed_at' => gmdate('Y-m-d\TH:i:s\Z'),
    'complete' => true,
    'balance_toman' => 1000,
    'campaigns' => [[
        'id' => $id, 'name' => 'نمونه', 'active' => true, 'keyword' => 'عبا',
        'bid_toman' => 10000, 'actual_cpc_toman' => 9500, 'period' => 'lifetime',
        'daily_limit_toman' => 5000000, 'daily_spend_toman' => 20000,
        'total_limit_toman' => 50000000, 'total_spend_toman' => 480000,
        'clicks' => 48, 'views' => 3057,
    ]],
];
$data = Sarmo_SnappPay_Ads::snapshot($input, ['عبا' => []]);
if (!$data) { throw new RuntimeException('Valid complete snapshot rejected.'); }
$GLOBALS['options']['sarmo_monitor_ads_snapshot'] = $data;
$GLOBALS['options']['sarmo_snappay_keywords'] = ['عبا' => [], 'مانتو' => []];
$detail = Sarmo_SnappPay_Ads::detail($id, static fn($keyword) => "رتبهٔ $keyword");
foreach (['4,980,000 تومان', '49,520,000 تومان', '9,500 تومان', 'CTR 1.57٪', 'رتبهٔ عبا'] as $expected) {
    if (!str_contains($detail, $expected)) { throw new RuntimeException('Incomplete report: ' . $expected); }
}
if (!str_contains(Sarmo_SnappPay_Ads::bid_preview('نمونه 9000'), '9,000 تومان')) {
    throw new RuntimeException('Active fresh campaign did not produce a preview.');
}
$GLOBALS['options']['sarmo_monitor_ads_snapshot']['observed_at'] -= 3600;
if (!str_contains(Sarmo_SnappPay_Ads::bid_preview('نمونه 9000'), 'گزارش تازه')) {
    throw new RuntimeException('Stale campaign data must block bid previews.');
}
$input['complete'] = false;
if (Sarmo_SnappPay_Ads::snapshot($input, []) !== null) {
    throw new RuntimeException('A partial import must not replace the active campaign list.');
}
if (!str_contains(Sarmo_SnappPay_Ads::link_keyword('نمونه | مانتو', $GLOBALS['options']['sarmo_snappay_keywords']), 'وصل شد') ||
    !str_contains(Sarmo_SnappPay_Ads::detail($id, static fn($keyword) => "رتبهٔ $keyword"), 'رتبهٔ مانتو')) {
    throw new RuntimeException('An explicit campaign mapping must be used over the import default.');
}
echo "Ads snapshot checks passed.\n";
