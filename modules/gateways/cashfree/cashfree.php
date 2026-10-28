<?php
/**
 * WHMCS Cashfree Payment Return / Callback
 *
 * Verifies payment via Cashfree Orders API, then applies invoice payment
 * when status is SUCCESS and amounts + currency match. Idempotent on duplicate
 * trans IDs (advisory lock shared with cashfree_notify.php).
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/lib/CashfreeHelpers.php';
require_once __DIR__ . '/lib/CashfreeLedger.php';

use WHMCS\Database\Capsule;

if (!defined('API_VERSION')) {
    define('API_VERSION', '2022-09-01');
}

$gateway_module_name = 'cashfree';
$gateway_params = getGatewayVariables($gateway_module_name);

if (empty($gateway_params['type'])) {
    die('Module Not Activated');
}

$app_id = $gateway_params['appId'];
$secret_key = $gateway_params['secretKey'];
$system_url = rtrim($gateway_params['systemurl'], '/') . '/';

$cashfree_order_id = isset($_REQUEST['order_id']) ? trim((string) $_REQUEST['order_id']) : '';
$invoice_id = cashfree_extract_invoice_id($cashfree_order_id);

if ($invoice_id === null) {
    logTransaction($gateway_params['name'], array(
        'code' => cashfree_gateway_log_code('invalid'),
        'request' => $_REQUEST,
    ), 'Cashfree ' . cashfree_gateway_log_code('invalid'));
    header('Location: ' . $system_url . 'clientarea.php');
    exit;
}

$invoice_id = checkCbInvoiceID($invoice_id, $gateway_params['name']);

$api_endpoint = ($gateway_params['testMode'] == 'on')
    ? 'https://sandbox.cashfree.com/pg/orders'
    : 'https://api.cashfree.com/pg/orders';

$get_payment_url = $api_endpoint . '/' . rawurlencode($cashfree_order_id) . '/payments';

$curl = curl_init();
curl_setopt_array($curl, array(
    CURLOPT_URL => $get_payment_url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => '',
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST => 'GET',
    CURLOPT_HTTPHEADER => array(
        'Accept: application/json',
        'Content-Type: application/json',
        'x-api-version: ' . API_VERSION,
        'x-client-id: ' . $app_id,
        'x-client-secret: ' . $secret_key,
    ),
));

$response = curl_exec($curl);
$curl_error = curl_error($curl);
$http_code = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);

$success = false;
$error = '';
$error_code = 'invalid';
$transaction_id = '';
$cf_order_amount = null;
$cf_currency = '';
$payment_log = null;
$raw_hash = cashfree_event_raw_hash(is_string($response) ? $response : '');

if ($response === false || $curl_error !== '') {
    $error = 'Unable to verify payment with Cashfree';
} else {
    $cf_order = json_decode($response);
    $invoice = Capsule::table('tblinvoices')->where('id', $invoice_id)->first();
    $invoice_amount = $invoice ? $invoice->total : null;
    $invoice_currency = cashfree_invoice_currency_code($invoice_id);

    if (is_object($cf_order) && isset($cf_order->message) && !is_array($cf_order)) {
        $error = (string) $cf_order->message;
    } elseif (is_array($cf_order) && isset($cf_order[0]) && isset($cf_order[0]->payment_status)) {
        $payment_log = $cf_order[0];
        if ($cf_order[0]->payment_status == 'SUCCESS') {
            $transaction_id = (string) $cf_order[0]->cf_payment_id;
            $cf_order_amount = isset($cf_order[0]->order_amount)
                ? $cf_order[0]->order_amount
                : (isset($cf_order[0]->payment_amount) ? $cf_order[0]->payment_amount : null);
            $cf_currency = isset($cf_order[0]->payment_currency)
                ? (string) $cf_order[0]->payment_currency
                : (isset($cf_order[0]->order_currency) ? (string) $cf_order[0]->order_currency : '');

            if ($invoice_amount === null || !cashfree_amounts_match($invoice_amount, $cf_order_amount)) {
                $error = 'Amount Mismatched';
                $error_code = 'rejected_amount';
            } elseif (!cashfree_currencies_match($invoice_currency, $cf_currency)) {
                $error = 'Currency Mismatched';
                $error_code = 'rejected_currency';
            } else {
                $success = true;
            }
        } else {
            $error = isset($cf_order[0]->payment_message)
                ? (string) $cf_order[0]->payment_message
                : 'Payment not successful';
            $error_code = 'ignored_not_success';
        }
    } else {
        $error = 'Unable to process your order. Please contact support.';
        if ($http_code > 0) {
            $error .= ' (HTTP ' . $http_code . ')';
        }
    }
}

if ($success === true) {
    $outcome = cashfree_apply_invoice_payment_once(
        $invoice_id,
        $transaction_id,
        $cf_order_amount,
        $gateway_module_name
    );
    $logCode = cashfree_outcome_log_code($outcome, 'payment');
    cashfree_ledger_record(array(
        'order_id' => $cashfree_order_id,
        'cf_payment_id' => $transaction_id,
        'invoice_id' => $invoice_id,
        'amount' => $cf_order_amount,
        'currency' => $cf_currency,
        'event_type' => 'RETURN_SUCCESS',
        'verify_result' => 'ok',
        'credit_outcome' => $logCode,
        'source' => 'return',
        'raw_hash' => $raw_hash,
    ));
    $logPayload = is_object($payment_log) ? (array) $payment_log : array();
    $logPayload['code'] = $logCode;
    $logPayload['order_id'] = $cashfree_order_id;
    $logPayload['transaction_id'] = $transaction_id;
    $logPayload['outcome'] = $outcome;
    logTransaction($gateway_params['name'], $logPayload, 'Cashfree ' . $logCode);
    header('Location: ' . $system_url . 'viewinvoice.php?id=' . $invoice_id . '&paymentsuccess=true');
    exit;
}

cashfree_ledger_record(array(
    'order_id' => $cashfree_order_id,
    'cf_payment_id' => $transaction_id,
    'invoice_id' => $invoice_id,
    'amount' => $cf_order_amount,
    'currency' => $cf_currency,
    'event_type' => 'RETURN_FAIL',
    'verify_result' => $error_code,
    'credit_outcome' => $error_code,
    'source' => 'return',
    'raw_hash' => $raw_hash,
));

logTransaction(
    $gateway_params['name'],
    array(
        'code' => cashfree_gateway_log_code($error_code),
        'order_id' => $cashfree_order_id,
        'error' => $error,
    ),
    'Cashfree ' . cashfree_gateway_log_code($error_code)
);

header('Location: ' . $system_url . 'viewinvoice.php?id=' . $invoice_id . '&paymentfailed=true');
exit;
