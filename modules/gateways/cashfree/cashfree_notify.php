<?php
/**
 * Cashfree server-to-server notify / webhook handler.
 *
 * Supports:
 * - Modern PG JSON webhooks (x-webhook-signature / x-webhook-timestamp)
 * - Legacy form-encoded notify_url payloads (orderId + signature body field)
 *
 * Credits invoices only after signature verification, SUCCESS status,
 * invoice validation, amount + currency match. Never force-marks invoices Unpaid.
 * Idempotent with browser return via cashfree_apply_invoice_payment_once().
 * Refunds are ledger-noted only (never auto-debited).
 *
 * Body size is capped (see CASHFREE_WEBHOOK_MAX_BODY_BYTES) before php://input
 * is fully buffered, to avoid attacker-driven memory exhaustion on this public URL.
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/lib/CashfreeHelpers.php';
require_once __DIR__ . '/lib/CashfreeLedger.php';

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
     * @param string $logCode
     * @return void
     */
    function cashfree_notify_fail($status, $message, $logContext = null, $gatewayName = null, $logCode = 'invalid')
    {
        if ($gatewayName !== null && $logContext !== null && function_exists('logTransaction')) {
            if (!is_array($logContext)) {
                $logContext = array('detail' => $logContext);
            }
            $logContext['code'] = cashfree_gateway_log_code($logCode);
            logTransaction($gatewayName, $logContext, 'Cashfree ' . cashfree_gateway_log_code($logCode));
        }
        http_response_code((int) $status);
        echo $message;
        exit;
    }
}

if (!function_exists('cashfree_notify_ok')) {
    /**
     * Idempotent success response for Cashfree (always 200 body OK).
     *
     * @return void
     */
    function cashfree_notify_ok()
    {
        http_response_code(200);
        echo 'OK';
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
        $oversizeCode = (isset($lengthCheck['http_status']) && (int) $lengthCheck['http_status'] === 413)
            ? 'rejected_oversize'
            : 'invalid';
        cashfree_notify_fail(
            isset($lengthCheck['http_status']) ? $lengthCheck['http_status'] : 413,
            isset($lengthCheck['error']) ? $lengthCheck['error'] : 'Request body too large',
            array(
                'error' => isset($lengthCheck['error']) ? $lengthCheck['error'] : 'Request body too large',
                'content_length' => $contentLength,
                'max_bytes' => cashfree_webhook_max_body_bytes(),
            ),
            $gateway_name,
            $oversizeCode
        );
    }

    $read = cashfree_read_webhook_raw_body();
    if (!$read['ok']) {
        $oversizeCode = (isset($read['http_status']) && (int) $read['http_status'] === 413)
            ? 'rejected_oversize'
            : 'invalid';
        cashfree_notify_fail(
            isset($read['http_status']) ? $read['http_status'] : 413,
            isset($read['error']) ? $read['error'] : 'Request body too large',
            array(
                'error' => isset($read['error']) ? $read['error'] : 'Request body too large',
                'max_bytes' => cashfree_webhook_max_body_bytes(),
            ),
            $gateway_name,
            $oversizeCode
        );
    }
    $raw_body = $read['body'];
    $raw_hash = cashfree_event_raw_hash($raw_body);

    $parsed = cashfree_parse_webhook_event($raw_body, $_POST, $_SERVER, $secret_key);
    if (!$parsed['ok']) {
        $failCode = isset($parsed['code']) ? (string) $parsed['code'] : 'invalid';
        cashfree_ledger_record(array(
            'order_id' => '',
            'cf_payment_id' => '',
            'invoice_id' => 0,
            'event_type' => 'PARSE_FAIL',
            'verify_result' => $failCode,
            'credit_outcome' => $failCode,
            'source' => 'notify',
            'raw_hash' => $raw_hash,
        ));
        cashfree_notify_fail(
            400,
            'Invalid webhook',
            array(
                'error' => $parsed['error'],
                'content_type' => isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '',
            ),
            $gateway_name,
            $failCode
        );
    }

    $event = $parsed['event'];

    // Refund webhooks: verify + ledger note only (never auto-debit).
    if (!empty($event['is_refund'])) {
        $invoice_id = cashfree_extract_invoice_id($event['order_id']);
        $invoice_id_num = $invoice_id !== null ? (int) $invoice_id : 0;
        if (empty($event['refund_ok'])) {
            logTransaction($gateway_name, array(
                'code' => cashfree_gateway_log_code('ignored_not_success'),
                'order_id' => $event['order_id'],
                'refund_id' => isset($event['refund_id']) ? $event['refund_id'] : '',
                'payment_status' => $event['payment_status'],
            ), 'Cashfree ' . cashfree_gateway_log_code('ignored_not_success'));
            cashfree_notify_ok();
        }
        $refundOutcome = cashfree_note_refund_once($event, $invoice_id_num, $raw_hash, 'notify');
        $refundCode = cashfree_outcome_log_code($refundOutcome, 'refund');
        logTransaction($gateway_name, array(
            'code' => $refundCode,
            'order_id' => $event['order_id'],
            'transaction_id' => $event['transaction_id'],
            'refund_id' => isset($event['refund_id']) ? $event['refund_id'] : '',
            'outcome' => $refundOutcome,
        ), 'Cashfree ' . $refundCode);
        cashfree_notify_ok();
    }

    if (empty($event['is_success'])) {
        cashfree_ledger_record(array(
            'order_id' => $event['order_id'],
            'cf_payment_id' => $event['transaction_id'],
            'invoice_id' => 0,
            'amount' => isset($event['amount']) ? $event['amount'] : null,
            'currency' => isset($event['currency']) ? $event['currency'] : '',
            'event_type' => $event['raw_type'],
            'verify_result' => 'ignored_not_success',
            'credit_outcome' => 'ignored_not_success',
            'source' => 'notify',
            'raw_hash' => $raw_hash,
        ));
        logTransaction($gateway_name, array(
            'code' => cashfree_gateway_log_code('ignored_not_success'),
            'order_id' => $event['order_id'],
            'payment_status' => $event['payment_status'],
            'raw_type' => $event['raw_type'],
            'format' => $event['format'],
        ), 'Cashfree ' . cashfree_gateway_log_code('ignored_not_success'));
        cashfree_notify_ok();
    }

    $invoice_id = cashfree_extract_invoice_id($event['order_id']);
    if ($invoice_id === null) {
        cashfree_notify_fail(400, 'Invalid order id', $event, $gateway_name, 'invalid');
    }

    $invoice_id = checkCbInvoiceID($invoice_id, $gateway_name);

    $invoice_details = Capsule::table('tblinvoices')->where('id', $invoice_id)->first();
    if (!$invoice_details) {
        cashfree_notify_fail(404, 'Invoice not found', $event, $gateway_name, 'invalid');
    }

    $transaction_id = (string) $event['transaction_id'];
    $invoice_currency = cashfree_invoice_currency_code($invoice_id);

    // Fast path: already recorded (covers post-settle duplicate webhooks).
    if (Capsule::table('tblaccounts')->where('transid', $transaction_id)->exists()) {
        cashfree_ledger_record(array(
            'order_id' => $event['order_id'],
            'cf_payment_id' => $transaction_id,
            'invoice_id' => $invoice_id,
            'amount' => isset($event['amount']) ? $event['amount'] : null,
            'currency' => isset($event['currency']) ? $event['currency'] : $invoice_currency,
            'event_type' => $event['raw_type'],
            'verify_result' => 'ok',
            'credit_outcome' => 'duplicate',
            'source' => 'notify',
            'raw_hash' => $raw_hash,
        ));
        logTransaction($gateway_name, array(
            'code' => cashfree_gateway_log_code('duplicate'),
            'order_id' => $event['order_id'],
            'transaction_id' => $transaction_id,
        ), 'Cashfree ' . cashfree_gateway_log_code('duplicate'));
        cashfree_notify_ok();
    }

    // Invoice already settled by another path/gateway — do not stack another credit.
    if ($invoice_details->status === 'Paid') {
        cashfree_ledger_record(array(
            'order_id' => $event['order_id'],
            'cf_payment_id' => $transaction_id,
            'invoice_id' => $invoice_id,
            'amount' => isset($event['amount']) ? $event['amount'] : null,
            'currency' => isset($event['currency']) ? $event['currency'] : $invoice_currency,
            'event_type' => $event['raw_type'],
            'verify_result' => 'ok',
            'credit_outcome' => 'duplicate',
            'source' => 'notify',
            'raw_hash' => $raw_hash,
        ));
        logTransaction($gateway_name, array(
            'code' => cashfree_gateway_log_code('duplicate'),
            'order_id' => $event['order_id'],
            'transaction_id' => $transaction_id,
            'invoice_status' => 'Paid',
        ), 'Cashfree ' . cashfree_gateway_log_code('duplicate'));
        cashfree_notify_ok();
    }

    if (!cashfree_amounts_match($invoice_details->total, $event['amount'])) {
        cashfree_ledger_record(array(
            'order_id' => $event['order_id'],
            'cf_payment_id' => $transaction_id,
            'invoice_id' => $invoice_id,
            'amount' => isset($event['amount']) ? $event['amount'] : null,
            'currency' => isset($event['currency']) ? $event['currency'] : $invoice_currency,
            'event_type' => $event['raw_type'],
            'verify_result' => 'rejected_amount',
            'credit_outcome' => 'rejected_amount',
            'source' => 'notify',
            'raw_hash' => $raw_hash,
        ));
        cashfree_notify_fail(
            400,
            'Amount mismatch',
            array(
                'event' => $event,
                'invoice_total' => $invoice_details->total,
            ),
            $gateway_name,
            'rejected_amount'
        );
    }

    $paid_currency = isset($event['currency']) ? (string) $event['currency'] : '';
    if (!cashfree_currencies_match($invoice_currency, $paid_currency)) {
        cashfree_ledger_record(array(
            'order_id' => $event['order_id'],
            'cf_payment_id' => $transaction_id,
            'invoice_id' => $invoice_id,
            'amount' => isset($event['amount']) ? $event['amount'] : null,
            'currency' => $paid_currency,
            'event_type' => $event['raw_type'],
            'verify_result' => 'rejected_currency',
            'credit_outcome' => 'rejected_currency',
            'source' => 'notify',
            'raw_hash' => $raw_hash,
        ));
        cashfree_notify_fail(
            400,
            'Currency mismatch',
            array(
                'event' => $event,
                'invoice_currency' => $invoice_currency,
                'paid_currency' => $paid_currency,
            ),
            $gateway_name,
            'rejected_currency'
        );
    }

    $outcome = cashfree_apply_invoice_payment_once(
        $invoice_id,
        $transaction_id,
        $invoice_details->total,
        $gateway_module_name
    );

    $logCode = cashfree_outcome_log_code($outcome, 'payment');
    cashfree_ledger_record(array(
        'order_id' => $event['order_id'],
        'cf_payment_id' => $transaction_id,
        'invoice_id' => $invoice_id,
        'amount' => $invoice_details->total,
        'currency' => $invoice_currency !== '' ? $invoice_currency : $paid_currency,
        'event_type' => $event['raw_type'],
        'verify_result' => 'ok',
        'credit_outcome' => $logCode,
        'source' => 'notify',
        'raw_hash' => $raw_hash,
    ));

    logTransaction($gateway_name, array(
        'code' => $logCode,
        'order_id' => $event['order_id'],
        'transaction_id' => $transaction_id,
        'format' => $event['format'],
        'payment_status' => $event['payment_status'],
        'outcome' => $outcome,
    ), 'Cashfree ' . $logCode);

    cashfree_notify_ok();
} catch (\Throwable $e) {
    // Prefer a controlled 400 over an uncaught fatal/exception on this public URL
    // (CVE-2026-109619 hardening: avoid attacker-reachable fatal paths where possible).
    $logName = (isset($gateway_params) && is_array($gateway_params) && !empty($gateway_params['name']))
        ? $gateway_params['name']
        : null;
    if ($logName !== null && function_exists('logTransaction')) {
        logTransaction($logName, array(
            'code' => cashfree_gateway_log_code('invalid'),
            'error' => 'Unhandled webhook exception',
            'exception_class' => get_class($e),
            'exception_message' => $e->getMessage(),
        ), 'Cashfree ' . cashfree_gateway_log_code('invalid'));
    }
    http_response_code(400);
    echo 'Webhook processing error';
    exit;
}
