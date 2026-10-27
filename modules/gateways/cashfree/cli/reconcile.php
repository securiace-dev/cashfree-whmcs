<?php
/**
 * Cashfree reconciliation CLI (Dokploy-friendly).
 *
 * Usage (from WHMCS root):
 *   php -q modules/gateways/cashfree/cli/reconcile.php
 *   php -q modules/gateways/cashfree/cli/reconcile.php --days=14
 *   php -q modules/gateways/cashfree/cli/reconcile.php --apply
 *
 * Without --apply: dry-run only — prints drift, never credits invoices.
 * With --apply: credits only unmatched_success rows after operator flag
 * (still uses cashfree_apply_invoice_payment_once).
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$whmcsRoot = dirname(__DIR__, 4);
if (!is_file($whmcsRoot . '/init.php')) {
    // Repo checkout without WHMCS: allow pure self-test of argv parsing.
    $whmcsRoot = null;
}

$days = 7;
$apply = false;
foreach ($argv as $i => $arg) {
    if ($i === 0) {
        continue;
    }
    if ($arg === '--apply') {
        $apply = true;
        continue;
    }
    if (strpos($arg, '--days=') === 0) {
        $days = (int) substr($arg, 7);
        if ($days < 1) {
            $days = 7;
        }
        continue;
    }
    if ($arg === '--help' || $arg === '-h') {
        echo "Usage: php -q modules/gateways/cashfree/cli/reconcile.php [--days=N] [--apply]\n";
        echo "  Default is dry-run. --apply credits unmatched SUCCESS payments only.\n";
        exit(0);
    }
}

if ($whmcsRoot === null) {
    fwrite(STDERR, "WHMCS init.php not found — run from a WHMCS install (dry helpers-only exit).\n");
    echo json_encode(array(
        'ok' => true,
        'mode' => $apply ? 'apply' : 'dry-run',
        'days' => $days,
        'rows' => array(),
        'note' => 'no_whmcs_bootstrap',
    ), JSON_PRETTY_PRINT) . "\n";
    exit(0);
}

require_once $whmcsRoot . '/init.php';
require_once $whmcsRoot . '/includes/gatewayfunctions.php';
require_once $whmcsRoot . '/includes/invoicefunctions.php';
require_once dirname(__DIR__) . '/lib/CashfreeHelpers.php';
require_once dirname(__DIR__) . '/lib/CashfreeLedger.php';

use WHMCS\Database\Capsule;

if (!defined('API_VERSION')) {
    define('API_VERSION', '2022-09-01');
}

$gateway_module_name = 'cashfree';
$gateway_params = getGatewayVariables($gateway_module_name);
if (empty($gateway_params['type'])) {
    fwrite(STDERR, "Cashfree module not activated\n");
    exit(2);
}

cashfree_ledger_ensure_schema();

$api_endpoint = ($gateway_params['testMode'] == 'on')
    ? 'https://sandbox.cashfree.com/pg/orders'
    : 'https://api.cashfree.com/pg/orders';

$since = date('Y-m-d H:i:s', time() - ($days * 86400));

// Prefer ledger order ids; also scan recent unpaid invoices that may have cf* attempts
// via gateway log is heavy — use ledger + unpaid invoices with recent updates.
$orderIds = array();
try {
    $ledgerOrders = Capsule::table(cashfree_ledger_table_name())
        ->where('created_at', '>=', $since)
        ->where('order_id', 'like', 'cf%_%')
        ->distinct()
        ->pluck('order_id');
    foreach ($ledgerOrders as $oid) {
        $orderIds[(string) $oid] = true;
    }
} catch (\Throwable $e) {
    // table may be empty / missing on first run
}

// tblinvoices uses `date` (not updated_at) across WHMCS versions.
$sinceDate = date('Y-m-d', time() - ($days * 86400));
$unpaid = Capsule::table('tblinvoices')
    ->where('status', 'Unpaid')
    ->where('date', '>=', $sinceDate)
    ->orderBy('id', 'desc')
    ->limit(200)
    ->get(array('id', 'total', 'status', 'userid'));

$results = array();

foreach (array_keys($orderIds) as $orderId) {
    $invoiceId = cashfree_extract_invoice_id($orderId);
    if ($invoiceId === null) {
        continue;
    }
    $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
    if (!$invoice) {
        continue;
    }

    $orderUrl = $api_endpoint . '/' . rawurlencode($orderId);
    $paymentsUrl = $orderUrl . '/payments';

    $orderJson = cashfree_cli_cf_get($orderUrl, $gateway_params);
    $paymentsJson = cashfree_cli_cf_get($paymentsUrl, $gateway_params);

    $cfOrderStatus = '';
    if (is_object($orderJson) && isset($orderJson->order_status)) {
        $cfOrderStatus = (string) $orderJson->order_status;
    }

    $paymentSuccess = false;
    $cfPaymentId = '';
    $cfAmount = null;
    $cfCurrency = '';
    if (is_array($paymentsJson)) {
        foreach ($paymentsJson as $p) {
            if (is_object($p) && isset($p->payment_status) && strtoupper((string) $p->payment_status) === 'SUCCESS') {
                $paymentSuccess = true;
                $cfPaymentId = isset($p->cf_payment_id) ? (string) $p->cf_payment_id : '';
                $cfAmount = isset($p->order_amount) ? $p->order_amount : (isset($p->payment_amount) ? $p->payment_amount : null);
                $cfCurrency = isset($p->payment_currency)
                    ? (string) $p->payment_currency
                    : (isset($p->order_currency) ? (string) $p->order_currency : '');
                break;
            }
        }
    }

    $whmcsHasPayment = ($cfPaymentId !== '' && cashfree_ledger_payment_recorded($cfPaymentId));
    $drift = cashfree_recon_drift_label($invoice->status, $cfOrderStatus, $paymentSuccess, $whmcsHasPayment);

    $row = array(
        'order_id' => $orderId,
        'invoice_id' => (int) $invoiceId,
        'invoice_status' => $invoice->status,
        'cf_order_status' => $cfOrderStatus,
        'cf_payment_id' => $cfPaymentId,
        'drift' => $drift,
        'applied' => false,
    );

    if ($apply && $drift === 'unmatched_success' && $cfPaymentId !== '') {
        $invoiceCurrency = cashfree_invoice_currency_code($invoiceId);
        if (
            cashfree_amounts_match($invoice->total, $cfAmount)
            && cashfree_currencies_match($invoiceCurrency, $cfCurrency)
        ) {
            $outcome = cashfree_apply_invoice_payment_once(
                $invoiceId,
                $cfPaymentId,
                $invoice->total,
                $gateway_module_name
            );
            $row['applied'] = ($outcome === 'applied');
            $row['outcome'] = $outcome;
            cashfree_ledger_record(array(
                'order_id' => $orderId,
                'cf_payment_id' => $cfPaymentId,
                'invoice_id' => $invoiceId,
                'amount' => $invoice->total,
                'currency' => $invoiceCurrency,
                'event_type' => 'RECON_APPLY',
                'verify_result' => 'ok',
                'credit_outcome' => cashfree_outcome_log_code($outcome, 'payment'),
                'source' => 'cli',
                'raw_hash' => '',
            ));
        } else {
            $row['outcome'] = 'rejected_amount_or_currency';
            cashfree_ledger_record(array(
                'order_id' => $orderId,
                'cf_payment_id' => $cfPaymentId,
                'invoice_id' => $invoiceId,
                'amount' => $cfAmount,
                'currency' => $cfCurrency,
                'event_type' => 'RECON_SKIP',
                'verify_result' => 'rejected_amount_or_currency',
                'credit_outcome' => 'rejected_amount',
                'source' => 'cli',
                'raw_hash' => '',
            ));
        }
    } else {
        cashfree_ledger_record(array(
            'order_id' => $orderId,
            'cf_payment_id' => $cfPaymentId,
            'invoice_id' => $invoiceId,
            'amount' => $cfAmount,
            'currency' => $cfCurrency,
            'event_type' => 'RECON_SCAN',
            'verify_result' => $drift,
            'credit_outcome' => $drift,
            'source' => 'cli',
            'raw_hash' => '',
        ));
    }

    $results[] = $row;
}

// Surface recent ledger problems for operators even when no order scan hits.
$filters = array('rejected_amount', 'rejected_currency', 'rejected_sig', 'rejected_skew', 'lock_busy', 'unmatched_success', 'refund_missing_payment');
$problems = array();
try {
    $problems = Capsule::table(cashfree_ledger_table_name())
        ->where('created_at', '>=', $since)
        ->whereIn('credit_outcome', $filters)
        ->orderBy('id', 'desc')
        ->limit(100)
        ->get();
} catch (\Throwable $e) {
    $problems = array();
}

echo json_encode(array(
    'ok' => true,
    'mode' => $apply ? 'apply' : 'dry-run',
    'days' => $days,
    'scanned_orders' => count($results),
    'rows' => $results,
    'recent_problems' => $problems,
    'unpaid_sample' => count($unpaid),
), JSON_PRETTY_PRINT) . "\n";
exit(0);

/**
 * @param string $url
 * @param array $gateway_params
 * @return mixed
 */
function cashfree_cli_cf_get($url, array $gateway_params)
{
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_HTTPHEADER => array(
            'Accept: application/json',
            'Content-Type: application/json',
            'x-api-version: ' . API_VERSION,
            'x-client-id: ' . $gateway_params['appId'],
            'x-client-secret: ' . $gateway_params['secretKey'],
        ),
    ));
    $response = curl_exec($curl);
    curl_close($curl);
    if ($response === false || $response === '') {
        return null;
    }
    return json_decode($response);
}
