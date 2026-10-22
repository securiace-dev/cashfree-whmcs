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
 * Idempotent with browser return via cashfree_apply_invoice_payment_once().
 *
 * Body size is capped (see CASHFREE_WEBHOOK_MAX_BODY_BYTES) before php://input
 * is fully buffered, to avoid attacker-driven memory exhaustion on this public URL.
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/lib/CashfreeHelpers.php';

use WHMCS\Database\Capsule;

$gateway_module_name = 'cashfree';

if (!function_exists('cashfree_notify_fail')) {
    /**
     * Emit an HTTP error for the notify endpoint and stop.
     *
     * @param int $status
     * @param string $message
     * @param array|null $logContext
     * @param string|null $gatewayName
     * @return void
     */
    function cashfree_notify_fail($status, $message, $logContext = null, $gatewayName = null)
    {
        if ($gatewayName !== null && $logContext !== null && function_exists('logTransaction')) {
            logTransaction($gatewayName, $logContext, 'Webhook rejected');
        }
        http_response_code((int) $status);
        echo $message;
        exit;
    }
}

try {
    $gateway_params = getGatewayVariables($gateway_module_name);

    if (empty($gateway_params['type'])) {
        cashfree_notify_fail(503, 'Module Not Activated');
    }

    $gateway_name = $gateway_params['name'];
    $secret_key = isset($gateway_params['secretKey']) ? (string) $gateway_params['secretKey'] : '';

    $contentLength = isset($_SERVER['CONTENT_LENGTH']) ? $_SERVER['CONTENT_LENGTH'] : null;
    $lengthCheck = cashfree_reject_oversized_content_length($contentLength);
    if (!$lengthCheck['ok']) {
        cashfree_notify_fail(
            isset($lengthCheck['http_status']) ? $lengthCheck['http_status'] : 413,
            isset($lengthCheck['error']) ? $lengthCheck['error'] : 'Request body too large',
            array(
                'error' => isset($lengthCheck['error']) ? $lengthCheck['error'] : 'Request body too large',
                'content_length' => $contentLength,
                'max_bytes' => cashfree_webhook_max_body_bytes(),
            ),
            $gateway_name
        );
    }

    $read = cashfree_read_webhook_raw_body();
    if (!$read['ok']) {
        cashfree_notify_fail(
            isset($read['http_status']) ? $read['http_status'] : 413,
            isset($read['error']) ? $read['error'] : 'Request body too large',
            array(
                'error' => isset($read['error']) ? $read['error'] : 'Request body too large',
                'max_bytes' => cashfree_webhook_max_body_bytes(),
            ),
            $gateway_name
        );
    }
    $raw_body = $read['body'];

    $parsed = cashfree_parse_webhook_event($raw_body, $_POST, $_SERVER, $secret_key);
    if (!$parsed['ok']) {
        cashfree_notify_fail(
            400,
            'Invalid webhook',
            array(
                'error' => $parsed['error'],
                'content_type' => isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '',
            ),
            $gateway_name
        );
    }

    $event = $parsed['event'];

    if (empty($event['is_success'])) {
        logTransaction($gateway_name, array(
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
        cashfree_notify_fail(400, 'Invalid order id', $event, $gateway_name);
    }

    $invoice_id = checkCbInvoiceID($invoice_id, $gateway_name);

    $invoice_details = Capsule::table('tblinvoices')->where('id', $invoice_id)->first();
    if (!$invoice_details) {
        cashfree_notify_fail(404, 'Invoice not found', $event, $gateway_name);
    }

    $transaction_id = (string) $event['transaction_id'];

    // Fast path: already recorded (covers post-settle duplicate webhooks).
    if (Capsule::table('tblaccounts')->where('transid', $transaction_id)->exists()) {
        http_response_code(200);
        echo 'OK';
        exit;
    }

    // Invoice already settled by another path/gateway — do not stack another credit.
    // Concurrent return+notify while still Unpaid is handled by the locked apply helper.
    if ($invoice_details->status === 'Paid') {
        http_response_code(200);
        echo 'OK';
        exit;
    }

    if (!cashfree_amounts_match($invoice_details->total, $event['amount'])) {
        cashfree_notify_fail(
            400,
            'Amount mismatch',
            array(
                'event' => $event,
                'invoice_total' => $invoice_details->total,
            ),
            $gateway_name
        );
    }

    $outcome = cashfree_apply_invoice_payment_once(
        $invoice_id,
        $transaction_id,
        $invoice_details->total,
        $gateway_module_name
    );

    if ($outcome === 'applied') {
        logTransaction($gateway_name, array(
            'order_id' => $event['order_id'],
            'transaction_id' => $transaction_id,
            'format' => $event['format'],
            'payment_status' => $event['payment_status'],
        ), 'Webhook payment applied');
    } else {
        logTransaction($gateway_name, array(
            'order_id' => $event['order_id'],
            'transaction_id' => $transaction_id,
            'outcome' => $outcome,
            'format' => $event['format'],
        ), 'Webhook idempotent skip — ' . $outcome);
    }

    http_response_code(200);
    echo 'OK';
    exit;
} catch (\Throwable $e) {
    // Prefer a controlled 400 over an uncaught fatal/exception on this public URL
    // (CVE-2026-109619 hardening: avoid attacker-reachable fatal paths where possible).
    $logName = (isset($gateway_params) && is_array($gateway_params) && !empty($gateway_params['name']))
        ? $gateway_params['name']
        : null;
    if ($logName !== null && function_exists('logTransaction')) {
        logTransaction($logName, array(
            'error' => 'Unhandled webhook exception',
            'exception_class' => get_class($e),
            'exception_message' => $e->getMessage(),
        ), 'Webhook rejected');
    }
    http_response_code(400);
    echo 'Webhook processing error';
    exit;
}
