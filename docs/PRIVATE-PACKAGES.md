# Source-agnostic private ZIP packages — Issue #108

This describes a source implementation candidate, **not** a published release or deployed-site capability. Installing plugin/theme code is an administrator-trust operation, not a sandbox.

## Bytes transport and ownership

In the WordPress dashboard, **WP AI Bridge → Private ZIP Packages** uploads a plugin or theme ZIP through Bridge's own logged-in, nonce-checked admin-post action. The archive can originate from an AI, human, vendor or developer; its origin never substitutes for authorization. Files are staged outside the public web root and are **not installed** at upload time. Neither a public download URL nor an AI-controlled local file path is required.

The uploading administrator selects a currently approved OAuth client. The staged artifact is bound to the exact WordPress user, site/blog and selected client ID plus approval revision. Revoking an extra client approval invalidates its staged artifacts. The approved, authenticated MCP client can then use:

- `wp-ai-bridge/private-packages-read` — `{"action":"list"}` or `{"action":"get","artifact_id":"..."}` — to see bounded, path-free metadata and exact SHA-256;
- `wp-ai-bridge/private-package-install` — explicit `artifact_id`, `kind` and `sha256` — to hand only the exact reviewed artifact to native WordPress Core Upgrader for installation, **never activation**.

The old `extension-lifecycle` WordPress.org slug and safe public HTTPS source paths remain unchanged.

**Transport limitation:** ChatGPT/MCP Adapter and the current Gateway typed JSON Ability model are not assumed to support ZIP bytes or multipart attachments. Browser multipart upload is a supported **Bridge-owned transfer path**, not a wp-admin-only installation workaround. Gateway attachment streaming needs a separately verified client/Target-scoped handoff contract coordinated by Gateway #130; no cross-repository compatibility is claimed here.

## Authorization and bounded validation

Both default-off **Code & Extensions** and **External Packages** grants, `manage_options`, and the kind-specific native `install_plugins` / `install_themes` capability are required. `DISALLOW_FILE_MODS` denies staging/installation. The admin browser upload requires a WordPress login session and CSRF nonce. AI Ability calls additionally require the currently validated OAuth/MCP client context; there is no caller-supplied client ID, bearer upload route, arbitrary filesystem path or raw byte argument.

- Private directory: server-owned, mode 0700, outside web roots; ZIP files are mode 0600 and identified by random 48-character hex IDs.
- Maximum compressed ZIP length: 64 MiB and no higher than `wp_max_upload_size()`. Max total uncompressed entries: 256 MiB and 4096 entries, with per-entry size, expansion ratio, file/directory-collision and path limits.
- Structural review: PHP ZipArchive required; deny path traversal, symlinks/special types, duplicate/colliding entries, inconsistent roots, unreadable/encrypted entries, absent plugin/theme headers, and invalid theme layouts; WordPress Core remains the package validation authority.
- Staging: 1-hour TTL, at most 4 open records per user and 24 per blog; a bounded per-blog MySQL/MariaDB advisory lock serializes quota allocation across concurrent WordPress workers and fails closed if unavailable. WordPress cron retires expired files and bounded outcome records.

**Multi-node deployment boundary:** the quota lock is database-wide, but ZIP bytes reside in a per-PHP-host private temporary directory. A WordPress installation spread across independent PHP hosts without shared private staging/sticky routing cannot yet promise that an artifact uploaded on one host will be installable from another. This candidate does not silently claim distributed file-storage support.

AI-visible responses contain metadata only. They never contain uploaded bytes, token values or local paths. Successful Core installs and recovery-required outcomes also produce bounded, credential-free entries in the existing Bridge mutation log.

## Installation, recovery and release boundary

Immediately before executing Core, the Bridge rechecks the user/site/client identity, current approval, all grants/native capabilities, SHA-256/byte length and archive structure. A once-only, atomic option insertion claims the exact artifact, denying concurrent/replayed installation. After the Core boundary, any ambiguous result or cleanup problem returns a bounded recovery-required error, retains the claim, and prohibits blind retry. Installation is never automatic activation.

Issue #108 uses real isolated WordPress tests for plugin/theme installation via native WordPress Abilities and the official MCP Adapter, and a native login/cookie/nonce/multipart browser-upload test. These are not proof of production deployment or an arbitrary AI client binary-upload protocol. Multisite install denial, concurrent quota protection, recovery and final exact-candidate CI/review remain subject to acceptance evidence. Independent author-separated **HIGH_ASSURANCE** review precedes any separately approved merge. Release, production rollout and live-site installation remain outside this source-development authorization.
