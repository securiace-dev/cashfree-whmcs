# Agent notes — cashfree-whmcs

## Production deploy (never forget)

- **Live WHMCS** runs on the **Dokploy WHMCS prod server** (`https://my.securiace.com/`, admin path **`/shriram`**).
- **Ship path:** gate → package module zip (WHMCS-root-relative paths) → **admit via `whmcs-prod-new` / `securiace-whmcs-prod`** into the app volume at `/var/www/html/...`.
- **Merge to `master` is not a deploy.** Do not claim the gateway is live until Dokploy admission + smoke are done.
- **Never** use retired FastPanel / `root@195.7.4.219` rsync.
- **Never deploy without an explicit go** from the operator.
- Operator Mac sources of truth (often not mounted in cloud):
  - `~/Projects/whmcs-prod-new` (`/Users/kritananda/Projects/whmcs-prod-new`)
  - `~/Projects/whmcs-devbox`
  - `~/.ai-audit/` (prior deploy learnings)
  - private repos: `securiace-dev/securiace-whmcs-prod`, `yashodhank/whmcs-prod`
- Sister-repo contract (public evidence when private trees are unavailable):
  `securiace-dev/contabo-pricing-scraper` → `docs/operations/DOKPLOY_WHMCS_MODULE_DEPLOY.md`

### This module’s runtime paths

```
modules/gateways/cashfree.php
modules/gateways/cashfree/cashfree.php
modules/gateways/cashfree/cashfree_notify.php
modules/gateways/cashfree/lib/CashfreeHelpers.php
```

Notify URL: `{systemurl}modules/gateways/cashfree/cashfree_notify.php`

### After admit

1. Confirm files under `/var/www/html/modules/gateways/...` in `whmcs-production-*-app-1`.
2. Smoke: signed webhook accepted; body > 64 KiB → HTTP 413; payment credit still signature-gated.
3. Keep prior artifact + checksum for rollback.

## Local tests (no WHMCS)

```bash
php tests/CashfreeHelpersTest.php
```

## Infra note (PHP CVEs)

Webhook body capping hardens this module’s public notify path. The complete fix
for CVE-2026-109618 / CVE-2026-109619 is still a **host PHP upgrade** on the
Dokploy WHMCS runtime (≥ 8.2.35 / 8.3.36 / 8.4.27 / 8.5.12).
