# Verification record — 0.1.3

**Current verified merchant ZIP 0.1.4:** [`lunixi-magento2-0.1.4.zip`](https://github.com/Lunixi-Payment/lunixi-magento2/releases/download/v0.1.4/lunixi-magento2-0.1.4.zip) · [SHA256SUMS](https://github.com/Lunixi-Payment/lunixi-magento2/releases/download/v0.1.4/SHA256SUMS) · [Release v0.1.4](https://github.com/Lunixi-Payment/lunixi-magento2/releases/tag/v0.1.4) · [Passing release CI](https://github.com/Lunixi-Payment/lunixi-magento2/actions/runs/41242098729).

Public ZIP SHA-256: `325625fdaf9f4680ebde1227354ab5c6116dd16d9c123ad7193a41bd96fcdcf2`. The downloaded archive passed public checksum, GitHub asset digest and ZIP CRC checks on 1 November 2026.

Historical baseline verified on 2026-10-31, macOS arm64, **PHP 8.4.1**, **MariaDB 11.8.6/InnoDB**, official Magento Open Source **2.4.9** and real native DI/services/database. No live gateway charge or external email was sent.

## Native source and security pin

- GitHub tag `2.4.9`, commit `755e34dd689021c5165db9d35ecff74f7dc51527`.
- Official source archive SHA-256: `57d31453bd8cc34fd1d7c770ed20ad85a764edbdc4e3bd065614f1413bd82ea0`.
- Installed through actual `setup:install`, 63 native modules plus Lunixi, with source-provided Composer lock/dependencies. Search modules/daemon are omitted from this financial fixture.
- Current module build pins shared SDK **0.29.5**. The earlier baseline used published **v0.29.3**, source reference `a725c9012020cb7b399d237984eaade7e5114157`. Scoped SDK signing and body digests are checked using independent sodium verification.
- Original official CE security patches applied sequentially, with zero failed hunks: July → August → separate VULN-39341_249 → September → October. No patch hunk rewrites or blanket vendor updates.
- Fresh **Linux aarch64 / Ubuntu 24 glibc 2.39**, official **GNU patch 2.7.6-7build3**: all five original patches passed strict `--fuzz=0` dry-run and application. All **75** patched file hashes matched the independently verified official fixture. Absolute source aliases reproduced the exact July line 1555/1572 failure; the root-confined relative alias helper fixes it without relaxing patch options.

| Official archive | SHA-256 |
| --- | --- |
| 2-4-9-jul-2026.zip | `650e8e34e925653a1b3980ce053b9cb993dc2b22e72ee80b5557e03f36848984` |
| 2-4-9-aug-2026.zip | `0f300d043b5f7f9558d2d1ffe13b91ec84b5766549adbaec1fc73278ff72b0e1` |
| VULN-39341-composer-patches.zip | `5f98d409e627c0ac77fb9cbff4bf139c17ad69550dc06411a4b4d0aeadd669e1` |
| 2-4-9-sep-2026.zip | `9fe17a9681d94deaaeb2ca9245e1e1bcd9fad9b3478fbcaaa8ab1a94ee4cd686` |
| 2-4-9-oct-2026.zip | `1d995f5998a65dc46c76c31bb4cff799ebaf26121ca933ec18a708393b2f1d30` |

`tools/native-security.py` creates source-component aliases under the TEST vendor directory so Adobe’s original Composer-path patches apply unchanged to GitHub source. It records/validates hashes of all patched files. The official CVT tool reports an unknown base package on this monorepo Composer lock; it does not certify PROTECTED. See [UPSTREAM-SECURITY.md](UPSTREAM-SECURITY.md) for exact audit findings, IDs, severities and official remediation scope.

## Executed checks

- Native `module:enable`, declarative `setup:upgrade`, clean `setup:install`, and full **9/9 native DI compile stages** passed on the standalone module, including generated interceptors. Developer modules are intentionally non-final where Magento interception requires inheritance.
- Native source alias helper: **7** tests passed for relative creation, idempotency, normalization of known absolute aliases, foreign/escaping path rejection and preservation of a real installed vendor directory.
- Public Composer export: removed the optional module Composer `version` field, installed locked tools in a clean source copy, then built the standalone ZIP using the authoritative bundled `VERSION`. Six actual-builder metadata cases (stripped, matching, conflicting, null, missing and unsafe) passed.
- Native PHP suite: **23 tests / 117 assertions** passed against the installed standalone artifact on the October-patched native fixture.
- Browser loader: **2 Node tests** passed, covering structured configuration, AMD/global exports, CDN and asynchronous mount failure without exposing error contents.
- **PHPStan level 7**, PSR-12 production adapter formatting, PHP/JS/Python syntax checks passed. The sole naming-rule exception is Magento’s required `_getElementHtml` native override.
- Composer audits of the bundled SDK input and locked build tools: **zero advisories**. Source/default-rule merchant-artifact secret scans are run separately and returned no findings.
- Native fixture runtime lock: **15 packages / 47 advisory records**, 4 abandoned packages. These are not bundled plugin dependencies. No claim of an entirely clean Magento platform is made.
- EN/TR docs pass the docs site’s API-path/amount-field checks. Remote CI is defined with pinned actions, native image, source/security hashes and the same fixture; local checks are not a claim that remote PHP 8.5 CI has executed.

## Reproduce

Use only a disposable database named exactly `lunixi_magento`; its account must own only that schema. Store `host`, integer `port`, `database`, `username`, `password` in a private chmod600 JSON file outside public source. Bootstrap refuses another schema or a non-TEST existing native deployment configuration. The installer clears only TEST fixture caches, then rebuilds the disposable native database.

```bash
python3 tools/test-release-metadata.py
python3 tools/test-native-security-aliases.py
composer install --working-dir=tools --no-interaction --no-plugins --no-scripts
php tools/build.php
export LUNIXI_NATIVE_TEST_DESTRUCTIVE=1
export LUNIXI_TEST_DB_CONFIG=/private/path/to/test-db.json
export MAGENTO_ROOT=/absolute/path/to/disposable/magento-2.4.9
export LUNIXI_TEST_MODULE_PATH="$PWD/build/module"
php tools/native-bootstrap.php "$MAGENTO_ROOT"
php -d memory_limit=1G tools/vendor/bin/phpunit --testdox
node --test tests/checkout.test.cjs
tools/vendor/bin/phpstan analyse --memory-limit=1G --no-progress
tools/vendor/bin/phpcs --standard=phpcs.xml
composer audit --working-dir=tools
composer audit --working-dir=build/input --no-dev
gitleaks dir . --config .gitleaks.toml --redact --no-banner
# Scan the merchant tree separately with default rules and no source build exclusions.
(cd /tmp && gitleaks dir /absolute/path/to/module/build/module --redact --no-banner)
```

The actual tests run native order placement, paid invoices, authorization/capture, partial/final fee refunds and real credit memo services, verified void, stale observations, manual hold/unhold, changed order/merchant/store/guest ownership, immutable credential/display rotation, lost create/refund recovery and three concurrent PHP processes consuming one signed event. A real database trigger forces native failure to prove rollback of invoice/order/outbox/inbox. Controller tests cover narrow read-only POST callback, fixed 303, size/path rejection, no field/body/order access and native-session owner-checked GET. Native factories come from Magento’s own generator; no replacement platform API is handwritten. Gateway I/O is deterministic TEST transport using the real scoped SDK.

## Limits

The earlier 63-module fixture did not exercise PHP 8.5 or OpenSearch. The 0.1.4 native acceptance below now covers PHP 8.5.12, MariaDB 12.3.3 and OpenSearch 3.9.0. Full browser/CDN/card checkout, licensed Adobe Commerce/B2B, Hyvä/headless, production SMTP and real 3DS/split/insurance provider flows remain unexecuted. Follow Adobe’s current deployment matrix and security patches, then validate the complete merchant staging workflow. Financial fixtures use virtual products; physical inventory/MSI/RMA customizations require separate staging acceptance. Native refunds are monetary adjustments and never fabricate returned quantities/restocking. Native currency rounding must represent the desired amount exactly; mismatches remain review instead of falsely confirming native bookkeeping.

The module has no live registry/marketplace approval assertion and does not ship Magento platform/vendor dependencies. Customer-facing returns never establish payment success. Third-party observers with external side effects need a durable outbox; uncertain post-commit mail remains review/sending instead of blind duplicate delivery.

## 0.1.3 checkout correction

The embedded form is the new default; optional hosted mode uses the validated backend paymentPageUrl. Its token is independent of the browser token. Native session rehydration decrypts the saved URL. Missing/legacy URL records retain embedded checkout and existing keys; hosted access fails closed. Targeted native suite: 23 tests /117 assertions, PHPStan7, PHPCS22 and browser loader2 checks passed with local shared SDK0.29.5. No new live/licensed/full-store acceptance was run; the earlier platform security limitations remain.

## 0.1.4 native store acceptance (2026-10-31)

A fresh isolated **Magento Open Source 2.4.9** store enabled **223 modules**, indexed the catalog with **OpenSearch 3.9.0**, and ran on **PHP 8.5.12 / MariaDB 12.3 / InnoDB**. The public 0.1.3 merchant ZIP passed its 23 native tests / 117 assertions, but a genuine HTTPS checkout exposed PHP 8.5's deprecated `curl_close()` under Magento's strict error handler. Release 0.1.4 bundles published PHP SDK 0.29.6 to remove that no-op without changing payment requests or idempotency. The release ZIP and published SDK are now available; the publication record below distinguishes archive verification from native/mock acceptance.

The accepted 0.1.4 runtime passed the same 23 native tests / 117 assertions and actual HTTPS native guest-cart creation, item/billing-address selection, payment-method discovery, quote-to-order placement, session/form-key checkout start, merchant-site form container and sensitive response headers. Optional hosted checkout returned the exact independent backend `paymentPageUrl`. Anonymous browser callback fields caused only a same-origin 303 and no provider call; a separate browser session received no checkout token. A signed webhook and authenticated provider GET created exactly one native invoice for TRY 100.50; replay did not duplicate it, tampered bytes were rejected, and the reservation key/request hash were unchanged. The original unbound order created before the fix recovered through a signed HTTP webhook with no replacement intent.

The provider was a dedicated local TLS server that verified actual SDK Ed25519 signatures and body digests, enforced stable-key body identity, and returned controlled settlement evidence. No real money, external email or live customer store was used. Native financial-service tests cover capture/refund/void, rollback and concurrency using their separately injected synthetic transport; these are not claims of a bank or licensed Adobe Commerce acceptance. Browser card entry, physical delivery/MSI/RMA, external 3DS/wallet/split/insurance provider approval, SMTP and licensed Adobe Commerce/B2B remain separate layers.

The current [public 0.1.4 ZIP](https://github.com/Lunixi-Payment/lunixi-magento2/releases/download/v0.1.4/lunixi-magento2-0.1.4.zip) passed public checksum, GitHub digest, CRC and [release CI](https://github.com/Lunixi-Payment/lunixi-magento2/actions/runs/41242098729). Its runtime code matches the locally accepted candidate. The two archive differences are `SECURITY.md` disclosure text and `composer.json` formatting/removal of the optional `version` field; parsed Composer fields otherwise match exactly. This is a runtime-equivalence check, not a claim of repeating the full financial suite after downloading the public ZIP. Previous platform security findings and all browser/licensed/provider limits above remain.
