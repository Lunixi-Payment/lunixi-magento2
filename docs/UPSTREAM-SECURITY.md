# Upstream native TEST-fixture dependency audit

This is the official Magento Open Source 2.4.9 source-lock runtime dependency inventory, audited with `composer audit --no-dev --format=json` on 2026-10-31. These packages are **not shipped in the Lunixi module ZIP**. The plugin SDK/build-tool audits separately report zero advisories.

All 15 affected packages below are in the native fixture’s production/runtime dependency section, not dev-only. Some, including Composer, are primarily used by platform setup/admin tooling but are nevertheless installed by the platform runtime lock. No blanket vendor update was performed.

| Package/version | Reported severities | Advisory identifiers |
| --- | --- | --- |
| `composer/composer` 2.9.5 | high, medium | PKSA-ddsx-fjz2-sbs3, PKSA-ym19-cy3j-z6df, PKSA-q3ht-3g42-rg8f, PKSA-4pm6-g63v-5rkr, PKSA-zcdk-qnhk-hq2g, PKSA-pwvr-3754-v57r, PKSA-t5r2-p5q9-mtpn, PKSA-6bp1-9hfj-2cgv |
| `guzzlehttp/guzzle` 7.10.0 | high, medium | PKSA-gcrk-3vtt-1r14, PKSA-cnw1-2ytm-cgr8, PKSA-fy2t-3c5f-827y, PKSA-qxvb-2bpp-dnk6, PKSA-bbs6-q5q9-f3t4, PKSA-bcdd-5xc7-gwfb, PKSA-pwsk-hy21-4gby, PKSA-93qv-9n9h-6k6p, PKSA-k22t-f949-t9g6 |
| `guzzlehttp/psr7` 2.9.0 | medium | PKSA-vznr-tgp9-fd7d, PKSA-7qs6-zvnz-h66r, PKSA-gm5x-j3mz-71n9, PKSA-jj5t-2zs1-dcfm |
| `league/flysystem` 3.32.0 | low | PKSA-w9tt-7782-78jx |
| `mtdowling/jmespath.php` 2.8.0 | critical | PKSA-mnyp-475s-ywph |
| `phpseclib/phpseclib` 3.0.49 | high, low, medium | PKSA-12sz-bcny-pk4m, PKSA-432p-hv1d-chf7, PKSA-smrh-yx37-92ws, PKSA-zh4j-by9m-7mz8, PKSA-km2b-zc3b-mjm3 |
| `symfony/cache` v7.4.7 | medium | PKSA-z7t6-zt6p-wtng |
| `symfony/http-foundation` v7.4.7 | high, medium | PKSA-xmwy-rmx5-m7h4, PKSA-y6py-qpv1-h52p |
| `symfony/http-kernel` v7.4.7 | high | PKSA-dw7n-x7f5-zf63 |
| `symfony/mailer` v7.4.6 | medium | PKSA-28rh-rzzn-djk4 |
| `symfony/mime` v7.4.7 | high, medium | PKSA-wtxr-p26d-nn42, PKSA-2n2k-66v2-bwg3 |
| `symfony/polyfill-intl-idn` v1.33.0 | low | PKSA-dwsq-ppd2-mb1x |
| `symfony/yaml` v7.4.6 | high, low | PKSA-xz3r-9gp4-4m1z, PKSA-v5yj-8nmz-sk2q, PKSA-ft77-7h5f-p3r6, PKSA-b14r-zh1d-vdrc |
| `web-token/jwt-framework` 4.1.3 | high, medium, unclassified | PKSA-ztxk-m2k2-bkgv, PKSA-m2bn-5kyy-vzsk, PKSA-p7vh-1fk6-znth, PKSA-815n-fyy9-rqkd |
| `webonyx/graphql-php` v15.30.2 | high, medium | PKSA-xwpn-zs9j-6wy5, PKSA-sf9j-1gs7-xzvx, PKSA-7h5p-prw9-w5nr |

Total: **15 runtime packages / 47 advisory records**. The lock audit also reports **4 abandoned runtime packages**: `laminas/laminas-config`, `laminas/laminas-json`, `laminas/laminas-loader`, `laminas/laminas-text`.

## Official platform resolution and verification

Adobe’s current stable 2.4.9 branch uses monthly isolated fixes rather than a 2.4.9-p1 Composer release. Apply the correct CE/EE/component patches in release order, plus the separately required CVE-2026-75650 hotfix, using Adobe’s official instructions. The TEST fixture applies the original checksum-pinned July CE, August CE, VULN-39341_249, September CE and October CE patch files without rewriting their hunks. This does not imply that every third-party Composer advisory above is fixed or suppressed.

Use the current Adobe-supported Composer distribution/dependency set and security patches for the actual deployment; ask Adobe Support for the supported resolution where a platform constraint prevents a dependency fix. Re-run both the Composer audit and Commerce Version Tool on that real installation. Do not blindly update the platform vendor tree or mark these advisories resolved based only on plugin tests.

Sources: [October APSB26-153 instructions](https://experienceleague.adobe.com/en/docs/experience-cloud-kcs/kbarticles/ka-49323), [separate hotfix](https://experienceleague.adobe.com/en/docs/commerce-knowledge-base/kb/announcements/commerce-apsb26-146), [system requirements](https://experienceleague.adobe.com/en/docs/commerce-operations/installation-guide/system-requirements).

The official `vendor/bin/patch-status` tool was executed locally. On the GitHub monorepo fixture it returns base_version `unknown` and warns that no recognized Commerce base package exists in composer.lock. It therefore does **not** certify this fixture as PROTECTED. Patch application is evidenced separately by zero-failure original hunks and recorded hashes of every patched file. Licensed Composer/Commerce acceptance and an official CVT result remain deployment checks.
