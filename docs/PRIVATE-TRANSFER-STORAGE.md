# Portable private-transfer storage (#134)

> **Engineering acceptance contract — not an implemented/released fix.** Adopted on 2026-10-15. This document is for the next implementation Master; it does not describe a feature already shipped by v0.6.5.

## Outcome and scope

WP AI Bridge must stage private binary, text, Media, source snapshots and WXR on ordinary supported WordPress hosting with **zero mandatory host setup**. The owner has no host SSH/shell; the control panel might be cPanel, DirectAdmin, Plesk or something else; the server might run Apache, Nginx or LiteSpeed. Never assume a fixed account/webroot path, public_html, system `/tmp`, a webserver rule, an externally provisioned directory or a writable system temp directory.

Use standard WordPress APIs to determine the current site's content directory, upload settings and database prefix. Handle multisite, relocated `WP_CONTENT_DIR`, filtered/custom `wp_upload_dir()`, read-only folders and realistic PHP/database/hosting limits. A new Bridge private folder **must not be required outside** the WordPress content/upload tree; no directory is needed when storage is WordPress DB-backed.

Public Media and private transfer are distinct:

- **Public/explicitly publishable files:** reuse native Media Library through existing `media-upload`, safe `media-import-url`, `media-read`, `file-bytes-read` and exact `media-delete`. These already passed real installed-site Gateway roundtrips; ordinary Media URLs may be public. Do not create a parallel Media manager.
- **Private bytes:** keep the authenticated `private-transfer` lifecycle and separate purpose-specific consumers. Never silently put private WXR, source files, confidential material or incomplete leases in public Media URLs.

A folder named "private" under `wp-content/uploads` is **not confidential merely because of chmod 0700, index.php or .htaccess**. Static file serving, Nginx and CDN/offload must not reveal raw staged bytes or private metadata. No plaintext in web-addressable storage without demonstrably robust server-independent confidentiality. Fail closed rather than weaken this property.

## Current failure (verified baseline)

Published/installed **v0.6.5**, main `8d9ae91dc8191027d1d29c8b3e5e28b2aefb782b`: `src/Support/class-private-transfer-session.php::root()` resolves `get_temp_dir()`, builds a site-scoped private filesystem folder and rejects any path inside `ABSPATH`, `WP_CONTENT_DIR` or `DOCUMENT_ROOT`. WordPress Core's documented `get_temp_dir()` fallback can be **WP_CONTENT_DIR** when earlier temp paths are not usable. On authenticated Gateway Target `ut-alumni-bonyadut`, a zero-byte text begin and one-byte Media begin both returned Bridge diagnostic code `transfer_storage_unsafe`. Which individual host path/permission check failed is not yet proven. No host SSH or PHP-settings intervention is an accepted prerequisite.

WordPress source documentation:
- https://developer.wordpress.org/reference/functions/get_temp_dir/
- https://developer.wordpress.org/reference/functions/wp_upload_dir/
- https://developer.wordpress.org/plugins/plugin-basics/activation-deactivation-hooks/
- https://developer.wordpress.org/plugins/plugin-basics/uninstall-methods/

## Implementation direction (do not assert completed)

Keep the existing public Ability schemas and site/principal/OAuth-client/purpose-bound lease semantics. Introduce only the backing storage abstraction needed to make them host-neutral; inspect **all** call sites of `Private_Transfer_Session`, private artifact writer, Media/text/WXR/source consumers and effect-claim journaling first.

**Preferred portable private fallback: bounded WordPress database staging** (or an equivalently demonstrated truly private WordPress-owned backend). Avoid public filesystem plaintext; no extra daemon/service, host credentials, autoloaded multi-MiB `wp_options` values or unreliable cache/transient persistence. If database schema/tables are required, use normal WordPress install/upgrade/multisite lifecycle with bounded site-local records, safe cleanup/expiry and verified effective database capabilities. Preserve the already-reviewed native effect fence, crash/unknown outcome, concurrent admission, quota/revocation, byte offset/replay and SHA256 guarantees. Unsupported database concurrency/durability semantics must fail before any irreversible WordPress native action.

A filesystem strategy is optional only when confidentiality is independently defensible on **all actual delivery surfaces**, including static URLs, server variants and offload. It cannot simply move the current unencrypted temporary store under uploads. Existing valid filesystem leases must be migrated, supported until retirement or safely reconciled; never silently lose/replay a claimed native effect.

**wp-config.php advanced override:** zero-configuration must be the default. A Bridge-specific read-only setting for selecting an approved storage backend may be added if warranted, with documented values and validation after implementation. Do not expose fictional constants before they work; never rewrite `wp-config.php` automatically. Do not require global `WP_TEMP_DIR` changes: that affects WordPress Core and unrelated plugins too. Existing finite Bridge Administrator transfer-capacity settings remain the owner for quotas/TTL.

## Mandatory acceptance before an integration decision

1. Disposable real WordPress+official MCP Adapter: fresh install with no host setup; force `get_temp_dir()` to resolve under `WP_CONTENT_DIR`, simulate constrained `open_basedir`; valid authenticated 0-byte, 1-byte, 128-KiB and multi-MiB Media/text/private source transfers work with byte parity. Include Apache and Nginx/LiteSpeed/static-server representations, customized WordPress content and multisite paths.
2. Prove no direct HTTP access to private plaintext or metadata even with `wp-content/uploads` publicly served, regardless of .htaccess/CDN/offload. Test wrong site/principal/OAuth client, revoked grants, malformed windows, stale resume, hash/size conflict and lease expiry.
3. Reproduce and preserve concurrent writers/leases, exact idempotent append, capacity/DB constraints, cancel and expiry cleanup, interrupted writes, deterministic durable one-way effect claims and `outcome_unknown` receipts for Media/text/WXR/source consumers. No blind retry, orphaned private payload or unbounded memory/storage.
4. Preserve current public Media integration and optional HTTPS URL import; do not reimplement their API or claim chat-native files are supported by a Base64 roundtrip.
5. Run focused tests then full exact candidate WordPress/Adapter CI. Demand **fresh author-separated HIGH_ASSURANCE independent review** before merging a storage security boundary; obtain separate owner authorization for protected main integration and publication; do not deploy a partial feature to the live alumni site.

## Cross-repository boundary / evidence

- Controlling Issue: https://github.com/AChWorks/wp-ai-bridge/issues/134
- Gateway client-selected / native ChatGPT file handoff: https://github.com/AChWorks/mcp-gateway/issues/147
- Gateway per-client/per-hop size matrix: https://github.com/AChWorks/mcp-gateway/issues/154
- Gateway Owner settings: https://github.com/AChWorks/mcp-gateway/issues/153 (no change currently justified)

Public Media on the real installed site already passed a 69-byte inline PNG and 17,792-byte safe public HTTPS image import, `file-bytes-read`, checksum and exact deletion. **Actual WordPress private transfer still fails**, and native attachment handoff is a separate Gateway gap. These results and owner constraints are in the controlling Issues. The current PR/branch may initially contain only this contract; implementation and tests must be added before requesting review/merge.
