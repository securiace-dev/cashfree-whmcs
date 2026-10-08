## Official Cashfree Payment Gateway plugin for WHMCS.

![GitHub](https://img.shields.io/github/license/cashfree/cashfree-whmcs) ![Discord](https://img.shields.io/discord/931125665669972018?label=discord) ![GitHub last commit (branch)](https://img.shields.io/github/last-commit/cashfree/cashfree-whmcs/master) ![GitHub release (with filter)](https://img.shields.io/github/v/release/cashfree/cashfree-whmcs?label=latest)  ![GitHub forks](https://img.shields.io/github/forks/cashfree/cashfree-whmcs) ![GitHub Repo stars](https://img.shields.io/github/stars/cashfree/cashfree-whmcs)


## Cashfree Payment Extension for WHMCS

Allows you to use Cashfree payment gateway with the WHMCS.

**Plugin version:** 2.4.4 · **Cashfree PG API version header:** `2022-09-01`

## Description

This repository contains integration code for interaction with the Cashfree API and allows payment in WHMCS seamlessly.

## Installation

1. Ensure you have a supported WHMCS release (8.x recommended) on PHP 7.4+ / 8.x.
2. Download the zip of this repo.
3. Upload the contents of the repo to your WHMCS Installation directory (content of `modules/` folder goes in the WHMCS `modules/` folder).

## Configuration

1. Log into WHMCS as administrator (`http://whmcs_installation/admin`).
2. Navigate to Setup → Payments → Payment Gateways.
3. Choose Cashfree in the Activate dropdown and Activate it.
4. Fill the App Id and Secret Key.
5. Choose if you are using test mode or live mode.
6. Optionally enable Popup Checkout.
7. Click **Save Changes**.

### Callback URLs (auto-configured per order)

| Purpose | Path |
| --- | --- |
| Browser return | `{systemurl}modules/gateways/cashfree/cashfree.php?order_id={order_id}` |
| Server notify / webhook | `{systemurl}modules/gateways/cashfree/cashfree_notify.php` |

The notify endpoint accepts modern Cashfree JSON webhooks (`x-webhook-signature` / `x-webhook-timestamp`) and legacy form-encoded notify payloads. Payments are applied only after signature verification, `SUCCESS` status, invoice validation, and amount match.

## Local helper tests

No WHMCS install required for helper unit tests:

```bash
php tests/CashfreeHelpersTest.php
```

Do not run against production Cashfree credentials from automated tests.

### Support

For further queries, reach us at techsupport@gocashfree.com .
