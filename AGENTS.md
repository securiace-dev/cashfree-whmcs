# Agent notes — cashfree-whmcs

## Production deploy (never forget)

- **Canonical prod project:** `whmcs-prod-new` — the main repo/control plane that all WHMCS modules must stay aligned with (overlay, admission, Dokploy compose).
- **Live host:** `195.7.4.219` is the **Dokploy prod host** running Docker-deployed WHMCS from that project (public site `https://my.securiace.com/`, admin path **`/shriram`**).
- **Ship path:** gate → package module zip (WHMCS-root-relative paths) → **admit via `whmcs-prod-new`** (and related `securiace-whmcs-prod` instance contract) into the app volume at `/var/www/html/...`.
- **Merge to `master` is not a deploy.** Do not claim the gateway is live until Dokploy / `whmcs-prod-new` admission + smoke are done.
- **Retired method only:** FastPanel-era **loose rsync of module trees** as the release path. Do **not** treat “avoid 195.7.4.219” as policy — that IP is prod Dokploy; use the `whmcs-prod-new` admit/overlay flow (or authorized `docker exec` into `whmcs-production-*-app-1`), never ad-hoc FastPanel file drops.
- **Auth (standard practice — never violate):**
  - **Cursor identity:** `yashodhan.kulkarni@gmail.com` **only** (Premium). Never use
    `yashodhan@securiace.com` for Cursor, and never cloud-browser OTP as a workaround.
  - **GitHub on Mac:** `/Users/kritananda/.local/bin/gh-account` to switch accounts per
    project (GitHub identity may differ from Cursor). Prefer the Mac worker over
    cloud `gh` as the `cursor` bot for private control-plane repos.
  - **Prod SSH:** `~/.ssh/id_rsa_securiace` → `root@195.7.4.219` (aliases
    `ded1-securiace-com` / `sat-de-prod01`).
  - Prefer self-hosted worker `~/Projects/whmcs-prod-new @ Sri's MacBook Pro`
    (`list-self-hosted-workers` for `workerId`). Place with `CreateAgent` +
    `machine.type=self_hosted_worker` when that tool exists. Overlay pin + image;
    no FastPanel loose rsync; no per-project secret asks; no secrets in chat/commits.
- Operator Mac sources of truth:
  - `~/Projects/whmcs-prod-new` — **primary** control plane
  - `~/Projects/whmcs-devbox`
  - `~/.ai-audit/` (prior deploy learnings)
  - related: `securiace-dev/securiace-whmcs-prod`, vendor helpers in `whmcs-prod`
- Canonical workflow store: `workflows/whmcs-dokploy-deploy.md` +
  `workflows/mac-gh-accounts-and-ssh.md` (user Cursor store).
- Sister-repo contract when needed:
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
