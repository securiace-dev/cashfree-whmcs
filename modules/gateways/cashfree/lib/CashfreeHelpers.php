<?php
/**
 * Pure helpers for Cashfree WHMCS gateway (testable without WHMCS bootstrap).
 */

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
            if (!cashfree_verify_webhook_signature($signature, $timestamp, $rawBody, $secretKey)) {
                return array('ok' => false, 'error' => 'Invalid webhook signature');
            }
            $payload = json_decode($rawBody, true);
            if (!is_array($payload)) {
                return array('ok' => false, 'error' => 'Invalid webhook JSON');
            }
            return cashfree_normalize_modern_payload($payload);
        }

        if (!empty($post['orderId']) && isset($post['signature'])) {
            if (!cashfree_verify_legacy_signature($post, $secretKey)) {
                return array('ok' => false, 'error' => 'Invalid legacy signature');
            }
            return cashfree_normalize_legacy_post($post);
        }

        return array('ok' => false, 'error' => 'Unrecognized webhook payload');
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

        $successTypes = array(
            'PAYMENT_SUCCESS_WEBHOOK',
            'PAYMENT_SUCCESS',
            'PAYMENT_CHARGES_WEBHOOK',
        );
        $isSuccessEvent = in_array($type, $successTypes, true) || $paymentStatus === 'SUCCESS';

        if ($orderId === '' || $transactionId === '') {
            return array('ok' => false, 'error' => 'Missing order or payment id in webhook');
        }

        return array(
            'ok' => true,
            'event' => array(
                'order_id' => $orderId,
                'transaction_id' => $transactionId,
                'amount' => $orderAmount,
                'currency' => $currency,
                'payment_status' => $paymentStatus !== '' ? $paymentStatus : ($isSuccessEvent ? 'SUCCESS' : 'UNKNOWN'),
                'is_success' => ($paymentStatus === 'SUCCESS' || ($isSuccessEvent && $paymentStatus === '')),
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
                'amount' => isset($post['orderAmount']) ? $post['orderAmount'] : null,
                'currency' => '',
                'payment_status' => $txStatus,
                'is_success' => ($txStatus === 'SUCCESS'),
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
