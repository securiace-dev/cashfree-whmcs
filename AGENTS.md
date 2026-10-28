# Agent notes — cashfree-whmcs

Hardened Cashfree Payment Gateway for WHMCS (Securiace fork of `cashfree/cashfree-whmcs`).

## Version

- Constant: `CASHFREE_PLUGIN_VERSION` in `modules/gateways/cashfree/lib/CashfreeHelpers.php`
- Current: **2.5.0** (integrity moat: skew / currency / refunds / structured log codes + reconciliation ledger + immutable package)
- Admin-visible via gateway FriendlyName / MetaData `DisplayName` and addon **Cashfree Ledger**

## Moat layers (do not dilute)

1. **Integrity** — signed webhooks, amount + currency match, timestamp skew window, idempotent credit lock, 64 KiB body cap → 413, refund note-only
2. **Reconciliation** — `mod_cashfree_events` ledger + addon UI + `cli/reconcile.php`
3. **Ship** — immutable zip + sha256 + manifest; prod via `whmcs-prod-new` overlay pin (never FastPanel rsync)

Out of scope: multi-PSP orchestration, custom UPI checkout, live file edits as release path.

## Local tests

```bash
php tests/CashfreeHelpersTest.php
```

## Immutable artifact

```bash
bash scripts/package-immutable.sh
# → dist/cashfree-whmcs-<version>.zip
# → dist/cashfree-whmcs-<version>.zip.sha256
# → dist/cashfree-whmcs-<version>.manifest
```

Manifest fields: `version`, `git_sha`, `sha256`, `overlay_pin` hint.

## Prod ship (Dokploy / overlay)

Follow user workflow `whmcs-dokploy-deploy`:

1. Merge module PR on `master` (helpers + CI green).
2. Build immutable artifact from the merge SHA (`scripts/package-immutable.sh`).
3. Open **overlay pin** PR on `securiace-dev/whmcs-prod-new` for Cashfree (same pattern as Bank Transfer Pro / Contabo): pin version + `sha256` digest — do **not** patch module PHP inside the overlay repo.
4. Rebuild WHMCS image; update `securiace-whmcs-prod` `image.lock` if that is the rollout path.
5. Dokploy redeploy app container. Validate: gateway DisplayName version string, oversize notify still 413, ledger addon lists events.
6. Ask before any prod `docker exec` / Dokploy click. Auth: Cursor `yashodhan.kulkarni@gmail.com` + Mac `gh-account` / SSH — never per-project secrets.

## Operator tools

| Tool | Path | Notes |
|------|------|--------|
| Notify / webhook | `modules/gateways/cashfree/cashfree_notify.php` | Public; body-capped; structured `Cashfree <code>` gateway logs |
| Browser return | `modules/gateways/cashfree/cashfree.php` | API verify + same credit lock |
| Ledger addon | `modules/addons/cashfree_ledger` | Filters: problems / applied / duplicate / refunds / all |
| Recon CLI | `php -q modules/gateways/cashfree/cli/reconcile.php [--days=N] [--apply]` | Default dry-run; `--apply` credits unmatched SUCCESS only |

Gateway log codes: `applied`, `duplicate`, `lock_busy`, `rejected_sig`, `rejected_amount`, `rejected_currency`, `rejected_oversize`, `rejected_skew`, `ignored_not_success`, `refund_noted`, `refund_duplicate`, `refund_missing_payment`.

## Structured logging contract

`logTransaction(..., 'Cashfree <code>')` with payload key `code` matching the table above. Prefer filtering gateway activity by that prefix over free-text archaeology.
