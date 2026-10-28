<?php
/**
 * Pure helpers for Cashfree WHMCS gateway (testable without WHMCS bootstrap).
 */

/** Plugin release version (admin UI + immutable package manifest). */
if (!defined('CASHFREE_PLUGIN_VERSION')) {
    define('CASHFREE_PLUGIN_VERSION', '2.5.0');
}

/** Hard cap for Cashfree notify / webhook request bodies (bytes). */
if (!defined('CASHFREE_WEBHOOK_MAX_BODY_BYTES')) {
    define('CASHFREE_WEBHOOK_MAX_BODY_BYTES', 65536);
}

/** Max |now − x-webhook-timestamp| skew in seconds (replay window). */
if (!defined('CASHFREE_WEBHOOK_MAX_TIMESTAMP_SKEW')) {
    define('CASHFREE_WEBHOOK_MAX_TIMESTAMP_SKEW', 300);
}

if (!function_exists('cashfree_webhook_max_body_bytes')) {
    /**
     * Maximum accepted webhook / notify body size in bytes.
     *
     * @return int
     */
    function cashfree_webhook_max_body_bytes()
    {
        return (int) CASHFREE_WEBHOOK_MAX_BODY_BYTES;
    }
}

if (!function_exists('cashfree_reject_oversized_content_length')) {
    /**
     * Reject oversized Content-Length before reading php://input.
     *
     * Missing / empty Content-Length is allowed; the capped read still enforces the limit.
     *
     * @param mixed $contentLengthHeader $_SERVER['CONTENT_LENGTH'] or null
     * @param int|null $maxBytes Defaults to cashfree_webhook_max_body_bytes()
     * @return array{ok:bool,error?:string,http_status?:int}
     */
    function cashfree_reject_oversized_content_length($contentLengthHeader, $maxBytes = null)
    {
        $max = $maxBytes !== null ? (int) $maxBytes : cashfree_webhook_max_body_bytes();
        if ($max < 1) {
            return array('ok' => false, 'error' => 'Invalid body size limit', 'http_status' => 500);
        }
        if ($contentLengthHeader === null || $contentLengthHeader === '') {
            return array('ok' => true);
        }
        $trimmed = trim((string) $contentLengthHeader);
        // Require a non-negative integer Content-Length (no floats / scientific notation).
        if ($trimmed === '' || !ctype_digit($trimmed)) {
            return array('ok' => false, 'error' => 'Invalid Content-Length', 'http_status' => 400);
        }
        $length = (int) $trimmed;
        if ($length > $max) {
            return array('ok' => false, 'error' => 'Request body too large', 'http_status' => 413);
        }
        return array('ok' => true);
    }
}

if (!function_exists('cashfree_enforce_body_byte_limit')) {
    /**
     * Enforce a byte limit on an already-buffered body string.
     *
     * @param string $rawBody
     * @param int|null $maxBytes
     * @return array{ok:bool,body?:string,error?:string,http_status?:int}
     */
    function cashfree_enforce_body_byte_limit($rawBody, $maxBytes = null)
    {
        $max = $maxBytes !== null ? (int) $maxBytes : cashfree_webhook_max_body_bytes();
        if (!is_string($rawBody)) {
            return array('ok' => false, 'error' => 'Invalid request body', 'http_status' => 400, 'body' => '');
        }
        if (strlen($rawBody) > $max) {
            return array('ok' => false, 'error' => 'Request body too large', 'http_status' => 413, 'body' => '');
        }
        return array('ok' => true, 'body' => $rawBody);
    }
}

if (!function_exists('cashfree_read_webhook_raw_body')) {
    /**
     * Read php://input with a hard byte cap (reads at most max+1 bytes to detect oversize).
     *
     * @param int|null $maxBytes
     * @return array{ok:bool,body:string,error?:string,http_status?:int}
     */
    function cashfree_read_webhook_raw_body($maxBytes = null)
    {
        $max = $maxBytes !== null ? (int) $maxBytes : cashfree_webhook_max_body_bytes();
        if ($max < 1) {
            return array('ok' => false, 'error' => 'Invalid body size limit', 'http_status' => 500, 'body' => '');
        }
        // Cap the read so an attacker cannot drive unbounded allocation via php://input.
        $raw = @file_get_contents('php://input', false, null, 0, $max + 1);
        if ($raw === false) {
            $raw = '';
        }
        return cashfree_enforce_body_byte_limit($raw, $max);
    }
}

if (!function_exists('cashfree_webhook_max_timestamp_skew_seconds')) {
    /**
     * @return int
     */
    function cashfree_webhook_max_timestamp_skew_seconds()
    {
        return (int) CASHFREE_WEBHOOK_MAX_TIMESTAMP_SKEW;
    }
}

if (!function_exists('cashfree_webhook_timestamp_fresh')) {
    /**
     * Reject stale or future-skewed webhook timestamps (replay window).
     *
     * @param mixed $timestamp Header x-webhook-timestamp (unix seconds)
     * @param int|null $now Unix now; defaults to time()
     * @param int|null $maxSkew Seconds; defaults to cashfree_webhook_max_timestamp_skew_seconds()
     * @return array{ok:bool,error?:string}
     */
    function cashfree_webhook_timestamp_fresh($timestamp, $now = null, $maxSkew = null)
    {
        $skew = $maxSkew !== null ? (int) $maxSkew : cashfree_webhook_max_timestamp_skew_seconds();
        if ($skew < 1) {
            return array('ok' => false, 'error' => 'Invalid timestamp skew limit');
        }
        $trimmed = trim((string) $timestamp);
        if ($trimmed === '' || !ctype_digit($trimmed)) {
            return array('ok' => false, 'error' => 'Invalid webhook timestamp');
        }
        $ts = (int) $trimmed;
        $nowTs = $now !== null ? (int) $now : time();
        if (abs($nowTs - $ts) > $skew) {
            return array('ok' => false, 'error' => 'Webhook timestamp outside allowed skew');
        }
        return array('ok' => true);
    }
}

if (!function_exists('cashfree_currencies_match')) {
    /**
     * Case-insensitive currency compare.
     * If either side is empty (legacy / unknown), do not reject on currency alone —
     * amount + signature checks still apply. Both set and unequal → fail.
     *
     * @param mixed $invoiceCurrency
     * @param mixed $paidCurrency
     * @return bool
     */
    function cashfree_currencies_match($invoiceCurrency, $paidCurrency)
    {
        $left = strtoupper(trim((string) $invoiceCurrency));
        $right = strtoupper(trim((string) $paidCurrency));
        if ($left === '' || $right === '') {
            return true;
        }
        return $left === $right;
    }
}

if (!function_exists('cashfree_gateway_log_code')) {
    /**
     * Structured gateway-log outcome codes for operator filtering.
     *
     * @param string $outcome
     * @return string
     */
    function cashfree_gateway_log_code($outcome)
    {
        $outcome = (string) $outcome;
        switch ($outcome) {
            case 'applied':
                return 'applied';
            case 'duplicate':
                return 'duplicate';
            case 'lock_busy':
                return 'lock_busy';
            case 'rejected_sig':
                return 'rejected_sig';
            case 'rejected_amount':
                return 'rejected_amount';
            case 'rejected_currency':
                return 'rejected_currency';
            case 'rejected_oversize':
                return 'rejected_oversize';
            case 'rejected_skew':
                return 'rejected_skew';
            case 'ignored_not_success':
                return 'ignored_not_success';
            case 'refund_noted':
                return 'refund_noted';
            case 'refund_duplicate':
                return 'refund_duplicate';
            case 'refund_missing_payment':
                return 'refund_missing_payment';
            case 'invalid':
                return 'invalid';
            default:
                return 'unknown';
        }
    }
}

if (!function_exists('cashfree_refund_decision')) {
    /**
     * Pure decision matrix for Cashfree refund webhooks (never auto-debits here).
     *
     * @param bool $lockAcquired
     * @param bool $paymentRecorded Original cf_payment_id exists in tblaccounts
     * @param bool $refundAlreadyRecorded Same refund id already in ledger
     * @return string note|duplicate|lock_busy|missing_payment|invalid
     */
    function cashfree_refund_decision($lockAcquired, $paymentRecorded, $refundAlreadyRecorded)
    {
        if (!$lockAcquired) {
            return 'lock_busy';
        }
        if ($refundAlreadyRecorded) {
            return 'duplicate';
        }
        if (!$paymentRecorded) {
            return 'missing_payment';
        }
        return 'note';
    }
}

if (!function_exists('cashfree_extract_invoice_id')) {
    /**
     * Order ids are formatted as cf{unix}_{invoiceId}.
     *
     * @param string $orderId
     * @return string|null Invoice id digits or null if invalid
     */
    function cashfree_extract_invoice_id($orderId)
    {
        $orderId = trim((string) $orderId);
        if ($orderId === '' || strpos($orderId, '_') === false) {
            return null;
        }
        $invoiceId = substr($orderId, strpos($orderId, '_') + 1);
        if ($invoiceId === '' || !ctype_digit((string) $invoiceId)) {
            return null;
        }
        return (string) $invoiceId;
    }
}

if (!function_exists('cashfree_verify_legacy_signature')) {
    /**
     * Legacy notify_url form signature (pre-JSON webhooks).
     *
     * @param array $post
     * @param string $secretKey
     * @return bool
     */
    function cashfree_verify_legacy_signature(array $post, $secretKey)
    {
        $required = array('orderId', 'orderAmount', 'referenceId', 'txStatus', 'paymentMode', 'txMsg', 'txTime', 'signature');
        foreach ($required as $key) {
            if (!isset($post[$key])) {
                return false;
            }
        }
        $data = $post['orderId'] . $post['orderAmount'] . $post['referenceId']
            . $post['txStatus'] . $post['paymentMode'] . $post['txMsg'] . $post['txTime'];
        $computed = base64_encode(hash_hmac('sha256', $data, (string) $secretKey, true));
        return hash_equals($computed, (string) $post['signature']);
    }
}

if (!function_exists('cashfree_verify_webhook_signature')) {
    /**
     * Modern PG webhook signature: Base64(HMAC-SHA256(timestamp + rawBody, secret)).
     *
     * @param string $signature Header x-webhook-signature
     * @param string $timestamp Header x-webhook-timestamp
     * @param string $rawBody Raw request body
     * @param string $secretKey
     * @return bool
     */
    function cashfree_verify_webhook_signature($signature, $timestamp, $rawBody, $secretKey)
    {
        if ($signature === '' || $timestamp === '' || $rawBody === '' || $secretKey === '') {
            return false;
        }
        $expected = base64_encode(hash_hmac('sha256', $timestamp . $rawBody, (string) $secretKey, true));
        return hash_equals($expected, (string) $signature);
    }
}

if (!function_exists('cashfree_parse_webhook_event')) {
    /**
     * Normalize modern JSON or legacy form notify into a common payment event.
     *
     * @param string $rawBody
     * @param array $post
     * @param array $server
     * @param string $secretKey
     * @return array{ok:bool,error?:string,event?:array}
     */
    function cashfree_parse_webhook_event($rawBody, array $post, array $server, $secretKey)
    {
        $signature = isset($server['HTTP_X_WEBHOOK_SIGNATURE']) ? (string) $server['HTTP_X_WEBHOOK_SIGNATURE'] : '';
        $timestamp = isset($server['HTTP_X_WEBHOOK_TIMESTAMP']) ? (string) $server['HTTP_X_WEBHOOK_TIMESTAMP'] : '';

        if ($signature !== '' && $timestamp !== '' && $rawBody !== '') {
            $fresh = cashfree_webhook_timestamp_fresh($timestamp);
            if (!$fresh['ok']) {
                return array(
                    'ok' => false,
                    'error' => isset($fresh['error']) ? $fresh['error'] : 'Webhook timestamp outside allowed skew',
                    'code' => 'rejected_skew',
                );
            }
            if (!cashfree_verify_webhook_signature($signature, $timestamp, $rawBody, $secretKey)) {
                return array('ok' => false, 'error' => 'Invalid webhook signature', 'code' => 'rejected_sig');
            }
            // Depth-capped decode: webhook JSON is shallow; deep nesting is not legitimate.
            $payload = json_decode($rawBody, true, 32);
            if (!is_array($payload)) {
                return array('ok' => false, 'error' => 'Invalid webhook JSON', 'code' => 'invalid');
            }
            return cashfree_normalize_modern_payload($payload);
        }

        if (!empty($post['orderId']) && isset($post['signature'])) {
            if (!cashfree_verify_legacy_signature($post, $secretKey)) {
                return array('ok' => false, 'error' => 'Invalid legacy signature', 'code' => 'rejected_sig');
            }
            return cashfree_normalize_legacy_post($post);
        }

        return array('ok' => false, 'error' => 'Unrecognized webhook payload', 'code' => 'invalid');
    }
}

if (!function_exists('cashfree_normalize_modern_payload')) {
    /**
     * @param array $payload
     * @return array{ok:bool,error?:string,event?:array}
     */
    function cashfree_normalize_modern_payload(array $payload)
    {
        $type = isset($payload['type']) ? (string) $payload['type'] : '';
        $data = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : array();
        $order = isset($data['order']) && is_array($data['order']) ? $data['order'] : array();
        $payment = isset($data['payment']) && is_array($data['payment']) ? $data['payment'] : array();
        $refund = isset($data['refund']) && is_array($data['refund']) ? $data['refund'] : array();

        $orderId = isset($order['order_id']) ? (string) $order['order_id'] : '';
        $paymentStatus = isset($payment['payment_status']) ? strtoupper((string) $payment['payment_status']) : '';
        $transactionId = isset($payment['cf_payment_id']) ? (string) $payment['cf_payment_id'] : '';
        $orderAmount = isset($order['order_amount']) ? $order['order_amount'] : null;
        if ($orderAmount === null && isset($payment['payment_amount'])) {
            $orderAmount = $payment['payment_amount'];
        }
        $currency = isset($order['order_currency'])
            ? (string) $order['order_currency']
            : (isset($payment['payment_currency']) ? (string) $payment['payment_currency'] : '');

        $refundTypes = array(
            'PAYMENT_REFUND_WEBHOOK',
            'REFUND_STATUS_WEBHOOK',
            'REFUND_SUCCESS_WEBHOOK',
        );
        $isRefundEvent = in_array($type, $refundTypes, true) || !empty($refund);

        if ($isRefundEvent) {
            $refundId = isset($refund['cf_refund_id'])
                ? (string) $refund['cf_refund_id']
                : (isset($refund['refund_id']) ? (string) $refund['refund_id'] : '');
            if ($transactionId === '' && isset($refund['cf_payment_id'])) {
                $transactionId = (string) $refund['cf_payment_id'];
            }
            if ($orderId === '' && isset($refund['order_id'])) {
                $orderId = (string) $refund['order_id'];
            }
            $refundStatus = isset($refund['refund_status'])
                ? strtoupper((string) $refund['refund_status'])
                : (isset($refund['status']) ? strtoupper((string) $refund['status']) : '');
            $refundAmount = isset($refund['refund_amount'])
                ? $refund['refund_amount']
                : (isset($refund['amount']) ? $refund['amount'] : null);
            if ($currency === '' && isset($refund['refund_currency'])) {
                $currency = (string) $refund['refund_currency'];
            }
            if ($orderId === '' || $transactionId === '' || $refundId === '') {
                return array('ok' => false, 'error' => 'Missing refund, order, or payment id in webhook', 'code' => 'invalid');
            }
            $refundOk = ($refundStatus === 'SUCCESS' || $refundStatus === 'COMPLETED' || $refundStatus === '');
            return array(
                'ok' => true,
                'event' => array(
                    'order_id' => $orderId,
                    'transaction_id' => $transactionId,
                    'refund_id' => $refundId,
                    'amount' => $refundAmount !== null ? $refundAmount : $orderAmount,
                    'currency' => $currency,
                    'payment_status' => $refundStatus !== '' ? $refundStatus : 'SUCCESS',
                    'is_success' => false,
                    'is_refund' => true,
                    'refund_ok' => $refundOk,
                    'raw_type' => $type !== '' ? $type : 'PAYMENT_REFUND_WEBHOOK',
                    'format' => 'modern',
                ),
            );
        }

        $successTypes = array(
            'PAYMENT_SUCCESS_WEBHOOK',
            'PAYMENT_SUCCESS',
            'PAYMENT_CHARGES_WEBHOOK',
        );
        $isSuccessEvent = in_array($type, $successTypes, true) || $paymentStatus === 'SUCCESS';

        if ($orderId === '' || $transactionId === '') {
            return array('ok' => false, 'error' => 'Missing order or payment id in webhook', 'code' => 'invalid');
        }

        return array(
            'ok' => true,
            'event' => array(
                'order_id' => $orderId,
                'transaction_id' => $transactionId,
                'refund_id' => '',
                'amount' => $orderAmount,
                'currency' => $currency,
                'payment_status' => $paymentStatus !== '' ? $paymentStatus : ($isSuccessEvent ? 'SUCCESS' : 'UNKNOWN'),
                'is_success' => ($paymentStatus === 'SUCCESS' || ($isSuccessEvent && $paymentStatus === '')),
                'is_refund' => false,
                'refund_ok' => false,
                'raw_type' => $type,
                'format' => 'modern',
            ),
        );
    }
}

if (!function_exists('cashfree_normalize_legacy_post')) {
    /**
     * @param array $post
     * @return array{ok:bool,error?:string,event?:array}
     */
    function cashfree_normalize_legacy_post(array $post)
    {
        $txStatus = isset($post['txStatus']) ? strtoupper((string) $post['txStatus']) : '';
        $orderId = (string) $post['orderId'];
        $transactionId = isset($post['referenceId']) ? (string) $post['referenceId'] : '';
        if ($orderId === '' || $transactionId === '') {
            return array('ok' => false, 'error' => 'Missing legacy order or reference id');
        }

        return array(
            'ok' => true,
            'event' => array(
                'order_id' => $orderId,
                'transaction_id' => $transactionId,
                'refund_id' => '',
                'amount' => isset($post['orderAmount']) ? $post['orderAmount'] : null,
                'currency' => '',
                'payment_status' => $txStatus,
                'is_success' => ($txStatus === 'SUCCESS'),
                'is_refund' => false,
                'refund_ok' => false,
                'raw_type' => 'LEGACY_' . $txStatus,
                'format' => 'legacy',
            ),
        );
    }
}

if (!function_exists('cashfree_amounts_match')) {
    /**
     * @param mixed $invoiceAmount
     * @param mixed $paidAmount
     * @return bool
     */
    function cashfree_amounts_match($invoiceAmount, $paidAmount)
    {
        if ($paidAmount === null || $paidAmount === '') {
            return false;
        }
        return round((float) $invoiceAmount, 2) === round((float) $paidAmount, 2);
    }
}

if (!function_exists('cashfree_normalize_phone')) {
    /**
     * @param string $phone
     * @return string
     */
    function cashfree_normalize_phone($phone)
    {
        $digits = preg_replace('/\D/', '', (string) $phone);
        if ($digits === null || strlen($digits) < 10) {
            return '9999999999';
        }
        if (strlen($digits) === 10) {
            return $digits;
        }
        return '+' . $digits;
    }
}

if (!function_exists('cashfree_js_string')) {
    /**
     * Escape a value for safe inclusion inside a double-quoted JS string literal.
     *
     * @param string $value
     * @return string
     */
    function cashfree_js_string($value)
    {
        return str_replace(
            array('\\', '"', "\n", "\r", '</'),
            array('\\\\', '\\"', '\\n', '\\r', '<\/'),
            (string) $value
        );
    }
}

if (!function_exists('cashfree_payment_lock_name')) {
    /**
     * MySQL GET_LOCK name for a Cashfree payment id (max 64 chars).
     *
     * @param string $transactionId Cashfree cf_payment_id / referenceId
     * @return string
     */
    function cashfree_payment_lock_name($transactionId)
    {
        return 'cf_pay_' . substr(hash('sha256', (string) $transactionId), 0, 40);
    }
}

if (!function_exists('cashfree_credit_decision')) {
    /**
     * Pure decision matrix for whether this worker should call addInvoicePayment.
     *
     * @param bool $lockAcquired
     * @param bool $alreadyRecorded
     * @return string apply|duplicate|lock_busy
     */
    function cashfree_credit_decision($lockAcquired, $alreadyRecorded)
    {
        if (!$lockAcquired) {
            return 'lock_busy';
        }
        if ($alreadyRecorded) {
            return 'duplicate';
        }
        return 'apply';
    }
}

if (!function_exists('cashfree_event_raw_hash')) {
    /**
     * SHA-256 of raw body for ledger storage (never store full PII body).
     *
     * @param string $rawBody
     * @return string 64-char hex
     */
    function cashfree_event_raw_hash($rawBody)
    {
        return hash('sha256', (string) $rawBody);
    }
}

if (!function_exists('cashfree_outcome_log_code')) {
    /**
     * Map credit / refund worker outcomes to structured gateway log codes.
     *
     * @param string $outcome applied|duplicate|lock_busy|invalid|note|missing_payment|…
     * @param string $kind payment|refund
     * @return string
     */
    function cashfree_outcome_log_code($outcome, $kind = 'payment')
    {
        $outcome = (string) $outcome;
        $kind = (string) $kind;
        if ($kind === 'refund') {
            switch ($outcome) {
                case 'note':
                    return cashfree_gateway_log_code('refund_noted');
                case 'duplicate':
                    return cashfree_gateway_log_code('refund_duplicate');
                case 'missing_payment':
                    return cashfree_gateway_log_code('refund_missing_payment');
                case 'lock_busy':
                    return cashfree_gateway_log_code('lock_busy');
                default:
                    return cashfree_gateway_log_code('invalid');
            }
        }
        switch ($outcome) {
            case 'applied':
                return cashfree_gateway_log_code('applied');
            case 'duplicate':
                return cashfree_gateway_log_code('duplicate');
            case 'lock_busy':
                return cashfree_gateway_log_code('lock_busy');
            default:
                return cashfree_gateway_log_code('invalid');
        }
    }
}

if (!function_exists('cashfree_build_ledger_row')) {
    /**
     * Pure builder for mod_cashfree_events row fields (no DB).
     *
     * @param array $fields
     * @return array
     */
    function cashfree_build_ledger_row(array $fields)
    {
        return array(
            'order_id' => isset($fields['order_id']) ? (string) $fields['order_id'] : '',
            'cf_payment_id' => isset($fields['cf_payment_id']) ? (string) $fields['cf_payment_id'] : '',
            'cf_refund_id' => isset($fields['cf_refund_id']) ? (string) $fields['cf_refund_id'] : '',
            'invoice_id' => isset($fields['invoice_id']) ? (int) $fields['invoice_id'] : 0,
            'amount' => array_key_exists('amount', $fields) ? $fields['amount'] : null,
            'currency' => isset($fields['currency']) ? strtoupper(trim((string) $fields['currency'])) : '',
            'event_type' => isset($fields['event_type']) ? (string) $fields['event_type'] : '',
            'verify_result' => isset($fields['verify_result']) ? (string) $fields['verify_result'] : '',
            'credit_outcome' => isset($fields['credit_outcome']) ? (string) $fields['credit_outcome'] : '',
            'source' => isset($fields['source']) ? (string) $fields['source'] : '',
            'raw_hash' => isset($fields['raw_hash']) ? (string) $fields['raw_hash'] : '',
        );
    }
}

if (!function_exists('cashfree_recon_drift_label')) {
    /**
     * Operator-facing drift label for unpaid invoice vs Cashfree order status.
     *
     * @param string $invoiceStatus WHMCS invoice status
     * @param string $cfOrderStatus Cashfree order_status
     * @param bool $paymentSuccessOnCf At least one SUCCESS payment on the order
     * @param bool $whmcsHasPayment tblaccounts already has cf_payment_id
     * @return string ok|unmatched_success|cf_unpaid|unknown
     */
    function cashfree_recon_drift_label($invoiceStatus, $cfOrderStatus, $paymentSuccessOnCf, $whmcsHasPayment)
    {
        $invoiceStatus = strtolower(trim((string) $invoiceStatus));
        $cfOrderStatus = strtoupper(trim((string) $cfOrderStatus));
        if ($whmcsHasPayment || $invoiceStatus === 'paid') {
            return 'ok';
        }
        if ($paymentSuccessOnCf || $cfOrderStatus === 'PAID') {
            return 'unmatched_success';
        }
        if ($cfOrderStatus === 'ACTIVE' || $cfOrderStatus === 'EXPIRED' || $cfOrderStatus === '') {
            return 'cf_unpaid';
        }
        return 'unknown';
    }
}

if (!function_exists('cashfree_apply_invoice_payment_once')) {
    /**
     * Atomically credit an invoice at most once per Cashfree transaction id.
     *
     * Holds a MySQL advisory lock across the exists-check and addInvoicePayment
     * so browser return and server notify cannot both credit the same cf_payment_id.
     * Soft Capsule exists-checks alone are racy (TOCTOU) under concurrent callbacks.
     *
     * Requires WHMCS Capsule + addInvoicePayment (not for offline unit tests).
     *
     * @param int|string $invoiceId
     * @param string $transactionId
     * @param mixed $amount
     * @param string $gatewayModule
     * @return string applied|duplicate|lock_busy|invalid
     */
    function cashfree_apply_invoice_payment_once($invoiceId, $transactionId, $amount, $gatewayModule)
    {
        $transactionId = trim((string) $transactionId);
        if ($transactionId === '' || $invoiceId === '' || $invoiceId === null) {
            return 'invalid';
        }
        if (!class_exists('WHMCS\\Database\\Capsule') || !function_exists('addInvoicePayment')) {
            return 'invalid';
        }

        $lockName = cashfree_payment_lock_name($transactionId);
        $got = \WHMCS\Database\Capsule::selectOne('SELECT GET_LOCK(?, 15) AS g', array($lockName));
        $lockAcquired = $got && (int) $got->g === 1;

        try {
            $already = false;
            if ($lockAcquired) {
                $already = \WHMCS\Database\Capsule::table('tblaccounts')
                    ->where('transid', $transactionId)
                    ->exists();
            }
            $decision = cashfree_credit_decision($lockAcquired, $already);
            if ($decision !== 'apply') {
                return $decision;
            }
            addInvoicePayment(
                $invoiceId,
                $transactionId,
                $amount,
                0,
                $gatewayModule
            );
            return 'applied';
        } finally {
            if ($lockAcquired) {
                \WHMCS\Database\Capsule::selectOne('SELECT RELEASE_LOCK(?) AS r', array($lockName));
            }
        }
    }
}
