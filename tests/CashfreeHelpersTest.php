<?php
/**
 * Standalone tests for CashfreeHelpers (no WHMCS bootstrap, no live API).
 *
 * Run: php tests/CashfreeHelpersTest.php
 */

require_once __DIR__ . '/../modules/gateways/cashfree/lib/CashfreeHelpers.php';

$failures = 0;

function assert_true($cond, $message)
{
    global $failures;
    if (!$cond) {
        echo "FAIL: {$message}\n";
        $failures++;
        return;
    }
    echo "PASS: {$message}\n";
}

// --- invoice id extraction ---
assert_true(cashfree_extract_invoice_id('cf1710000000_42') === '42', 'extract invoice id');
assert_true(cashfree_extract_invoice_id('cf1710000000_') === null, 'reject empty invoice suffix');
assert_true(cashfree_extract_invoice_id('nounderscore') === null, 'reject missing underscore');
assert_true(cashfree_extract_invoice_id('cf1_12ab') === null, 'reject non-digit invoice id');

// --- amounts ---
assert_true(cashfree_amounts_match('10.00', 10), 'amount match int/float');
assert_true(cashfree_amounts_match('10.10', '10.1'), 'amount match trailing zero');
assert_true(!cashfree_amounts_match('10.00', '9.99'), 'amount mismatch');
assert_true(!cashfree_amounts_match('10.00', null), 'null paid amount fails');

// --- currencies ---
assert_true(cashfree_currencies_match('INR', 'inr'), 'currency case-insensitive match');
assert_true(cashfree_currencies_match('INR', 'USD') === false, 'currency mismatch rejected');
assert_true(cashfree_currencies_match('INR', ''), 'empty paid currency allowed (legacy)');
assert_true(cashfree_currencies_match('', 'INR'), 'empty invoice currency allowed');
assert_true(cashfree_currencies_match('', ''), 'both empty currencies allowed');

// --- phone ---
assert_true(cashfree_normalize_phone('9876543210') === '9876543210', '10-digit phone');
assert_true(cashfree_normalize_phone('+91 98765 43210') === '+919876543210', 'intl phone');
assert_true(cashfree_normalize_phone('123') === '9999999999', 'short phone fallback');

// --- legacy signature ---
$secret = 'test_secret_key';
$post = array(
    'orderId' => 'cf1_99',
    'orderAmount' => '100.00',
    'referenceId' => 'pay_1',
    'txStatus' => 'SUCCESS',
    'paymentMode' => 'UPI',
    'txMsg' => 'ok',
    'txTime' => '2024-01-01 00:00:00',
);
$data = $post['orderId'] . $post['orderAmount'] . $post['referenceId']
    . $post['txStatus'] . $post['paymentMode'] . $post['txMsg'] . $post['txTime'];
$post['signature'] = base64_encode(hash_hmac('sha256', $data, $secret, true));
assert_true(cashfree_verify_legacy_signature($post, $secret), 'legacy signature valid');
$bad = $post;
$bad['signature'] = 'nope';
assert_true(!cashfree_verify_legacy_signature($bad, $secret), 'legacy signature invalid');

$legacy = cashfree_parse_webhook_event('', $post, array(), $secret);
assert_true($legacy['ok'] === true && $legacy['event']['is_success'] === true, 'legacy SUCCESS parses');

$failedPost = $post;
$failedPost['txStatus'] = 'FAILED';
$failedData = $failedPost['orderId'] . $failedPost['orderAmount'] . $failedPost['referenceId']
    . $failedPost['txStatus'] . $failedPost['paymentMode'] . $failedPost['txMsg'] . $failedPost['txTime'];
$failedPost['signature'] = base64_encode(hash_hmac('sha256', $failedData, $secret, true));
$legacyFail = cashfree_parse_webhook_event('', $failedPost, array(), $secret);
assert_true(
    $legacyFail['ok'] === true && $legacyFail['event']['is_success'] === false,
    'legacy FAILED must not be treated as success'
);

// --- timestamp skew ---
assert_true(cashfree_webhook_max_timestamp_skew_seconds() === 300, 'default skew is 300s');
$now = 1700000000;
assert_true(cashfree_webhook_timestamp_fresh((string) $now, $now)['ok'] === true, 'exact now timestamp fresh');
assert_true(cashfree_webhook_timestamp_fresh((string) ($now - 300), $now)['ok'] === true, 'skew at boundary accepted');
$stale = cashfree_webhook_timestamp_fresh((string) ($now - 301), $now);
assert_true($stale['ok'] === false, 'stale timestamp rejected');
$future = cashfree_webhook_timestamp_fresh((string) ($now + 301), $now);
assert_true($future['ok'] === false, 'future-skew timestamp rejected');
assert_true(cashfree_webhook_timestamp_fresh('not-a-ts', $now)['ok'] === false, 'non-digit timestamp rejected');
assert_true(cashfree_webhook_timestamp_fresh('12.5', $now)['ok'] === false, 'float timestamp rejected');

// --- modern webhook (fresh timestamp) ---
$raw = json_encode(array(
    'data' => array(
        'order' => array(
            'order_id' => 'cf2_77',
            'order_amount' => 50.5,
            'order_currency' => 'INR',
        ),
        'payment' => array(
            'cf_payment_id' => '999',
            'payment_status' => 'SUCCESS',
            'payment_amount' => 50.5,
            'payment_currency' => 'INR',
        ),
    ),
    'type' => 'PAYMENT_SUCCESS_WEBHOOK',
));
$ts = (string) time();
$sig = base64_encode(hash_hmac('sha256', $ts . $raw, $secret, true));
$modern = cashfree_parse_webhook_event($raw, array(), array(
    'HTTP_X_WEBHOOK_SIGNATURE' => $sig,
    'HTTP_X_WEBHOOK_TIMESTAMP' => $ts,
), $secret);
assert_true($modern['ok'] === true && $modern['event']['is_success'] === true, 'modern SUCCESS parses');
assert_true($modern['event']['transaction_id'] === '999', 'modern payment id');
assert_true($modern['event']['currency'] === 'INR', 'modern currency extracted');
assert_true(cashfree_extract_invoice_id($modern['event']['order_id']) === '77', 'modern order invoice id');

$badModern = cashfree_parse_webhook_event($raw, array(), array(
    'HTTP_X_WEBHOOK_SIGNATURE' => 'bad',
    'HTTP_X_WEBHOOK_TIMESTAMP' => $ts,
), $secret);
assert_true($badModern['ok'] === false, 'modern bad signature rejected');
assert_true(
    isset($badModern['code']) && $badModern['code'] === 'rejected_sig',
    'modern bad signature code rejected_sig'
);

$staleTs = (string) (time() - 1000);
$staleSig = base64_encode(hash_hmac('sha256', $staleTs . $raw, $secret, true));
$staleModern = cashfree_parse_webhook_event($raw, array(), array(
    'HTTP_X_WEBHOOK_SIGNATURE' => $staleSig,
    'HTTP_X_WEBHOOK_TIMESTAMP' => $staleTs,
), $secret);
assert_true($staleModern['ok'] === false, 'stale modern webhook rejected');
assert_true(
    isset($staleModern['code']) && $staleModern['code'] === 'rejected_skew',
    'stale modern webhook code rejected_skew'
);

// Replay signed sample: signature must still verify after size helpers (body unchanged).
$replayCheck = cashfree_enforce_body_byte_limit($raw);
assert_true($replayCheck['ok'] === true && $replayCheck['body'] === $raw, 'signed sample within body limit');
$replay = cashfree_parse_webhook_event($replayCheck['body'], array(), array(
    'HTTP_X_WEBHOOK_SIGNATURE' => $sig,
    'HTTP_X_WEBHOOK_TIMESTAMP' => $ts,
), $secret);
assert_true($replay['ok'] === true && $replay['event']['is_success'] === true, 'signed sample webhook replays');

$failedRaw = json_encode(array(
    'data' => array(
        'order' => array('order_id' => 'cf2_77', 'order_amount' => 50.5, 'order_currency' => 'INR'),
        'payment' => array('cf_payment_id' => '1000', 'payment_status' => 'FAILED', 'payment_amount' => 50.5),
    ),
    'type' => 'PAYMENT_FAILED_WEBHOOK',
));
$failedSig = base64_encode(hash_hmac('sha256', $ts . $failedRaw, $secret, true));
$modernFail = cashfree_parse_webhook_event($failedRaw, array(), array(
    'HTTP_X_WEBHOOK_SIGNATURE' => $failedSig,
    'HTTP_X_WEBHOOK_TIMESTAMP' => $ts,
), $secret);
assert_true(
    $modernFail['ok'] === true && $modernFail['event']['is_success'] === false,
    'modern FAILED must not be treated as success'
);

// --- refund webhook normalize ---
$refundRaw = json_encode(array(
    'type' => 'PAYMENT_REFUND_WEBHOOK',
    'data' => array(
        'order' => array('order_id' => 'cf2_77', 'order_currency' => 'INR'),
        'payment' => array('cf_payment_id' => '999'),
        'refund' => array(
            'cf_refund_id' => 'rf_1',
            'cf_payment_id' => '999',
            'refund_amount' => 10.0,
            'refund_status' => 'SUCCESS',
            'refund_currency' => 'INR',
        ),
    ),
));
$refundSig = base64_encode(hash_hmac('sha256', $ts . $refundRaw, $secret, true));
$refundParsed = cashfree_parse_webhook_event($refundRaw, array(), array(
    'HTTP_X_WEBHOOK_SIGNATURE' => $refundSig,
    'HTTP_X_WEBHOOK_TIMESTAMP' => $ts,
), $secret);
assert_true($refundParsed['ok'] === true, 'refund webhook parses');
assert_true(!empty($refundParsed['event']['is_refund']), 'refund event flagged');
assert_true($refundParsed['event']['is_success'] === false, 'refund is not a payment success');
assert_true($refundParsed['event']['refund_id'] === 'rf_1', 'refund id extracted');
assert_true(!empty($refundParsed['event']['refund_ok']), 'refund_ok for SUCCESS');

// --- refund decision matrix ---
assert_true(cashfree_refund_decision(true, true, false) === 'note', 'refund note when payment known');
assert_true(cashfree_refund_decision(true, true, true) === 'duplicate', 'refund duplicate');
assert_true(cashfree_refund_decision(true, false, false) === 'missing_payment', 'refund without prior payment');
assert_true(cashfree_refund_decision(false, true, false) === 'lock_busy', 'refund lock busy');

// --- gateway log codes ---
assert_true(cashfree_gateway_log_code('applied') === 'applied', 'log code applied');
assert_true(cashfree_gateway_log_code('duplicate') === 'duplicate', 'log code duplicate');
assert_true(cashfree_gateway_log_code('rejected_sig') === 'rejected_sig', 'log code rejected_sig');
assert_true(cashfree_gateway_log_code('rejected_amount') === 'rejected_amount', 'log code rejected_amount');
assert_true(cashfree_gateway_log_code('rejected_currency') === 'rejected_currency', 'log code rejected_currency');
assert_true(cashfree_gateway_log_code('rejected_oversize') === 'rejected_oversize', 'log code rejected_oversize');
assert_true(cashfree_gateway_log_code('rejected_skew') === 'rejected_skew', 'log code rejected_skew');
assert_true(cashfree_gateway_log_code('nope') === 'unknown', 'unknown log code');
assert_true(cashfree_outcome_log_code('applied') === 'applied', 'outcome map applied');
assert_true(cashfree_outcome_log_code('note', 'refund') === 'refund_noted', 'outcome map refund note');
assert_true(cashfree_outcome_log_code('missing_payment', 'refund') === 'refund_missing_payment', 'outcome map refund missing');

// --- ledger row builder / raw hash ---
$hash = cashfree_event_raw_hash('abc');
assert_true(strlen($hash) === 64 && $hash === hash('sha256', 'abc'), 'raw hash sha256');
$row = cashfree_build_ledger_row(array(
    'order_id' => 'cf1_1',
    'cf_payment_id' => 'p1',
    'invoice_id' => '42',
    'amount' => 10.5,
    'currency' => 'inr',
    'event_type' => 'PAYMENT_SUCCESS_WEBHOOK',
    'verify_result' => 'ok',
    'credit_outcome' => 'applied',
    'source' => 'notify',
    'raw_hash' => $hash,
));
assert_true($row['invoice_id'] === 42 && $row['currency'] === 'INR', 'ledger row normalized');

// --- recon drift labels ---
assert_true(
    cashfree_recon_drift_label('Unpaid', 'PAID', true, false) === 'unmatched_success',
    'drift unmatched SUCCESS'
);
assert_true(
    cashfree_recon_drift_label('Paid', 'PAID', true, true) === 'ok',
    'drift ok when paid'
);
assert_true(
    cashfree_recon_drift_label('Unpaid', 'ACTIVE', false, false) === 'cf_unpaid',
    'drift cf still unpaid'
);

// JS escape
assert_true(strpos(cashfree_js_string('a"b'), '\\"') !== false, 'js escape quote');

// --- idempotent credit decision (return + notify race matrix) ---
assert_true(cashfree_credit_decision(true, false) === 'apply', 'lock held + unseen transid → apply');
assert_true(cashfree_credit_decision(true, true) === 'duplicate', 'lock held + existing transid → duplicate');
assert_true(cashfree_credit_decision(false, false) === 'lock_busy', 'lock miss → lock_busy (other worker applying)');
assert_true(cashfree_credit_decision(false, true) === 'lock_busy', 'lock miss wins over exists flag');
$lockName = cashfree_payment_lock_name('6690909903');
assert_true(strpos($lockName, 'cf_pay_') === 0 && strlen($lockName) <= 64, 'lock name length for MySQL GET_LOCK');
assert_true(cashfree_payment_lock_name('6690909903') === cashfree_payment_lock_name('6690909903'), 'lock name stable');
assert_true(cashfree_payment_lock_name('a') !== cashfree_payment_lock_name('b'), 'lock name distinct per transid');

// Offline: apply helper must refuse without WHMCS bootstrap (no Capsule / addInvoicePayment)
assert_true(
    cashfree_apply_invoice_payment_once(300003464, '6690909903', 1.00, 'cashfree') === 'invalid',
    'apply_once without WHMCS returns invalid'
);
assert_true(
    cashfree_apply_invoice_payment_once(300003464, '', 1.00, 'cashfree') === 'invalid',
    'empty transid rejected'
);

// --- webhook body size cap (CVE-2026-109618 / 109619 hardening) ---
assert_true(cashfree_webhook_max_body_bytes() === 65536, 'default max body is 64 KiB');
assert_true(cashfree_reject_oversized_content_length(null)['ok'] === true, 'missing Content-Length allowed');
assert_true(cashfree_reject_oversized_content_length('')['ok'] === true, 'empty Content-Length allowed');
assert_true(cashfree_reject_oversized_content_length('1024')['ok'] === true, 'small Content-Length accepted');
assert_true(cashfree_reject_oversized_content_length('65536')['ok'] === true, 'exact max Content-Length accepted');
$overLen = cashfree_reject_oversized_content_length('65537');
assert_true(
    $overLen['ok'] === false && isset($overLen['http_status']) && $overLen['http_status'] === 413,
    'oversize Content-Length rejected with 413'
);
$badLen = cashfree_reject_oversized_content_length('12.5');
assert_true(
    $badLen['ok'] === false && isset($badLen['http_status']) && $badLen['http_status'] === 400,
    'non-integer Content-Length rejected with 400'
);
$negLen = cashfree_reject_oversized_content_length('-1');
assert_true(
    $negLen['ok'] === false && isset($negLen['http_status']) && $negLen['http_status'] === 400,
    'negative Content-Length rejected with 400'
);

$okBody = cashfree_enforce_body_byte_limit(str_repeat('a', 65536));
assert_true($okBody['ok'] === true && strlen($okBody['body']) === 65536, 'body at exact max accepted');
$overBody = cashfree_enforce_body_byte_limit(str_repeat('b', 65537));
assert_true(
    $overBody['ok'] === false
        && isset($overBody['http_status'])
        && $overBody['http_status'] === 413
        && $overBody['body'] === '',
    'oversize body rejected with 413'
);
$customCap = cashfree_enforce_body_byte_limit('hello-world', 5);
assert_true(
    $customCap['ok'] === false && $customCap['http_status'] === 413,
    'custom maxBytes enforced'
);
$customOk = cashfree_enforce_body_byte_limit('hi', 5);
assert_true($customOk['ok'] === true && $customOk['body'] === 'hi', 'body under custom max accepted');

// Custom Content-Length max for unit isolation
$customLen = cashfree_reject_oversized_content_length('10', 8);
assert_true(
    $customLen['ok'] === false && $customLen['http_status'] === 413,
    'Content-Length respects custom maxBytes'
);

if ($failures > 0) {
    echo "\n{$failures} failure(s)\n";
    exit(1);
}

echo "\nAll tests passed\n";
exit(0);
