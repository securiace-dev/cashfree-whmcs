<?php
/**
 * WHMCS Cashfree Payment Return / Callback
 *
 * Verifies payment via Cashfree Orders API, then applies invoice payment
 * when status is SUCCESS and amounts match. Idempotent on duplicate trans IDs
 * (advisory lock shared with cashfree_notify.php).
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/lib/CashfreeHelpers.php';

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
    logTransaction($gateway_params['name'], $_REQUEST, 'Return rejected — invalid order id');
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
$transaction_id = '';
$cf_order_amount = null;
$payment_log = null;

if ($response === false || $curl_error !== '') {
    $error = 'Unable to verify payment with Cashfree';
} else {
    $cf_order = json_decode($response);
    $invoice = Capsule::table('tblinvoices')->where('id', $invoice_id)->first();
    $invoice_amount = $invoice ? $invoice->total : null;

    if (is_object($cf_order) && isset($cf_order->message) && !is_array($cf_order)) {
        $error = (string) $cf_order->message;
    } elseif (is_array($cf_order) && isset($cf_order[0]) && isset($cf_order[0]->payment_status)) {
        $payment_log = $cf_order[0];
        if ($cf_order[0]->payment_status == 'SUCCESS') {
            $transaction_id = (string) $cf_order[0]->cf_payment_id;
            $cf_order_amount = $cf_order[0]->order_amount;
            if ($invoice_amount !== null && cashfree_amounts_match($invoice_amount, $cf_order_amount)) {
                $success = true;
            } else {
                $error = 'Amount Mismatched';
            }
        } else {
            $error = isset($cf_order[0]->payment_message)
                ? (string) $cf_order[0]->payment_message
                : 'Payment not successful';
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
    if ($outcome === 'applied') {
        logTransaction($gateway_params['name'], $payment_log, 'Successful');
    } elseif ($outcome === 'duplicate' || $outcome === 'lock_busy') {
        logTransaction(
            $gateway_params['name'],
            array(
                'order_id' => $cashfree_order_id,
                'transaction_id' => $transaction_id,
                'outcome' => $outcome,
            ),
            'Return idempotent skip — already recorded'
        );
    } else {
        logTransaction(
            $gateway_params['name'],
            array(
                'order_id' => $cashfree_order_id,
                'transaction_id' => $transaction_id,
                'outcome' => $outcome,
            ),
            'Return credit skipped — invalid state'
        );
    }
    header('Location: ' . $system_url . 'viewinvoice.php?id=' . $invoice_id . '&paymentsuccess=true');
    exit;
}

logTransaction(
    $gateway_params['name'],
    array(
        'order_id' => $cashfree_order_id,
        'error' => $error,
    ),
    'Unsuccessful — ' . $error . '. Please check Cashfree dashboard for order id: ' . $cashfree_order_id
);

header('Location: ' . $system_url . 'viewinvoice.php?id=' . $invoice_id . '&paymentfailed=true');
exit;
