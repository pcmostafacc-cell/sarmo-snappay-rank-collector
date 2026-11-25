<?php
if (!defined('ABSPATH')) { exit; }

final class Sarmo_SnappPay_Bids {
    private const CAP = 'sarmo_monitor_ads_bid_cap';
    private const MAX_AMOUNT = 1000000000;
    private const WINDOW = 1800;

    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'sarmo_snappay_bid_jobs';
    }

    public static function activate(): void {
        global $wpdb;
        $table = self::table();
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $table (
            id char(16) NOT NULL,
            campaign_id char(36) NOT NULL,
            campaign_name varchar(100) NOT NULL,
            old_bid bigint unsigned NOT NULL,
            new_bid bigint unsigned NOT NULL,
            state varchar(20) NOT NULL,
            expires bigint unsigned NOT NULL,
            claim_hash char(64) NOT NULL DEFAULT '',
            actual_bid bigint unsigned NULL,
            reason varchar(50) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY state_expires (state,expires)
        ) $charset;");
        if (!get_role('sarmo_ads_executor')) {
            add_role('sarmo_ads_executor', 'Sarmo SnappPay Ads Executor', [
                'read' => true,
                'manage_sarmo_ads_bid' => true,
            ]);
        }
    }

    private static function amount(string $text): int {
        if (!preg_match('/^[1-9][0-9]{0,9}$/D', $text)) { return 0; }
        $amount = (int) $text;
        return $amount <= self::MAX_AMOUNT ? $amount : 0;
    }

    private static function lookup(string $id): ?array {
        if (!preg_match('/^[a-f0-9]{16}$/D', $id)) { return null; }
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %s', $id), ARRAY_A) ?: null;
    }

    private static function label(array $row): string {
        return '«' . $row['campaign_name'] . '» | قیمت قبلی ' . number_format((int) $row['old_bid']) .
            ' تومان | قیمت درخواستی ' . number_format((int) $row['new_bid']) . ' تومان';
    }

    public static function cap(string $argument): string {
        if ($argument !== '') {
            $value = self::amount($argument);
            if ($value <= 0) { return 'قالب: /bid_cap مبلغ سقف هر کلیک به تومان'; }
            update_option(self::CAP, $value, false);
        }
        $cap = (int) get_option(self::CAP, 0);
        return $cap > 0 ? 'سقف قیمت پیشنهادی هر کلیک: ' . number_format($cap) . ' تومان.' :
            'سقف تغییر قیمت ثبت نشده است. /bid_cap مبلغ به تومان';
    }

    public static function draft(string $argument): string {
        if (!preg_match('/^(.*?)\s+([1-9][0-9]{0,9})$/D', $argument, $parts)) {
            return 'قالب: /bid نام کمپین مبلغ به تومان';
        }
        $amount = self::amount($parts[2]);
        $cap = (int) get_option(self::CAP, 0);
        if ($cap <= 0 || $amount <= 0 || $amount > $cap) { return self::cap('') . ' درخواست خارج از سقف یا نامعتبر است.'; }
        $campaign = Sarmo_SnappPay_Ads::bid_target($parts[1]);
        if (!$campaign) { return 'کمپین فعال یکتا با قیمت تازه پیدا نشد. /ads را بررسی کن.'; }
        if ($campaign['bid_toman'] === $amount) { return 'قیمت کمپین از قبل همین است.'; }
        $id = bin2hex(random_bytes(8));
        $row = [
            'id' => $id,
            'campaign_id' => $campaign['id'],
            'campaign_name' => $campaign['name'],
            'old_bid' => $campaign['bid_toman'],
            'new_bid' => $amount,
            'state' => 'draft',
            'expires' => time() + self::WINDOW,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ];
        global $wpdb;
        if (!$wpdb->insert(self::table(), $row)) { return 'ثبت پیش‌نویس ممکن نشد.'; }
        return self::label($row) . "\nبرای تأیید همین درخواست در ۳۰ دقیقهٔ آینده: /bid_confirm $id";
    }

    public static function confirm(string $id): string {
        $id = strtolower(trim($id));
        $row = self::lookup($id);
        $now = time();
        if (!$row || $row['state'] !== 'draft' || (int) $row['expires'] <= $now) {
            return 'درخواست معتبر و در انتظار تأیید پیدا نشد.';
        }
        if ((int) $row['new_bid'] > (int) get_option(self::CAP, 0)) { return 'مبلغ از سقف فعلی بیشتر است.'; }
        global $wpdb;
        $result = $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::table() . " SET state='ready', expires=%d WHERE id=%s AND state='draft' AND expires>%d",
            $now + self::WINDOW, $id, $now
        ));
        return $result === 1 ? self::label($row) . "\nدر صف اجرا: /bid_status $id" : 'درخواست برای تأیید در دسترس نبود.';
    }

    public static function status(string $id): string {
        $row = self::lookup(strtolower(trim($id)));
        if (!$row) { return 'درخواست پیدا نشد. /bid_status شناسه'; }
        $states = ['draft' => 'در انتظار تأیید', 'ready' => 'در صف اجرا', 'claimed' => 'در حال بررسی', 'applied' => 'اعمال و تأیید شد',
            'blocked' => 'متوقف شد', 'uncertain' => 'نتیجه نیازمند بررسی در پنل'];
        $status = $states[$row['state']] ?? 'نامشخص';
        if (in_array($row['state'], ['draft','ready','claimed'], true) && (int) $row['expires'] <= time()) {
            $status = $row['state'] === 'claimed' ? 'نتیجه نیازمند بررسی در پنل' : 'منقضی شد';
        }
        $reasons = ['stale_bid' => 'قیمت پنل از زمان درخواست تغییر کرده', 'inactive' => 'کمپین دیگر فعال نیست',
            'guard' => 'شرط سقف یا سقف بودجهٔ مشاهده‌شده برقرار نبود',
            'editor' => 'ویرایشگر مطابق انتظار نبود', 'readback' => 'قیمت نهایی از پنل تأیید نشد'];
        $reason = $reasons[$row['reason']] ?? '';
        $actual = $row['actual_bid'] === null ? '' : "\nعدد تأییدشده در پنل: " . number_format((int) $row['actual_bid']) . ' تومان';
        return self::label($row) . "\nوضعیت: " . $status . ($reason ? "\n" . $reason : '') . $actual;
    }

    public static function routes(): void {
        $permission = static fn() => current_user_can('manage_sarmo_ads_bid');
        register_rest_route('sarmo-monitor/v1', '/ads-bid-next', [
            'methods' => 'GET', 'callback' => [self::class, 'next'],
            'permission_callback' => $permission,
        ]);
        register_rest_route('sarmo-monitor/v1', '/ads-bid-claim', [
            'methods' => 'POST', 'callback' => [self::class, 'claim'],
            'permission_callback' => $permission,
        ]);
        register_rest_route('sarmo-monitor/v1', '/ads-bid-result', [
            'methods' => 'POST', 'callback' => [self::class, 'result'],
            'permission_callback' => $permission,
        ]);
        register_rest_route('sarmo-monitor/v1', '/ads-bid-login-required', [
            'methods' => 'POST', 'permission_callback' => $permission,
            'callback' => static fn() => rest_ensure_response(['ok' => Sarmo_SnappPay_Auth::codespace_status('required', 'ads')]),
        ]);
    }

    public static function next(): WP_REST_Response {
        global $wpdb;
        $cap = (int) get_option(self::CAP, 0);
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT id, campaign_id, old_bid, new_bid, expires FROM ' . self::table() .
            " WHERE state='ready' AND expires>%d AND new_bid<=%d ORDER BY created_at,id LIMIT 1",
            time(), $cap
        ), ARRAY_A);
        // Keep the market reader and reporting role separate from the bid queue.
        $session = $row ? Sarmo_SnappPay_Auth::ads_session()->get_data()['session'] : null;
        $response = new WP_REST_Response(['job' => $row ?: null, 'session' => $session]);
        $response->header('Cache-Control', 'no-store, private');
        return $response;
    }

    public static function claim(WP_REST_Request $request) {
        $id = $request->get_param('id');
        if (!is_string($id) || !self::lookup($id)) { return new WP_Error('unknown_job', 'No matching job', ['status' => 404]); }
        global $wpdb;
        $token = bin2hex(random_bytes(32));
        $now = time();
        $updated = $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::table() . " SET state='claimed', claim_hash=%s, expires=%d
            WHERE id=%s AND state='ready' AND expires>%d AND new_bid<=%d",
            hash('sha256', $token), $now + 120, $id, $now, (int) get_option(self::CAP, 0)
        ));
        if ($updated !== 1) { return new WP_Error('claim_conflict', 'Job is unavailable', ['status' => 409]); }
        $response = new WP_REST_Response(['claim' => $token, 'expires' => $now + 120]);
        $response->header('Cache-Control', 'no-store, private');
        return $response;
    }

    public static function result(WP_REST_Request $request) {
        $id = $request->get_param('id');
        $row = is_string($id) ? self::lookup($id) : null;
        $claim = $request->get_param('claim');
        $state = $request->get_param('state');
        $reason = $request->get_param('reason');
        $actual = $request->get_param('actual_bid');
        if (!$row || $row['state'] !== 'claimed' || !is_string($claim) ||
            !hash_equals($row['claim_hash'], hash('sha256', $claim)) ||
            !in_array($state, ['applied','blocked','uncertain'], true)) {
            return new WP_Error('bad_result', 'Unknown or consumed claim', ['status' => 409]);
        }
        if ($state === 'applied' && (!is_int($actual) || (int) $row['new_bid'] !== $actual)) {
            return new WP_Error('bad_readback', 'Bid could not be verified', ['status' => 400]);
        }
        $reasons = ['inactive','stale_bid','guard','editor','readback'];
        global $wpdb;
        $updated = $wpdb->update(self::table(), [
            'state' => $state, 'actual_bid' => $state === 'applied' ? $actual : null,
            'reason' => in_array($reason, $reasons, true) ? $reason : '', 'claim_hash' => '',
        ], ['id' => $id, 'state' => 'claimed', 'claim_hash' => $row['claim_hash']]);
        if ($updated !== 1) { return new WP_Error('bad_result', 'Claim was already completed', ['status' => 409]); }
        Sarmo_SnappPay_Runtime::notify_owner(self::status($id));
        return rest_ensure_response(['ok' => true]);
    }
}
