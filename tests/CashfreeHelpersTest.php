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

// --- modern webhook ---
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
$ts = '1700000000';
$sig = base64_encode(hash_hmac('sha256', $ts . $raw, $secret, true));
$modern = cashfree_parse_webhook_event($raw, array(), array(
    'HTTP_X_WEBHOOK_SIGNATURE' => $sig,
    'HTTP_X_WEBHOOK_TIMESTAMP' => $ts,
), $secret);
assert_true($modern['ok'] === true && $modern['event']['is_success'] === true, 'modern SUCCESS parses');
assert_true($modern['event']['transaction_id'] === '999', 'modern payment id');
assert_true(cashfree_extract_invoice_id($modern['event']['order_id']) === '77', 'modern order invoice id');

$badModern = cashfree_parse_webhook_event($raw, array(), array(
    'HTTP_X_WEBHOOK_SIGNATURE' => 'bad',
    'HTTP_X_WEBHOOK_TIMESTAMP' => $ts,
), $secret);
assert_true($badModern['ok'] === false, 'modern bad signature rejected');

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

// JS escape
assert_true(strpos(cashfree_js_string('a"b'), '\\"') !== false, 'js escape quote');

if ($failures > 0) {
    echo "\n{$failures} failure(s)\n";
    exit(1);
}

echo "\nAll tests passed\n";
exit(0);
