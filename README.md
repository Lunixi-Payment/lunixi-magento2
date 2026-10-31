<p align="center"><picture><source media="(prefers-color-scheme: dark)" srcset="view/frontend/web/images/lunixi-dark.svg"><img src="view/frontend/web/images/lunixi.svg" alt="Lunixi" width="240"></picture></p>

# Lunixi Payments for Magento 2

**Current verified merchant ZIP 0.1.4:** [`lunixi-magento2-0.1.4.zip`](https://github.com/Lunixi-Payment/lunixi-magento2/releases/download/v0.1.4/lunixi-magento2-0.1.4.zip) · [SHA256SUMS](https://github.com/Lunixi-Payment/lunixi-magento2/releases/download/v0.1.4/SHA256SUMS) · [Release v0.1.4](https://github.com/Lunixi-Payment/lunixi-magento2/releases/tag/v0.1.4) · [Passing release CI](https://github.com/Lunixi-Payment/lunixi-magento2/actions/runs/41242098729).

Public ZIP SHA-256: `325625fdaf9f4680ebde1227354ab5c6116dd16d9c123ad7193a41bd96fcdcf2`. The downloaded archive passed public checksum, GitHub asset digest and ZIP CRC checks on 1 November 2026. Use this installation package rather than GitHub’s automatic source archive.

The existing Lunixi form opens **on the merchant website by default**. Hosted checkout remains optional and uses only the validated backend `paymentPageUrl`. Its opaque token can differ from the embedded token; the module never builds this URL from that token. Missing or legacy URLs keep embedded checkout available; hosted checkout stays on hold/reconciliation without a new intent or key. Existing attempts keep their saved mode, request body, profile and key. The official form/panel controls available methods and multi-card options.

Payment module **0.1.4**, targeting Magento Open Source / Adobe Commerce **2.4.9 payment APIs**. It uses the modern Magento Payment Adapter and the shared [Lunixi PHP SDK 0.29.6](https://github.com/Lunixi-Payment/lunixi-php). The existing Lunixi form is embedded by default; optional redirect uses the backend `paymentPageUrl`.

Available methods, installments, insurance and split eligibility are managed in the **Lunixi panel**. The module does not duplicate method filters or collect card data. Product-specific capabilities still depend on merchant/form activation and the required product context.

Release 0.1.4 bundles published PHP SDK 0.29.6 to fix strict PHP 8.5 transport handling. Its current public ZIP and passing CI are verified above.

## Compatibility

Current native acceptance used the full **223-module Magento Open Source 2.4.9** store on **PHP 8.5.12 / MariaDB 12.3.3 / InnoDB / OpenSearch 3.9.0**, including actual HTTPS guest-cart/order/checkout/webhook routes with a controlled local TLS provider. **23 native tests / 117 assertions** passed. The earlier 63-module PHP 8.4.1/MariaDB 11.8.6 fixture is historical. No full browser/CDN/card/3DS or licensed Adobe Commerce acceptance is claimed.

Production deployments must follow Adobe's [current system requirements](https://experienceleague.adobe.com/en/docs/commerce-operations/installation-guide/system-requirements) and security patches. Adobe's current 2.4.9 on-premises matrix lists PHP 8.5 and MariaDB 12.3/MySQL 8.4. Source PHP constraints and local acceptance are not claims that every platform deployment combination is vendor supported. Older Magento API lines and Adobe Commerce-only features are not claimed tested.

GitHub source availability does not imply Adobe Marketplace approval or publication of this module to a Composer registry. The release ZIP bundles an isolated shared SDK, so installing it needs no module dependency fetch or build tools.

## Build and install

Maintainers need Composer, PHP with sodium/curl/json/zip and the native platform's required extensions:

```bash
composer install --working-dir=tools --no-interaction --no-plugins --no-scripts
php tools/build.php
```

The builder fetches exactly `lunixi/php-sdk:0.29.6`, scopes it into `Lunixi\Payments\Vendor` with pinned build dependencies and includes its MIT license. A trusted source checkout can be supplied as `php tools/build.php /absolute/path/to/lunixi-php`. Output: `dist/lunixi-magento2-0.1.4.zip` and SHA-256 file. Source checkouts must be built before enabling the module; the generated SDK is deliberately excluded from source control. The source and ZIP include the authoritative `VERSION` file. Public Composer exports may omit their optional `version` field because Packagist reads Git tags; the builder supports both forms and rejects a conflicting field.

On a backed-up staging Magento installation, extract the ZIP at the store root. It contains `app/code/Lunixi/Payments/`:

```bash
php bin/magento module:enable Lunixi_Payments
php bin/magento setup:upgrade --safe-mode=1
php -d memory_limit=2G bin/magento setup:di:compile
php bin/magento setup:static-content:deploy en_US tr_TR
php bin/magento cache:clean
```

The native store still needs its normal dependencies and configured cron. Keep the module's server encryption key and database backup together through financial retention, with keys stored separately.

## Credentials and form settings

Set `LUNIXI_MAGENTO_ENCRYPTION_KEY` to the base64 representation of an independently generated random 32-byte key on every application/cron instance. Back it up outside the database and web root. Replacing it without migrating historical profiles prevents recovery of previous payments.

Under **Stores → Configuration → Sales → Payment Methods → Lunixi Payments**, enable the method and enter merchant ID, merchant SECRET key identifier, Ed25519 PKCS8 private key and webhook secret. The private-key field accepts base64 PKCS8 DER or PEM with literal `\n` separators. Native encrypted configuration fields mask saved secrets. TEST/LIVE is determined by the API key; the HTTPS gateway origin is fixed.

Leave **Use optional hosted checkout** disabled for the default merchant-site form; enable it only for the backend-URL hosted flow. **Authorize now, capture later** keeps an authorized order pending; authorization does not create a paid invoice. Changed secrets create encrypted immutable profiles; existing attempts retain their original merchant, currency, body, callback, display mode and credentials.

Register the displayed HTTPS webhook URL in Lunixi for the correct environment and payment events. Retain prior profiles/endpoint secrets while old payments can require reconciliation or refunds. Use HTTPS, trusted proxy headers and your store's CSP. The module allowlists fixed official SDK/gateway/form origins and never renders gateway-returned HTML.

## Settlement and native order actions

Settlement uses the order's persisted **base currency/base grand total**, with exact integer minor units; display currency remains Magento's concern. Paid state requires an authenticated payment GET matching merchant, payment ID, order, currency, base amount, fingerprint for additional fees, and complete captured/refunded balances. Browser returns are informational. `FAILED` alone does not prove definitive failure; the module has no automatic key-reset route.

Webhooks verify exact bytes, v2 timestamp/event binding and replay identity. Known-order lookup precedes authenticated reconciliation. A lost create response can recover only from the original opaque reservation proof. Inbox consumption, order changes, invoices, credit memos and notification records commit on the **same native sales connection**, with exclusive per-order locking. Split payment requires a complete balanced parent aggregate without compensation/reconciliation; a captured leg alone never fulfills an order. Promissory-note issuance and ambiguous/partial states remain review.

Use **Sales → Orders → Manage Lunixi payment** for capture, partial/full refund, void or read-only reconciliation. Dedicated native ACL and form-key/POST checks apply. Standard online capture/refund flags are disabled: these actions persist an addon-owned operation before I/O and apply native bookkeeping atomically after authenticated confirmation, without a gateway callback returning before core financial writes. Unknown outcomes retain the same operation/key and require reconciliation. The shared SDK stops replay before the gateway's 24-hour idempotency-cache boundary.

Confirmed full capture creates a native paid invoice through `InvoiceService`. Refunds create native **monetary adjustment credit memos** after verified settlement; they do not fabricate returned quantities or restock inventory. Product returns/RMA and inventory adjustments remain a separate merchant action. The final base-basket refund includes any remaining authenticated installment fee; gross gateway balances are retained separately so the basket is not inflated twice. Do not issue a separate native offline credit memo for the same Lunixi refund.

Native fraud/manual holds and canceled orders are preserved even when funded. Reconciliation can apply a paid invoice after an explicit native unhold. Magento cron polls unresolved and settled attempts with separate rotating budgets. Mail is queued in a post-commit outbox; an uncertain send remains `review`/`sending` for operator inspection rather than being blindly sent twice. Third-party observers performing external effects inside native events need their own durable outbox.

## Retention and verification

Disabling/removing the module does not run a financial-data deletion hook. No destructive declarative whitelist or uninstall routine is provided. Preserve `lunixi_payment_*` tables, historical encrypted profiles, Magento crypt key and the separate server key; native destructive database cleanup remains an explicit operator action. Recurring payments, vault/card storage, Hyvä/headless checkout renderers, RMA automation and Adobe Commerce-only modules are not implemented in 0.1.4.

See [docs/VERIFICATION.md](docs/VERIFICATION.md) for actual test commands, native fixture scope and limits; [English guide](docs/guide.en.md), [Türkçe rehber](docs/guide.tr.md), [security reporting](SECURITY.md), [changelog](CHANGELOG.md), [MIT](LICENSE). No live charge or marketplace approval is claimed.

The native financial fixture applies checksum-pinned official July–October 2026 CE patches and the separate VULN-39341 fix. This does not clear the platform’s third-party Composer findings; see [upstream audit scope](docs/UPSTREAM-SECURITY.md). The canonical browser POST callback only uses a bounded field-ignoring same-origin 303 adapter; the subsequent GET restores native session/ownership checks.

**Historical public merchant ZIP 0.1.3:** [`lunixi-magento2-0.1.3.zip`](https://github.com/Lunixi-Payment/lunixi-magento2/releases/download/v0.1.3/lunixi-magento2-0.1.3.zip) · [SHA256SUMS](https://github.com/Lunixi-Payment/lunixi-magento2/releases/download/v0.1.3/SHA256SUMS) · [Release v0.1.3](https://github.com/Lunixi-Payment/lunixi-magento2/releases/tag/v0.1.3). The CI-built installation package was downloaded and verified on 2026-10-31. Use this asset rather than GitHub's automatic source archive. Historical SHA-256: `fff93235ddf631c1b7da2e20b66b1f1bf69bdf0e0f73a1bfdbd0aa1318ac6ad9`.
