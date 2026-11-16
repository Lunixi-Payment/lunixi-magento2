# Changelog

## 1.1.1 — 2026-11-16

- Test: check the native expired-order selection instead of running the cancel cron inside the test container.
- Build tools: `symfony/filesystem` 7.4.23 (security advisory GHSA-6prc-h956-hxjq; not shipped in the module ZIP).

## 1.1.0 — 2026-11-16

- Built on PHP SDK 1.6.0.
- Bank transfers: while the authenticated payment is `PROCESSING`, the order moves from `pending_payment` to `payment_review` (marked by the module), so Magento's pending-payment cleanup no longer cancels it while the money is on the way (1 to 3 business days). A completed payment is invoiced as before; an expired or failed one returns the order to `pending_payment` for the native cleanup.
- Money captured for a canceled order is no longer kept silently: the order stays canceled and uninvoiced, with an order comment and an admin notification. Each notice is written once.
- A late payment accepted as another payment for the same order (shared SDK linked-payment review decision) holds the order when possible, with a comment naming the new payment ID and an admin notification. The bound payment is unchanged and no invoice is created. Takes effect with a bundled SDK that includes linked-payment decisions.

## 1.0.3 — 2026-11-01

- Format the exact-money native order/runtime expressions to satisfy strict release lint; all non-whitespace PHP tokens and financial behavior are unchanged.

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
