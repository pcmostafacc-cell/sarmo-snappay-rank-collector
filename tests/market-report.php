<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');

if (!function_exists('mb_substr')) {
    function mb_substr(string $value, int $start, ?int $length = null): string {
        return $length === null ? substr($value, $start) : substr($value, $start, $length);
    }
}

function sanitize_text_field(string $value): string { return trim(strip_tags($value)); }
function absint($value): int { return abs((int) $value); }
function get_option(string $name, $default = null) { return $GLOBALS['options'][$name] ?? $default; }
function update_option(string $name, $value, $autoload = null): bool {
    $GLOBALS['options'][$name] = $value;
    return true;
}
function wp_json_encode($value, int $flags = 0): string { return json_encode($value, $flags) ?: ''; }
function wp_remote_post(string $url, array $args = []): array {
    $GLOBALS['sent_messages'][] = json_decode((string) ($args['body'] ?? ''), true);
    return ['code' => 200, 'body' => '{"ok":true}'];
}
function is_wp_error($value): bool { return false; }
function wp_remote_retrieve_body(array $response): string { return (string) ($response['body'] ?? ''); }
function wp_remote_retrieve_response_code(array $response): int { return (int) ($response['code'] ?? 0); }

final class WP_REST_Request {
    public function __construct(private array $payload) {}
    public function get_json_params(): array { return $this->payload; }
}

final class WP_REST_Response {
    public function __construct(public array $data = [], public int $status = 200) {}
}

final class FakeWpdb {
    public array $row = [];
    public function prepare(string $query, ...$args): string { return $query; }
    public function get_row(string $query, $output): array { return $this->row; }
}

$GLOBALS['options'] = [
    'sarmo_monitor_bale_settings' => ['token' => 'test-token', 'owner_chat_id' => '42'],
    'sarmo_monitor_bale_last_update_id' => 0,
];
$GLOBALS['sent_messages'] = [];
$GLOBALS['wpdb'] = new FakeWpdb();
$GLOBALS['wpdb']->row = [
    'query' => 'روسری',
    'observed_at' => '2026-11-25 07:00:00',
    'scanned_products' => 3,
    'details' => json_encode([
        'scan_complete' => true,
        'own_products' => [[
            'rank' => 1, 'merchant' => 'سارمو استایل', 'title' => 'روسری سارمو',
            'isAd' => true, 'price' => '۱٬۲۰۰٬۰۰۰', 'rating' => '۴٫۸',
            'reviewCount' => '۲۱', 'discount' => '۱۰٪',
        ]],
        'top_competitors' => [[
            'rank' => 2, 'merchant' => 'فروشگاه رقیب', 'title' => 'روسری رقیب',
            'isAd' => true, 'price' => '۱٬۰۰۰٬۰۰۰', 'rating' => '۴٫۴',
            'reviewCount' => '۱۲', 'discount' => '۵٪',
        ]],
    ], JSON_UNESCAPED_UNICODE),
];

require_once __DIR__ . '/../wordpress-plugin/sarmo-snappay-runtime.php';

$response = Sarmo_SnappPay_Runtime::webhook(new WP_REST_Request([
    'update_id' => 1,
    'message' => ['chat' => ['id' => '42'], 'text' => '/competitors روسری'],
]));
$report = (string) ($GLOBALS['sent_messages'][0]['text'] ?? '');
$mustContain = [
    'محصولات سارمو:', 'روسری سارمو', 'قیمت کالا ۱٬۲۰۰٬۰۰۰', 'امتیاز/نمرهٔ کارت ۴٫۸',
    'رقبا:', 'روسری رقیب', 'قیمت کالا ۱٬۰۰۰٬۰۰۰', 'زمان تهران:',
    'قیمت کارت، قیمت خود محصول است', 'هزینهٔ تبلیغ یا کلیک', 'پنل Search Ads وصل شود',
];
foreach ($mustContain as $expected) {
    if (!str_contains($report, $expected)) {
        fwrite(STDERR, "Missing expected report text: $expected\n$report\n");
        exit(1);
    }
}
if ($response->status !== 200) {
    fwrite(STDERR, "Webhook did not return HTTP 200\n");
    exit(1);
}

$GLOBALS['wpdb']->row['details'] = json_encode([
    'scan_complete' => false,
    'own_products' => [],
    'top_competitors' => [[
        'rank' => 1, 'merchant' => 'فروشگاه رقیب', 'title' => 'کالای رقیب', 'isAd' => false,
    ]],
], JSON_UNESCAPED_UNICODE);
$GLOBALS['options']['sarmo_monitor_bale_last_update_id'] = 1;
$GLOBALS['sent_messages'] = [];
Sarmo_SnappPay_Runtime::webhook(new WP_REST_Request([
    'update_id' => 2,
    'message' => ['chat' => ['id' => '42'], 'text' => '/competitors روسری'],
]));
$partial_report = (string) ($GLOBALS['sent_messages'][0]['text'] ?? '');
if (!str_contains($partial_report, 'اسکن کامل نیست') || !str_contains($partial_report, 'اسکن کامل نشده است')) {
    fwrite(STDERR, "Incomplete scan was not clearly qualified\n$partial_report\n");
    exit(1);
}

echo "Market report checks passed.\n";
