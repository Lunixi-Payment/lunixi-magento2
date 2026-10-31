# Magento 2 / Adobe Commerce

**Current verified merchant ZIP 0.1.4:** [`lunixi-magento2-0.1.4.zip`](https://github.com/Lunixi-Payment/lunixi-magento2/releases/download/v0.1.4/lunixi-magento2-0.1.4.zip) · [SHA256SUMS](https://github.com/Lunixi-Payment/lunixi-magento2/releases/download/v0.1.4/SHA256SUMS) · [Release v0.1.4](https://github.com/Lunixi-Payment/lunixi-magento2/releases/tag/v0.1.4) · [Passing release CI](https://github.com/Lunixi-Payment/lunixi-magento2/actions/runs/41242098729).

Public ZIP SHA-256: `325625fdaf9f4680ebde1227354ab5c6116dd16d9c123ad7193a41bd96fcdcf2`. The downloaded archive passed public checksum, GitHub asset digest and ZIP CRC checks on 1 November 2026. Use this installation package rather than GitHub’s automatic source archive.

The existing Lunixi form opens **on the merchant website by default**. Hosted checkout remains optional and uses only the validated backend `paymentPageUrl`. Its opaque token can differ from the embedded token; the module never builds this URL from that token. Missing or legacy URLs keep embedded checkout available; hosted checkout stays on hold/reconciliation without a new intent or key. Existing attempts keep their saved mode, request body, profile and key. The official form/panel controls available methods and multi-card options.

Lunixi Payments 0.1.4 integrates the Magento Payment Adapter with the shared PHP SDK 0.29.6. Source: [lunixi-magento2](https://github.com/Lunixi-Payment/lunixi-magento2). The target API line is **2.4.9**; native acceptance was executed on Magento Open Source, not a licensed Adobe Commerce installation.

## Install

Use the completed `lunixi-magento2-0.1.4.zip` and verify its SHA-256. Extract it at the store root; the module is under `app/code/Lunixi/Payments`. The ZIP bundles a namespace-isolated SDK and needs no module Composer restore.

```bash
php bin/magento module:enable Lunixi_Payments
php bin/magento setup:upgrade --safe-mode=1
php -d memory_limit=2G bin/magento setup:di:compile
php bin/magento setup:static-content:deploy en_US tr_TR
php bin/magento cache:clean
```

Maintainers can build from source with `composer install --working-dir=tools --no-plugins --no-scripts` followed by `php tools/build.php`. The build input is exactly `lunixi/php-sdk:0.29.6`; `php tools/build.php /absolute/path/to/lunixi-php` accepts a trusted checkout. Source checkouts must be built before enabling the module. GitHub source availability does not imply a module Composer-registry release or Adobe Marketplace listing.

Follow Adobe’s supported deployment matrix and security patches. Current native acceptance ran the full 223-module Magento Open Source 2.4.9 store on PHP 8.5.12, MariaDB 12.3.3/InnoDB and OpenSearch 3.9.0: 23 tests / 117 assertions plus actual HTTPS guest quote/order, embedded/hosted, callback, ownership and signed settlement routes using a local TLS provider stand-in. The earlier 63-module PHP8.4 fixture remains historical. Full browser/CDN/card/3DS and licensed Commerce acceptance are not claimed. See [verification](VERIFICATION.md) and [upstream dependency findings](UPSTREAM-SECURITY.md).

## Configure credentials

Set `LUNIXI_MAGENTO_ENCRYPTION_KEY` to base64 of a separately generated random 32-byte key on every web/cron instance. Keep a secure backup outside the database/web root. Losing or replacing it without migration makes previous profiles unreadable.

Open **Stores → Configuration → Sales → Payment Methods → Lunixi Payments**:

| Field | Purpose |
| --- | --- |
| Enable Lunixi | Enables new checkout attempts |
| Merchant ID | Expected identity in authenticated payment records |
| Merchant SECRET key identifier | Server-side API key; determines TEST/LIVE |
| Ed25519 PKCS8 private key | Base64 DER, or PEM with literal `\n` separators |
| Webhook signing secret | Verifies signed raw-body v2 deliveries |
| Redirect to pay.lunixi.com | Hosted form when enabled; embedded form when disabled |
| Authorize now, capture later | Keeps authorization pending until capture |

Native encrypted fields mask saved credentials. Immutable profiles retain old credentials for refunds and reconciliation. Existing attempts keep their original profile, request, callback, base currency and display mode. The gateway origin is fixed to `https://api-gateway.lunixi.com`.

Register the displayed **HTTPS webhook URL** in the Lunixi panel for the right environment and payment-event subscriptions. It ends in `lunixi/notification/webhook/profile/<profile-id>`. Preserve historical endpoint profiles while old payments are active. Start with TEST credentials on a staging store.

## Hosted or embedded form

Hosted checkout sends the buyer to `paymentPageUrl`. Embedded checkout loads only the fixed official `https://cdn.lunixi.com/lunixi-checkout.min.js` bundle and mounts the existing Lunixi form. Customer/session order ownership is checked before browser-token access. Return pages never decide payment success.

Methods, installments, insurance and split eligibility are managed in the **Lunixi panel**. No duplicate method-filter settings are present. Product availability still requires merchant/form activation and suitable product context. The module never collects raw card data or renders gateway-returned HTML. Use HTTPS with trusted proxy headers and a CSP allowing the official form origins.

## Verified settlement

Amounts use the persisted order **base currency/base grand total** in exact integer minor units; display-currency conversion stays in Magento. Additional installment fees require the persisted fingerprint in authenticated metadata and are recorded separately from the base basket.

Each intent has a durable reservation/key before network I/O. Uncertain responses preserve that key. The create response binds the actual `paymentId` and token. A lost response can recover through a signed event only if authenticated GET also proves the original opaque reservation. The module has no automatic attempt-reset route; `FAILED` alone does not prove definitive failure.

The receiver verifies exact bytes, signed event/timestamp identity, freshness and replay conflicts. It looks up only a known order and queries the gateway with server credentials. Merchant, payment ID, order, currency, amount and full settlement counters must match. Inbox, native order, invoice, credit memo and notification-outbox writes share the native sales database transaction and exclusive per-order lock. InnoDB storage and one native sales/default connection are required and checked.

| Verified evidence | Native result |
| --- | --- |
| Full captured settlement | Native paid invoice through `InvoiceService` |
| Authorization without capture | Pending payment; no paid invoice |
| Complete balanced split parent without compensation/reconciliation | May complete the parent order |
| Captured split leg, partial/ambiguous state or promissory-note issuance alone | No automatic fulfillment |
| Confirmed refund balance | Native monetary adjustment credit memo |
| Existing native fraud/manual hold or canceled order | Hold is preserved |

Split support in source does not assert gateway rollout or merchant eligibility. Promissory-note issuance is not collected cash. Reconciliation can apply a funded order after an explicit native unhold.

## Capture, refund, void and reconciliation

Under **Sales → Orders**, select **Manage Lunixi payment**. Dedicated native permission and CSRF/form-key POST checks guard capture, refund, void and reconciliation. These actions use addon-owned persisted operation IDs; retrying the same action retains its exact key/amount. The native standard online-action flags are disabled to avoid a gateway call returning before native financial bookkeeping.

A mutation response alone never confirms success. Authenticated settlement balances must prove the effect first. Lost responses can reconcile without another mutation. The SDK stops replay before the gateway's 24-hour cache boundary while preserving read-only reconciliation.

Refund input is in base currency. Partial/full refunds create **monetary adjustment credit memos** and do not fabricate returned item quantities or restock inventory. Handle product returns/RMA separately. The final base-basket refund includes any remaining verified fee. Do not create another native offline memo for the same refund.

Enable Magento cron. Separate rotating budgets poll unresolved orders and settled refund discovery. Notifications are delivered from a post-commit outbox. Uncertain mail sends remain review/sending for operator inspection; third-party event observers performing external effects need their own durable outbox.

## Retention and limits

Keep `lunixi_payment_*` tables, historical profiles, Magento crypt key and the separate server key through refund/dispute retention. No financial-delete uninstall hook or destructive declarative whitelist is provided. Recurring payments, stored cards/vault, Hyvä/headless renderers, automated RMA/restocking and Adobe Commerce-only features are not implemented in 0.1.4.

The repository verification record lists executed native tests, dependency/security checks and limits. No live charge, marketplace approval or complete browser-store acceptance is claimed.

The native financial fixture applies checksum-pinned official July–October 2026 CE patches and the separate VULN-39341 fix. This does not clear the platform’s third-party Composer findings; see [upstream audit scope](UPSTREAM-SECURITY.md). The canonical browser POST callback only uses a bounded field-ignoring same-origin 303 adapter; the subsequent GET restores native session/ownership checks.
