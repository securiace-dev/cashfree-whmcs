<?php
define('CASHFREE_PLUGIN_VERSION', '2.4.4', true);
define('API_VERSION', '2022-09-01');

require_once __DIR__ . '/cashfree/lib/CashfreeHelpers.php';

/**
 * WHMCS Cashfree Payment Gateway Module
 */
if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Define module related meta data.
 * @return array
 */
function cashfree_MetaData()
{
    return array(
        'DisplayName' => 'Cashfree',
        // WHMCS gateway module API version (not the plugin release version).
        'APIVersion' => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    );
}

/**
 * Define Cashfree gateway configuration options.
 * @return array
 */
function cashfree_config()
{
    return array(
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'Cashfree',
        ),
        'appId' => array(
            'FriendlyName' => 'App Id',
            'Type' => 'text',
            'Size' => '50',
            'Description' => 'Cashfree "App Id". Available <a href="https://www.cashfree.com/" target="_blank">HERE</a>',
        ),
        'secretKey' => array(
            'FriendlyName' => 'Secret Key',
            'Type' => 'password',
            'Size' => '50',
            'Description' => 'Cashfree "Secret Key" from the Merchant Dashboard API Keys page',
        ),
        'themeLogo' => array(
            'FriendlyName' => 'Logo URL',
            'Type' => 'text',
            'Size' => '50',
            'Description' => 'ONLY "http<strong>s</strong>://"; else leave blank.<br/><small>Size: 128px X 128px (or higher) | File Type: png/jpg/gif/ico</small>',
        ),
        'themeColor' => array(
            'FriendlyName' => 'Theme Color',
            'Type' => 'text',
            'Size' => '15',
            'Default' => '#15A4D3',
            'Description' => 'Reserved for future checkout theming',
        ),
        'testMode' => array(
            'FriendlyName' => 'Test Mode',
            'Type' => 'yesno',
            'Description' => 'Tick to enable test mode',
        ),
        'checkoutPopUp' => array(
            'FriendlyName' => 'Enable Popup Checkout',
            'Type' => 'yesno',
            'Description' => 'Tick to enable popup checkout',
        ),
    );
}

/**
 * Payment link HTML for the invoice.
 *
 * @param array $params Payment Gateway Module Parameters
 * @return string
 */
function cashfree_link($params)
{
    $invoice_id = $params['invoiceid'];
    $system_url = rtrim($params['systemurl'], '/') . '/';
    $module_name = $params['paymentmethod'];

    if (isset($params['amount']) && (float) $params['amount'] <= 0) {
        return '<p>Unable to pay a zero-amount invoice through Cashfree.</p>';
    }

    // Prefer Capsule when available (WHMCS 7+); avoid deprecated mysql_* APIs.
    if (class_exists('WHMCS\\Database\\Capsule')) {
        $paid = \WHMCS\Database\Capsule::table('tblinvoices')
            ->where('id', $invoice_id)
            ->where('status', 'Paid')
            ->exists();
        if ($paid) {
            return '<p>This invoice is already paid.</p>';
        }
    }

    $cf_request = array(
        'orderId' => 'cf' . time() . '_' . $invoice_id,
        'returnUrl' => $system_url . 'modules/gateways/cashfree/' . $module_name . '.php?order_id={order_id}',
        'notifyUrl' => $system_url . 'modules/gateways/cashfree/' . $module_name . '_notify.php',
        'mode' => ($params['testMode'] == 'on') ? 'sandbox' : 'production',
    );

    $callback_url = $system_url . 'modules/gateways/cashfree/' . $module_name . '.php?order_id=' . $cf_request['orderId'];
    $payment_session_id = generatePaymentSession($cf_request, $params);

    if ($payment_session_id === null || $payment_session_id === '') {
        return '<p>Unable to create your order. Please contact support.</p>';
    }

    $use_popup = (!empty($params['checkoutPopUp']) && $params['checkoutPopUp'] == 'on');
    return generateHtmlOutput($cf_request, $payment_session_id, $callback_url, $use_popup);
}

/**
 * Build invoice-embedded checkout controls (fragment, not a full HTML document).
 *
 * @param array $cf_request
 * @param string $payment_session_id
 * @param string $callback_url
 * @param bool $use_popup
 * @return string
 */
function generateHtmlOutput($cf_request, $payment_session_id, $callback_url, $use_popup)
{
    $mode = cashfree_js_string($cf_request['mode']);
    $session = cashfree_js_string($payment_session_id);
    $callback = cashfree_js_string($callback_url);
    $popup_js = $use_popup ? 'true' : 'false';

    return <<<EOT
<script src="https://sdk.cashfree.com/js/v3/cashfree.js"></script>
<form method="post" action="#" id="cashfree-pay-form" onsubmit="return false;">
    <input type="submit" id="renderBtn" value="Pay Now" />
</form>
<script>
(function () {
    var cashfree = Cashfree({ mode: "{$mode}" });
    var usePopup = {$popup_js};
    function startCheckout() {
        var opts = {
            paymentSessionId: "{$session}",
            platformName: "wh"
        };
        if (usePopup) {
            opts.redirectTarget = "_modal";
            cashfree.checkout(opts).then(function () {
                window.location.href = "{$callback}";
            });
        } else {
            cashfree.checkout(opts);
        }
    }
    var btn = document.getElementById("renderBtn");
    if (btn) {
        btn.addEventListener("click", function (e) {
            e.preventDefault();
            startCheckout();
        });
    }
})();
</script>
EOT;
}

/**
 * @param array $cf_request
 * @param array $params
 * @return string|null
 */
function generatePaymentSession($cf_request, $params)
{
    $api_endpoint = ($params['testMode'] == 'on')
        ? 'https://sandbox.cashfree.com/pg/orders'
        : 'https://api.cashfree.com/pg/orders';

    $get_cashfree_order_url = $api_endpoint . '/' . rawurlencode($cf_request['orderId']);
    $get_order = getCfOrder($params, $get_cashfree_order_url);

    if (
        $get_order
        && isset($get_order->order_status)
        && $get_order->order_status == 'ACTIVE'
        && isset($get_order->order_amount, $get_order->order_currency, $get_order->payment_session_id)
        && cashfree_amounts_match($params['amount'], $get_order->order_amount)
        && $get_order->order_currency == $params['currency']
    ) {
        return $get_order->payment_session_id;
    }

    return createCashfreeOrder($cf_request, $params, $api_endpoint);
}

/**
 * @param array $params
 * @param string $curl_url
 * @return object|null
 */
function getCfOrder($params, $curl_url)
{
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => $curl_url,
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
            'x-client-id: ' . $params['appId'],
            'x-client-secret: ' . $params['secretKey'],
        ),
    ));

    $response = curl_exec($curl);
    $err = curl_error($curl);
    curl_close($curl);

    if ($err || $response === false) {
        return null;
    }

    $decoded = json_decode($response);
    return is_object($decoded) ? $decoded : null;
}

/**
 * @param array $cf_request
 * @param array $params
 * @param string $api_endpoint
 * @return string|null
 */
function createCashfreeOrder($cf_request, $params, $api_endpoint)
{
    $client_id = isset($params['clientdetails']['userid'])
        ? (string) $params['clientdetails']['userid']
        : (isset($params['clientdetails']['id']) ? (string) $params['clientdetails']['id'] : '0');

    $customer_details = array(
        'customer_id' => 'whmcs_' . $client_id,
        'customer_email' => $params['clientdetails']['email'],
        'customer_name' => $params['clientdetails']['firstname'] . ' ' . $params['clientdetails']['lastname'],
        'customer_phone' => cashfree_normalize_phone(
            isset($params['clientdetails']['phonenumber']) ? $params['clientdetails']['phonenumber'] : ''
        ),
    );

    $order_meta = array(
        'return_url' => $cf_request['returnUrl'],
        'notify_url' => $cf_request['notifyUrl'],
    );

    $request = array(
        'customer_details' => $customer_details,
        'order_id' => $cf_request['orderId'],
        'order_amount' => (float) $params['amount'],
        'order_currency' => $params['currency'],
        'order_note' => 'WHMCS Order',
        'order_meta' => $order_meta,
    );

    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => $api_endpoint,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => json_encode($request),
        CURLOPT_HTTPHEADER => array(
            'Accept: application/json',
            'Content-Type: application/json',
            'x-api-version: ' . API_VERSION,
            'x-client-id: ' . $params['appId'],
            'x-client-secret: ' . $params['secretKey'],
        ),
    ));

    $response = curl_exec($curl);
    $httpcode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($httpcode != 200) {
        return null;
    }

    $cf_order = json_decode($response);
    if ($cf_order && !empty($cf_order->payment_session_id)) {
        return $cf_order->payment_session_id;
    }

    return null;
}
