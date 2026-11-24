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
function wp_json_encode($value, int $flags = 0): string { return json_encode($value, $flags) ?: ''; }
function get_option(string $name, $default = null) { return $GLOBALS['options'][$name] ?? $default; }
function update_option(string $name, $value, $autoload = null): bool { $GLOBALS['options'][$name] = $value; return true; }
function current_time(string $type, bool $gmt = false): string { return gmdate('Y-m-d H:i:s'); }
function wp_remote_post(string $url, array $args = []): array {
    $GLOBALS['sent_messages'][] = json_decode((string) ($args['body'] ?? ''), true);
    return ['code' => 200, 'body' => '{"ok":true}'];
}
function is_wp_error($value): bool { return false; }
function wp_remote_retrieve_body(array $response): string { return (string) ($response['body'] ?? ''); }
function wp_remote_retrieve_response_code(array $response): int { return (int) ($response['code'] ?? 0); }

final class WP_REST_Request {
    public function __construct(private array $params = []) {}
    public function get_param(string $name) { return $this->params[$name] ?? null; }
}
final class WP_REST_Response {
    public function __construct(public array $data = [], public int $status = 200) {}
}
final class FakeWpdb {
    public string $prefix = 'wp_';
    public array $inserted = [];
    public function insert(string $table, array $row, array $formats = []): int {
        $this->inserted[] = [$table, $row];
        return 1;
    }
}

$GLOBALS['options'] = [
    'sarmo_monitor_bale_settings' => ['token' => 'test-token', 'owner_chat_id' => '42'],
    'sarmo_monitor_source_health' => [],
];
$GLOBALS['sent_messages'] = [];
$GLOBALS['wpdb'] = new FakeWpdb();
require_once __DIR__ . '/../wordpress-plugin/sarmo-snappay-runtime.php';

$warning = 'SnappPay OTP rate limit (HTTP 429); retries blocked for 12 hours';
foreach (['روسری', 'عبا', 'مانتو'] as $query) {
    Sarmo_SnappPay_Runtime::record([
        'query' => $query,
        'observed_at' => gmdate('Y-m-d H:i:s'),
        'source_valid' => false,
        'source_warning' => $warning,
        'grid_rank' => null,
        'ad_rank' => null,
        'absolute_ad_slot' => null,
        'merchant_rank' => null,
    ], new WP_REST_Request(['details' => [], 'scanned_products' => 0]));
}

if (count($GLOBALS['sent_messages']) !== 1) {
    fwrite(STDERR, 'The same provider rate-limit error was not deduplicated across keywords\n'); exit(1);
}
$message = (string) ($GLOBALS['sent_messages'][0]['text'] ?? '');
foreach (['۴۲۹', 'OTP ارسال نشده', '/login_status', 'افت رتبه اعلام نمی‌شود'] as $required) {
    if (!str_contains($message, $required)) {
        fwrite(STDERR, "Bale health notice is missing: $required\n$message\n"); exit(1);
    }
}
if (count($GLOBALS['wpdb']->inserted) !== 3) {
    fwrite(STDERR, 'Invalid source observations were not retained for all keywords\n'); exit(1);
}

// A later 429 incident is reported again even when that keyword was already failed.
$GLOBALS['options']['sarmo_monitor_rate_alert_at'] = time() - 3600;
Sarmo_SnappPay_Runtime::record([
    'query' => 'روسری',
    'observed_at' => gmdate('Y-m-d H:i:s'),
    'source_valid' => false,
    'source_warning' => $warning,
    'grid_rank' => null,
    'ad_rank' => null,
    'absolute_ad_slot' => null,
    'merchant_rank' => null,
], new WP_REST_Request(['details' => [], 'scanned_products' => 0]));
if (count($GLOBALS['sent_messages']) !== 2) {
    fwrite(STDERR, 'A later provider rate-limit incident was incorrectly suppressed\n'); exit(1);
}

echo "Source health notification checks passed.\n";
