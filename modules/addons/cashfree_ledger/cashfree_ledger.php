<?php
/**
 * WHMCS addon: Cashfree reconciliation ledger (read-only operator view).
 *
 * Activate under Setup → Addon Modules. Creates mod_cashfree_events on activate.
 * Gateway notify/return also ensure the schema lazily.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once dirname(__DIR__, 2) . '/gateways/cashfree/lib/CashfreeHelpers.php';
require_once dirname(__DIR__, 2) . '/gateways/cashfree/lib/CashfreeLedger.php';

/**
 * @return array
 */
function cashfree_ledger_config()
{
    $version = defined('CASHFREE_PLUGIN_VERSION') ? CASHFREE_PLUGIN_VERSION : 'dev';
    return array(
        'name' => 'Cashfree Ledger',
        'description' => 'Read-only Cashfree payment / refund reconciliation trail (mod_cashfree_events). Gateway plugin ' . $version,
        'version' => $version,
        'author' => 'Securiace',
        'language' => 'english',
        'fields' => array(),
    );
}

/**
 * @return array
 */
function cashfree_ledger_activate()
{
    if (cashfree_ledger_ensure_schema()) {
        return array(
            'status' => 'success',
            'description' => 'Cashfree ledger table ready (mod_cashfree_events)',
        );
    }
    return array(
        'status' => 'error',
        'description' => 'Unable to create mod_cashfree_events — check DB permissions',
    );
}

/**
 * @return array
 */
function cashfree_ledger_deactivate()
{
    // Keep historical ledger rows; do not drop table on deactivate.
    return array('status' => 'success', 'description' => 'Deactivated (ledger table retained)');
}

/**
 * Admin output: filterable event list.
 *
 * @param array $vars
 * @return void
 */
function cashfree_ledger_output($vars)
{
    cashfree_ledger_ensure_schema();

    $filter = isset($_REQUEST['filter']) ? (string) $_REQUEST['filter'] : 'problems';
    $allowed = array(
        'problems' => array('rejected_amount', 'rejected_currency', 'rejected_sig', 'rejected_skew', 'lock_busy', 'unmatched_success', 'refund_missing_payment', 'rejected_oversize'),
        'applied' => array('applied'),
        'duplicate' => array('duplicate', 'refund_duplicate'),
        'refunds' => array('refund_noted', 'refund_duplicate', 'refund_missing_payment'),
        'all' => null,
    );
    if (!array_key_exists($filter, $allowed)) {
        $filter = 'problems';
    }

    echo '<h2>Cashfree reconciliation ledger</h2>';
    echo '<p>Operator trail for Cashfree notify / return / CLI. Full webhook bodies are not stored (SHA-256 only).</p>';
    echo '<p>Plugin version: <strong>' . htmlspecialchars(
        defined('CASHFREE_PLUGIN_VERSION') ? CASHFREE_PLUGIN_VERSION : 'unknown',
        ENT_QUOTES,
        'UTF-8'
    ) . '</strong></p>';

    echo '<p>Filter: ';
    foreach (array_keys($allowed) as $key) {
        $label = htmlspecialchars($key, ENT_QUOTES, 'UTF-8');
        if ($key === $filter) {
            echo '<strong>' . $label . '</strong> ';
        } else {
            echo '<a href="?module=cashfree_ledger&amp;filter=' . urlencode($key) . '">' . $label . '</a> ';
        }
    }
    echo '</p>';

    echo '<p>CLI dry-run (Dokploy): <code>php -q modules/gateways/cashfree/cli/reconcile.php --days=7</code></p>';

    try {
        $query = \WHMCS\Database\Capsule::table(cashfree_ledger_table_name())->orderBy('id', 'desc')->limit(200);
        if ($allowed[$filter] !== null) {
            $query->whereIn('credit_outcome', $allowed[$filter]);
        }
        $rows = $query->get();
    } catch (\Throwable $e) {
        echo '<p><strong>Ledger unavailable:</strong> ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
        return;
    }

    if (count($rows) === 0) {
        echo '<p>No matching events.</p>';
        return;
    }

    echo '<table class="datatable" width="100%" border="0" cellspacing="1" cellpadding="3">';
    echo '<tr>'
        . '<th>ID</th><th>When</th><th>Invoice</th><th>Order</th><th>Payment</th>'
        . '<th>Refund</th><th>Amount</th><th>CCY</th><th>Type</th><th>Verify</th>'
        . '<th>Outcome</th><th>Source</th><th>Hash</th>'
        . '</tr>';

    foreach ($rows as $row) {
        echo '<tr>';
        echo '<td>' . (int) $row->id . '</td>';
        echo '<td>' . htmlspecialchars((string) $row->created_at, ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . (int) $row->invoice_id . '</td>';
        echo '<td>' . htmlspecialchars((string) $row->order_id, ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars((string) $row->cf_payment_id, ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars((string) $row->cf_refund_id, ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars((string) $row->amount, ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars((string) $row->currency, ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars((string) $row->event_type, ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars((string) $row->verify_result, ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars((string) $row->credit_outcome, ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars((string) $row->source, ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td><code>' . htmlspecialchars(substr((string) $row->raw_hash, 0, 12), ENT_QUOTES, 'UTF-8') . '</code></td>';
        echo '</tr>';
    }
    echo '</table>';
}
