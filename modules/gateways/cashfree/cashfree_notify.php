<?php
/**
 * Cashfree server-to-server notify / webhook handler.
 *
 * Supports:
 * - Modern PG JSON webhooks (x-webhook-signature / x-webhook-timestamp)
 * - Legacy form-encoded notify_url payloads (orderId + signature body field)
 *
 * Credits invoices only after signature verification, SUCCESS status,
 * invoice validation, and amount match. Never force-marks invoices Unpaid.
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/lib/CashfreeHelpers.php';

use WHMCS\Database\Capsule;

$gateway_module_name = 'cashfree';
$gateway_params = getGatewayVariables($gateway_module_name);

if (empty($gateway_params['type'])) {
    http_response_code(503);
    echo 'Module Not Activated';
    exit;
}

$secret_key = isset($gateway_params['secretKey']) ? (string) $gateway_params['secretKey'] : '';
$raw_body = file_get_contents('php://input');
if ($raw_body === false) {
    $raw_body = '';
}

$parsed = cashfree_parse_webhook_event($raw_body, $_POST, $_SERVER, $secret_key);
if (!$parsed['ok']) {
    logTransaction($gateway_params['name'], array(
        'error' => $parsed['error'],
        'content_type' => isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '',
    ), 'Webhook rejected');
    http_response_code(400);
    echo 'Invalid webhook';
    exit;
}

$event = $parsed['event'];

if (empty($event['is_success'])) {
    logTransaction($gateway_params['name'], array(
        'order_id' => $event['order_id'],
        'payment_status' => $event['payment_status'],
        'raw_type' => $event['raw_type'],
        'format' => $event['format'],
    ), 'Webhook ignored — payment not successful');
    http_response_code(200);
    echo 'OK';
    exit;
}

$invoice_id = cashfree_extract_invoice_id($event['order_id']);
if ($invoice_id === null) {
    logTransaction($gateway_params['name'], $event, 'Webhook rejected — invalid order id');
    http_response_code(400);
    echo 'Invalid order id';
    exit;
}

$invoice_id = checkCbInvoiceID($invoice_id, $gateway_params['name']);

$invoice_details = Capsule::table('tblinvoices')->where('id', $invoice_id)->first();
if (!$invoice_details) {
    logTransaction($gateway_params['name'], $event, 'Webhook rejected — invoice not found');
    http_response_code(404);
    echo 'Invoice not found';
    exit;
}

if ($invoice_details->status === 'Paid') {
    http_response_code(200);
    echo 'OK';
    exit;
}

if (!cashfree_amounts_match($invoice_details->total, $event['amount'])) {
    logTransaction($gateway_params['name'], array(
        'event' => $event,
        'invoice_total' => $invoice_details->total,
    ), 'Webhook rejected — amount mismatch');
    http_response_code(400);
    echo 'Amount mismatch';
    exit;
}

$transaction_id = (string) $event['transaction_id'];
checkCbTransID($transaction_id);

addInvoicePayment(
    $invoice_id,
    $transaction_id,
    $invoice_details->total,
    0,
    $gateway_module_name
);

logTransaction($gateway_params['name'], array(
    'order_id' => $event['order_id'],
    'transaction_id' => $transaction_id,
    'format' => $event['format'],
    'payment_status' => $event['payment_status'],
), 'Webhook payment applied');

http_response_code(200);
echo 'OK';
exit;
