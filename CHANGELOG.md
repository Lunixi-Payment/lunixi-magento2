# Changelog

## 1.0.2 — 2026-11-01

- Keep the real native refund integration assertion strict against the canonical string-money response.
- Require the tested source commit to match the release tag before publishing the immutable ZIP.

## 1.0.1 — 2026-11-01

- Validate native decimal conversion with canonical strings, including values above the JavaScript safe integer boundary and numeric input rejection.
- Retry transient official security-patch download failures within a fixed attempt/delay budget; keep the pinned archive checksum and strict patch verification.

## 1.0.0 — 2026-11-01

- Breaking: monetary minor units are canonical integer strings in requests, responses and platform settlement records. Numeric money is rejected; counts, rates and quantities retain their existing types.
- Convert persisted decimal text with the currency exponent; retain exact values above the JavaScript safe-integer limit up to signed64 for fiat. Capture/refund omission retains its existing meaning; explicit zero is invalid.
- Align with PHP SDK 1.0.0 and preserve raw webhook signatures, merchant identity checks, operation keys and order-state ownership. See [MIGRATION-1.0.md](MIGRATION-1.0.md) before upgrading.

## 0.1.4 — 2026-10-31

- Bundle candidate PHP SDK 0.29.6 to preserve real PHP 8.5 network transport under Magento's strict error handler; deprecated cURL cleanup no longer interrupts a successful or failed request.
- Preserve existing pending reservations, payment bodies, encrypted profiles and idempotency keys through transport recovery. No payment retry or new financial operation is introduced.

## 0.1.3 — 2026-10-31

- Default new checkout configuration to the existing Lunixi form on the merchant site; retain the optional hosted selector and explicit merchant settings.
- Redirect only to a validated backend `paymentPageUrl`; its opaque token can differ from the embedded form token. Missing URLs keep embedded checkout available and hosted checkout on hold without creating another intent/key.
- Preserve historical checkout modes, encrypted credentials/tokens, immutable request bodies and existing idempotency/3DS/split return behavior.
- Bundle shared PHP SDK 0.29.5 with validated URL handling and encrypted ledger persistence.

## 0.1.2 — 2026-10-31

- Use root-confined relative native-source aliases for Ubuntu 24 GNU patch 2.7.6; normalize known absolute aliases from a failed fixture bootstrap.
- Keep all five checksum-pinned official security patches unchanged with strict zero-fuzz application; add alias confinement/idempotency regressions and retain both ends of patch failure diagnostics.
- Keep shared PHP SDK 0.29.3 and native financial behavior unchanged.

## 0.1.1 — 2026-10-31

- Read the authoritative bundled `VERSION` file when Composer exports omit `version`; validate the Composer value only when present.
- Add real builder regressions for stripped/matching/conflicting/missing release metadata and verify a clean public export.
- Keep shared PHP SDK 0.29.3 and the existing native financial behavior.

## 0.1.0 — 2026-10-31

- Native Magento Payment Adapter, hosted/embedded shared form and encrypted admin credential entry.
- Shared PHP SDK 0.29.3 with durable attempts/operations, authenticated settlement, split-parent proof and v2 webhooks.
- Native invoice/monetary credit memo application on the same database connection, CSRF/ACL admin actions and post-commit notification outbox.
- Read-only POST-to-303 callback adapter and native owner/session-checked GET; structured browser SDK configuration and AMD/global loader recovery.
- English/Turkish UI, original Lunixi logos, standalone scoped-SDK ZIP, native-source/database tests and verification record.
