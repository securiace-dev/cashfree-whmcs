<?php
/**
 * Cashfree reconciliation ledger (WHMCS Capsule-dependent).
 *
 * Stores an append-friendly audit trail of verify/credit outcomes.
 * Never stores full webhook bodies — only SHA-256 raw_hash.
 */

require_once __DIR__ . '/CashfreeHelpers.php';

if (!function_exists('cashfree_ledger_table_name')) {
    /**
     * @return string
     */
    function cashfree_ledger_table_name()
    {
        return 'mod_cashfree_events';
    }
}

if (!function_exists('cashfree_ledger_ensure_schema')) {
    /**
     * Create mod_cashfree_events if missing. Safe to call repeatedly.
     *
     * @return bool True when schema is ready (or Capsule unavailable → false)
     */
    function cashfree_ledger_ensure_schema()
    {
        if (!class_exists('WHMCS\\Database\\Capsule')) {
            return false;
        }
        $table = cashfree_ledger_table_name();
        try {
            if (\WHMCS\Database\Capsule::schema()->hasTable($table)) {
                return true;
            }
            \WHMCS\Database\Capsule::schema()->create($table, function ($blueprint) {
                $blueprint->bigIncrements('id');
                $blueprint->timestamp('created_at')->useCurrent();
                $blueprint->string('order_id', 64)->default('');
                $blueprint->string('cf_payment_id', 64)->default('');
                $blueprint->string('cf_refund_id', 64)->default('');
                $blueprint->unsignedInteger('invoice_id')->default(0);
                $blueprint->decimal('amount', 16, 2)->nullable();
                $blueprint->string('currency', 8)->default('');
                $blueprint->string('event_type', 64)->default('');
                $blueprint->string('verify_result', 32)->default('');
                $blueprint->string('credit_outcome', 32)->default('');
                $blueprint->string('source', 16)->default('');
                $blueprint->char('raw_hash', 64)->default('');
                $blueprint->index('invoice_id');
                $blueprint->index('cf_payment_id');
                $blueprint->index('credit_outcome');
                $blueprint->index(array('order_id', 'cf_payment_id', 'event_type'), 'cf_evt_lookup');
            });
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('cashfree_ledger_record')) {
    /**
     * Insert one ledger row. Returns false if Capsule/schema unavailable.
     *
     * @param array $fields See cashfree_build_ledger_row()
     * @return bool
     */
    function cashfree_ledger_record(array $fields)
    {
        if (!cashfree_ledger_ensure_schema()) {
            return false;
        }
        $row = cashfree_build_ledger_row($fields);
        try {
            \WHMCS\Database\Capsule::table(cashfree_ledger_table_name())->insert($row);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('cashfree_ledger_refund_recorded')) {
    /**
     * @param string $refundId
     * @return bool
     */
    function cashfree_ledger_refund_recorded($refundId)
    {
        $refundId = trim((string) $refundId);
        if ($refundId === '' || !class_exists('WHMCS\\Database\\Capsule')) {
            return false;
        }
        if (!cashfree_ledger_ensure_schema()) {
            return false;
        }
        try {
            return \WHMCS\Database\Capsule::table(cashfree_ledger_table_name())
                ->where('cf_refund_id', $refundId)
                ->whereIn('credit_outcome', array('refund_noted', 'note', 'refund_duplicate'))
                ->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('cashfree_ledger_payment_recorded')) {
    /**
     * True when WHMCS already has this Cashfree payment id in tblaccounts.
     *
     * @param string $transactionId
     * @return bool
     */
    function cashfree_ledger_payment_recorded($transactionId)
    {
        $transactionId = trim((string) $transactionId);
        if ($transactionId === '' || !class_exists('WHMCS\\Database\\Capsule')) {
            return false;
        }
        try {
            return \WHMCS\Database\Capsule::table('tblaccounts')
                ->where('transid', $transactionId)
                ->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('cashfree_invoice_currency_code')) {
    /**
     * Resolve ISO currency code for an invoice via client currency.
     *
     * @param int|string $invoiceId
     * @return string Empty when unknown
     */
    function cashfree_invoice_currency_code($invoiceId)
    {
        if (!class_exists('WHMCS\\Database\\Capsule')) {
            return '';
        }
        try {
            $row = \WHMCS\Database\Capsule::table('tblinvoices')
                ->join('tblclients', 'tblclients.id', '=', 'tblinvoices.userid')
                ->join('tblcurrencies', 'tblcurrencies.id', '=', 'tblclients.currency')
                ->where('tblinvoices.id', $invoiceId)
                ->select('tblcurrencies.code')
                ->first();
            return $row && isset($row->code) ? strtoupper(trim((string) $row->code)) : '';
        } catch (\Throwable $e) {
            return '';
        }
    }
}

if (!function_exists('cashfree_refund_lock_name')) {
    /**
     * @param string $refundId
     * @return string
     */
    function cashfree_refund_lock_name($refundId)
    {
        return 'cf_ref_' . substr(hash('sha256', (string) $refundId), 0, 40);
    }
}

if (!function_exists('cashfree_note_refund_once')) {
    /**
     * Idempotently record a refund webhook into the ledger (never auto-debits).
     *
     * @param array $event Normalized refund event
     * @param int|string $invoiceId
     * @param string $rawHash
     * @param string $source
     * @return string note|duplicate|lock_busy|missing_payment|invalid
     */
    function cashfree_note_refund_once(array $event, $invoiceId, $rawHash = '', $source = 'notify')
    {
        $refundId = isset($event['refund_id']) ? trim((string) $event['refund_id']) : '';
        $paymentId = isset($event['transaction_id']) ? trim((string) $event['transaction_id']) : '';
        if ($refundId === '' || $paymentId === '') {
            return 'invalid';
        }
        if (!class_exists('WHMCS\\Database\\Capsule')) {
            return 'invalid';
        }

        $lockName = cashfree_refund_lock_name($refundId);
        $got = \WHMCS\Database\Capsule::selectOne('SELECT GET_LOCK(?, 15) AS g', array($lockName));
        $lockAcquired = $got && (int) $got->g === 1;

        try {
            $paymentRecorded = false;
            $refundAlready = false;
            if ($lockAcquired) {
                $paymentRecorded = cashfree_ledger_payment_recorded($paymentId);
                $refundAlready = cashfree_ledger_refund_recorded($refundId);
            }
            $decision = cashfree_refund_decision($lockAcquired, $paymentRecorded, $refundAlready);
            if ($decision === 'note') {
                cashfree_ledger_record(array(
                    'order_id' => isset($event['order_id']) ? $event['order_id'] : '',
                    'cf_payment_id' => $paymentId,
                    'cf_refund_id' => $refundId,
                    'invoice_id' => $invoiceId,
                    'amount' => isset($event['amount']) ? $event['amount'] : null,
                    'currency' => isset($event['currency']) ? $event['currency'] : '',
                    'event_type' => isset($event['raw_type']) ? $event['raw_type'] : 'REFUND',
                    'verify_result' => 'ok',
                    'credit_outcome' => 'refund_noted',
                    'source' => $source,
                    'raw_hash' => $rawHash,
                ));
            } elseif ($decision === 'duplicate') {
                cashfree_ledger_record(array(
                    'order_id' => isset($event['order_id']) ? $event['order_id'] : '',
                    'cf_payment_id' => $paymentId,
                    'cf_refund_id' => $refundId,
                    'invoice_id' => $invoiceId,
                    'amount' => isset($event['amount']) ? $event['amount'] : null,
                    'currency' => isset($event['currency']) ? $event['currency'] : '',
                    'event_type' => isset($event['raw_type']) ? $event['raw_type'] : 'REFUND',
                    'verify_result' => 'ok',
                    'credit_outcome' => 'refund_duplicate',
                    'source' => $source,
                    'raw_hash' => $rawHash,
                ));
            } elseif ($decision === 'missing_payment') {
                cashfree_ledger_record(array(
                    'order_id' => isset($event['order_id']) ? $event['order_id'] : '',
                    'cf_payment_id' => $paymentId,
                    'cf_refund_id' => $refundId,
                    'invoice_id' => $invoiceId,
                    'amount' => isset($event['amount']) ? $event['amount'] : null,
                    'currency' => isset($event['currency']) ? $event['currency'] : '',
                    'event_type' => isset($event['raw_type']) ? $event['raw_type'] : 'REFUND',
                    'verify_result' => 'ok',
                    'credit_outcome' => 'refund_missing_payment',
                    'source' => $source,
                    'raw_hash' => $rawHash,
                ));
            }
            return $decision;
        } finally {
            if ($lockAcquired) {
                \WHMCS\Database\Capsule::selectOne('SELECT RELEASE_LOCK(?) AS r', array($lockName));
            }
        }
    }
}
