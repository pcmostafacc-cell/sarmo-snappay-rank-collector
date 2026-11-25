<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

final class WP_REST_Response {
    public function __construct(public array $data = [], public int $status = 200) {}
    public function header(string $name, string $value): void {}
}
final class WP_Error {
    public function __construct(public string $code, public string $message = '', public array $data = []) {}
}
final class WP_REST_Request {
    public function __construct(private array $params = []) {}
    public function get_param(string $name) { return $this->params[$name] ?? null; }
    public function get_body(): string { return json_encode($this->params); }
}
final class FakeWpdb {
    public string $prefix = 'wp_';
    public string|false $observed_at = false;
    public function prepare(string $query, ...$args): string { return $query; }
    public function get_var(string $query) { return $this->observed_at; }
}

function add_action(...$args): void {}
function register_rest_route(...$args): void {}
function get_option(string $key, $default = null) { return $GLOBALS['options'][$key] ?? $default; }
function update_option(string $key, $value, $autoload = null): bool { $GLOBALS['options'][$key] = $value; return true; }
function add_option(string $key, $value, ...$args): bool {
    if (array_key_exists($key, $GLOBALS['options'])) { return false; }
    $GLOBALS['options'][$key] = $value;
    return true;
}
function delete_option(string $key): bool { unset($GLOBALS['options'][$key]); return true; }
function wp_salt(string $scheme): string { return 'test-only-salt'; }
function wp_json_encode($value): string { return json_encode($value); }
function current_user_can(string $capability): bool { return true; }
function wp_date(string $format, ?int $timestamp = null, ?DateTimeZone $timezone = null): string {
    return (new DateTimeImmutable('@' . ($timestamp ?? time())))->setTimezone($timezone ?? new DateTimeZone('UTC'))->format($format);
}

$GLOBALS['options'] = [];
$GLOBALS['wpdb'] = new FakeWpdb();
$testPhone = '09' . str_repeat('1', 9);
require_once __DIR__ . '/../wordpress-plugin/sarmo-snappay-auth.php';

// A provider lock refuses another /login but preserves an existing session.
$GLOBALS['options']['sarmo_monitor_snappay_auth'] = [
    'rate_limited_until' => time() + 3600,
    'session' => 'encrypted-session-placeholder',
];
if (Sarmo_SnappPay_Auth::set_phone($testPhone) !== false) {
    fwrite(STDERR, "A login was armed during provider rate limit\n"); exit(1);
}
$state = $GLOBALS['options']['sarmo_monitor_snappay_auth'];
if (($state['session'] ?? '') !== 'encrypted-session-placeholder') {
    fwrite(STDERR, "Arming a blocked login discarded the saved session\n"); exit(1);
}
$GLOBALS['options']['sarmo_monitor_snappay_auth']['challenge_at'] = time();
if (Sarmo_SnappPay_Auth::receive_otp('123456') !== false) {
    fwrite(STDERR, "OTP was accepted while the provider lock was active\n"); exit(1);
}
if (!str_contains(Sarmo_SnappPay_Auth::status(), '۴۲۹')) {
    fwrite(STDERR, "Login status did not explain the provider 429 lock\n"); exit(1);
}

// Existing sanitized 429 observations reconstruct the lock after a plugin update.
$GLOBALS['options']['sarmo_monitor_snappay_auth'] = ['session' => 'preserved'];
$GLOBALS['wpdb']->observed_at = gmdate('Y-m-d H:i:s', time() - 60);
if (Sarmo_SnappPay_Auth::set_phone($testPhone) !== false) {
    fwrite(STDERR, "A recent 429 observation did not block a new login\n"); exit(1);
}
$state = $GLOBALS['options']['sarmo_monitor_snappay_auth'];
if ((int) ($state['rate_limited_until'] ?? 0) < time() + 11 * 3600) {
    fwrite(STDERR, "The reconstructed provider lock did not persist for 12 hours\n"); exit(1);
}
$auth = Sarmo_SnappPay_Auth::get_auth();
if ((int) ($auth->data['rate_limited_until'] ?? 0) < time() + 11 * 3600) {
    fwrite(STDERR, "The collector auth response did not include the reconstructed retry time\n"); exit(1);
}
if (($state['session'] ?? '') !== 'preserved') {
    fwrite(STDERR, "429 recovery discarded the saved session\n"); exit(1);
}

// An expired lock and old 429 observation allow one fresh owner-authorized attempt.
$GLOBALS['options']['sarmo_monitor_snappay_auth'] = ['rate_limited_until' => time() - 1];
$GLOBALS['wpdb']->observed_at = gmdate('Y-m-d H:i:s', time() - 13 * 3600);
if (Sarmo_SnappPay_Auth::set_phone($testPhone) !== true) {
    fwrite(STDERR, "A new login remained blocked after the provider cooldown expired\n"); exit(1);
}
$state = $GLOBALS['options']['sarmo_monitor_snappay_auth'];
if (empty($state['login_armed_until']) || empty($state['phone'])) {
    fwrite(STDERR, "A permitted login attempt was not armed\n"); exit(1);
}

echo "OTP guard checks passed.\n";

// The Codespace can write one scoped session with an expiring grant.
$grant = str_repeat('a', 64);
$hash = hash('sha256', $grant);
$key = 'sarmo_monitor_login_grant_' . $hash;
$GLOBALS['options']['sarmo_monitor_codespace_name'] = 'test-codespace';
$GLOBALS['options']['sarmo_monitor_codespace_grant'] = $hash;
$GLOBALS['options'][$key] = time() + 120;
$session = ['cookies' => [['domain' => 'app.snapppay.ir']], 'origins' => []];
$foreign = ['cookies' => [['domain' => 'example.test']], 'origins' => []];
$check = Sarmo_SnappPay_Auth::receive_codespace_session(new WP_REST_Request(['grant' => $grant, 'check' => true]));
if (!$check instanceof WP_REST_Response || !isset($GLOBALS['options'][$key])) {
    throw new RuntimeException('Checking the grant must not consume it.');
}
$rejected = Sarmo_SnappPay_Auth::receive_codespace_session(new WP_REST_Request(['grant' => $grant, 'session' => $foreign]));
if (!$rejected instanceof WP_Error || $rejected->code !== 'bad_session') {
    throw new RuntimeException('Foreign cookies must be rejected.');
}
$request = new WP_REST_Request(['grant' => $grant, 'session' => $session]);
$accepted = Sarmo_SnappPay_Auth::receive_codespace_session($request);
$replay = Sarmo_SnappPay_Auth::receive_codespace_session($request);
if (!$accepted instanceof WP_REST_Response || !$replay instanceof WP_Error || $replay->code !== 'bad_grant') {
    throw new RuntimeException('Session grants can be used once only.');
}
$auth = Sarmo_SnappPay_Auth::get_auth();
if ($auth->data['session'] !== $session || $auth->data['manual_browser_login'] !== true ||
    Sarmo_SnappPay_Auth::set_phone($testPhone)) {
    throw new RuntimeException('Manual mode must expose the saved session and prevent automatic login.');
}
echo "Codespace grant checks passed.\n";

$grant = str_repeat('b', 64);
$hash = hash('sha256', $grant);
$key = 'sarmo_monitor_login_grant_' . $hash;
$GLOBALS['options']['sarmo_monitor_codespace_grant'] = $hash;
$GLOBALS['options'][$key] = ['expires' => time() + 120, 'scope' => 'ads', 'local' => true];
$origin = ['origin' => 'https://ads.snapppay.ir', 'localStorage' => [['name' => 'fixture', 'value' => 'sample']]];
$storageOnly = ['cookies' => [], 'origins' => [$origin]];
$foreignOrigin = ['cookies' => [], 'origins' => [array_merge($origin, ['origin' => 'https://app.snapppay.ir'])]];
foreach ([$foreignOrigin, ['cookies' => [], 'origins' => []]] as $invalid) {
    $result = Sarmo_SnappPay_Auth::receive_codespace_session(new WP_REST_Request(['grant' => $grant, 'session' => $invalid]));
    if (!$result instanceof WP_Error || $result->code !== 'bad_session') {
        throw new RuntimeException('An empty or wrong-origin storage-only Ads session must be rejected.');
    }
}
$accepted = Sarmo_SnappPay_Auth::receive_codespace_session(new WP_REST_Request(['grant' => $grant, 'session' => $storageOnly]));
if (!$accepted instanceof WP_REST_Response || Sarmo_SnappPay_Auth::ads_session()->data['session'] !== $storageOnly ||
    Sarmo_SnappPay_Auth::get_auth()->data['session'] !== $session) {
    throw new RuntimeException('WordPress must save a storage-only Ads session independently of the market session.');
}
echo "Ads storage-only session checks passed.\n";
