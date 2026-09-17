# WP AI Bridge

[![CI](https://github.com/ach1992/wp-ai-bridge/actions/workflows/ci.yml/badge.svg)](https://github.com/ach1992/wp-ai-bridge/actions/workflows/ci.yml)
[![Latest release](https://img.shields.io/github/v/release/ach1992/wp-ai-bridge)](https://github.com/ach1992/wp-ai-bridge/releases/latest)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](./LICENSE)

WP AI Bridge connects a WordPress site to ChatGPT through a direct HTTPS MCP endpoint, WordPress-backed OAuth, the official WordPress MCP Adapter, and the WordPress Abilities API.

It exposes bounded, typed site-management abilities while keeping WordPress capabilities and explicit Bridge access groups in control.

## What it provides

- Direct ChatGPT Workspace App connection over HTTPS with OAuth 2.1 and PKCE.
- Read and update posts, pages, supported custom post types, revisions, and Gutenberg blocks.
- Admin-controlled generic post/term metadata access for exact WordPress objects the connected user may edit, including protected/private metadata without provider/post-type/taxonomy/meta-key allowlists.
- Media Library read, upload, safe URL import, update, and delete operations.
- Taxonomy and classic navigation management.
- Bounded WordPress site settings.
- WordPress.org plugin/theme lifecycle operations plus separately authorized bounded external HTTPS package installation.
- Separately enabled installed plugin/theme source read, preview, apply, and conflict-safe recovery using native WordPress authority.
- User and role administration behind an explicit destructive-access group.
- Persistent Workspace documents and tasks for durable project context.
- Native Astra Ability reuse when Astra Abilities are enabled.
- Managed Code Snippets lifecycle support for compatible Code Snippets versions.
- Gravity Forms fallback through GFAPI when no native Gravity Forms Ability surface is active.
- Persian (`fa_IR`) localization and RTL-compatible admin screens.

## Requirements

- WordPress **6.9 or newer**.
- The official [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter), currently validated with `0.6.1`.
- HTTPS and a publicly reachable WordPress REST API for direct ChatGPT Workspace App connections.
- A WordPress account with the capabilities required for the operations you enable.

## Install

1. Download `wp-ai-bridge.zip` from the [latest GitHub release](https://github.com/ach1992/wp-ai-bridge/releases/latest).
2. In WordPress open **Plugins → Add Plugin → Upload Plugin**.
3. Upload the ZIP, install it, and activate **WP AI Bridge**.
4. Install and activate the official WordPress MCP Adapter if it is not already active.
5. Open **WP AI Bridge → Settings**.

Version 0.4.0 establishes the canonical plugin installation identity `wp-ai-bridge/wp-ai-bridge.php`. If migrating from the published 0.3.0 package, follow the one-time Workspace migration procedure in the installation guide instead of using WordPress's replace-existing-plugin flow.

See [Installation and connection](./docs/INSTALLATION.md) for the complete setup.

## Connect ChatGPT

On an HTTPS WordPress site, **WP AI Bridge → Settings** shows the canonical MCP endpoint for the site:

```text
https://YOUR-SITE.example/wp-json/wp-ai-bridge/v1/mcp
```

In a ChatGPT workspace with Developer Mode enabled:

1. Open **Workspace settings → Apps**.
2. Create a custom App.
3. Enter the MCP endpoint shown by WordPress.
4. Choose OAuth authentication and run **Scan Tools**.
5. Sign in to WordPress when prompted.
6. Review the WordPress consent page and authorize ChatGPT.

Version 0.4.0 serves only the canonical `wp-ai-bridge` MCP/OAuth routes. Connections created by the former plugin are intentionally not migrated; reconnect ChatGPT after installing 0.4.0 using the canonical endpoint shown by WordPress.

No tunnel or separate proxy service is required for the direct HTTPS setup.

## WordPress admin

The plugin adds a top-level **WP AI Bridge** menu:

- **Dashboard** — Workspace summary and connection status.
- **Documents** — durable project documents.
- **Tasks** — durable work items with independent progress, review, and delivery state.
- **Activity** — bounded mutation activity.
- **Settings** — ChatGPT connection details and Bridge access groups.

Administration uses only the canonical `wp-ai-bridge...` slugs. Former `wp-native-builder...` admin aliases are not registered by version 0.4.0.

Workspace state is private to WordPress and is not exposed through ordinary post, Gutenberg, or generic metadata abilities.

## Access groups

Bridge permissions are additive to normal WordPress capabilities. Enabling a Bridge group never grants a WordPress capability the connected user does not already have. Advanced Metadata is a deliberate exception to WordPress's generic protected-unregistered-meta default denial: when an administrator enables the group, protected unregistered post/term/user/comment metadata may be accessed through the exact target's native edit authority. Explicit registered/provider metadata authorization contracts remain authoritative.

| Group | Purpose |
| --- | --- |
| **Site Read** | Inspect site information, content, media, navigation, extensions, integrations, and Workspace state. |
| **Builder Write** | Create and update drafts, content, Gutenberg blocks, media, taxonomies, navigation, forms, and Workspace objects. |
| **Remote Media** | Import safe HTTP(S) media into the Media Library; Builder Write and native upload authority are also required. |
| **Live Content** | Permit publishing and other live-content status changes when WordPress also permits them. |
| **Site Configuration** | Permit bounded global WordPress/theme configuration changes. |
| **Advanced Metadata** | Permit generic bounded metadata read/update for exact authorized post, term, user, and comment targets; role/capability/session/application-password/credential-like state, options, and Workspace internals remain excluded. |
| **Authentication & Credentials** | Permit Core-native WordPress Application Password list/get/create/rename/revoke operations. Disabled by default including upgrades; generated plaintext credentials are returned only once on successful create and are never persisted by the Bridge. |
| **Code & Extensions** | Permit supported managed-snippet and plugin/theme lifecycle operations. |
| **External Packages** | Permit plugin/theme installation from an explicit safe public HTTPS package URL. Disabled by default including upgrades; Code & Extensions plus native WordPress install authority are also required. |
| **Source Editing** | Separately permit installed plugin/theme source read/preview/apply/recovery. Code & Extensions and native WordPress source-edit authority are still required. Disabled by default, including upgrades. |
| **Native Abilities** | Permit registered Core/provider Abilities to execute through the WP AI Bridge MCP routes when their own WordPress/provider permission checks also allow it. This is broad registered-operation trust, not a sandbox, and is disabled by default including upgrades. |
| **Comments** | Permit bounded standard-comment discovery, replies, and moderation through the fixed WordPress Core comments REST contract. Disabled by default including upgrades; permanent comment deletion additionally requires Users & Destructive. |
| **Users & Destructive** | Permit user/role administration and destructive operations when WordPress also permits them. Metadata deletion requires this group in addition to Advanced Metadata. |

Only **Site Read** is enabled by default.

## Uploads and extension installation

`wp-ai-bridge/media-upload` accepts file bytes plus a filename and hands them to WordPress Media Library handling. The maximum payload is the smaller of the WordPress upload limit and **20 MiB**. The caller cannot choose a server filesystem path.

`wp-ai-bridge/media-import-url` accepts a public HTTP(S) URL and a filename, streams it within the current WordPress upload limit, and creates a normal attachment. It requires explicit **Remote Media** plus **Builder Write** access; upgrades do not enable it automatically. See [URL media import](./docs/ABILITIES.md#url-media-import).

`wp-ai-bridge/extension-lifecycle` keeps the existing WordPress.org slug installation path under **Code & Extensions**. For a non-WordPress.org source, an administrator must additionally enable **External Packages** and the connected WordPress user must have the native plugin/theme install capability. The caller supplies one exact public HTTPS package URL; the Bridge does not accept local package paths, HTTP/FTP sources, URL credentials, caller-supplied request headers/cookies, or generic transport configuration. The package is streamed into a Bridge-owned temporary file with TLS/redirect/SSRF checks and a maximum of the smaller of the current WordPress upload limit and **100 MiB**, then handed to the native WordPress Core Upgrader. The temporary package is retired and verified before success is returned. Installation does not activate the plugin/theme automatically, and package code has normal WordPress runtime authority once later activated. See [External package installation](./docs/EXTERNAL-PACKAGES.md).

Source Editing remains a separate administrator-level trust boundary and is not enabled by Code & Extensions or External Packages alone. The Bridge does **not** expose a generic HTTP client, arbitrary package request credentials, shell commands, generic SQL, unrestricted filesystem access, arbitrary WordPress options, or generic credential retrieval. WordPress Application Passwords are available only through the separate default-off purpose-specific lifecycle described above.

## Canonical identity and one-time migration

Version 0.4.0 uses WP AI Bridge identity throughout the maintained runtime: the plugin directory/entrypoint is `wp-ai-bridge/wp-ai-bridge.php`, the PHP namespace is `WP_AI_Bridge`, the text domain is `wp-ai-bridge`, Bridge Ability identifiers use `wp-ai-bridge/*`, and admin/OAuth/MCP routes use the `wp-ai-bridge` slug.

For the one published 0.3.0 transition package, uninstalling the former plugin intentionally leaves its private Workspace Documents and Tasks in WordPress. Activating 0.4.0 migrates only those Workspace records to the canonical identifiers while preserving their IDs, content, state hashes, and versions. When legacy Workspace records exist, the migration first verifies that the site's exact WordPress `posts`, `postmeta`, and `options` tables use InnoDB; activation stops before identity changes if rollback-capable storage cannot be established. Clean installs with no legacy Workspace state are not subject to this migration-only engine guard. Former access-group settings, activity data, OAuth clients/tokens, and other connection/runtime state are not imported. Reconfigure the desired access groups and reconnect ChatGPT against the new canonical MCP endpoint after migration.

## Optional integrations

Integration is discovery-first: provider-owned public WordPress Abilities are reused at runtime, so a compatible new plugin or theme should normally require no Bridge-specific code. Remote execution of those registered provider/Core Abilities through the Bridge requires explicit **Native Abilities** access in addition to the provider's own permission callback. Provider fallbacks are reserved for bounded gaps with documented public APIs. Generic post, term, user, and comment metadata is provider-neutral and does not require a new Bridge adapter merely because a plugin/theme stores state in `post_meta` or `term_meta`. See [Architecture](./docs/ARCHITECTURE.md#discovery-and-reuse).

- **Astra / Astra Pro:** enable Astra's **Abilities** setting. A separate Astra MCP server is not required for this Bridge setup.
- **Code Snippets:** compatible provider APIs are used for managed snippet lifecycle; the Bridge does not directly evaluate submitted code.
- **Gravity Forms:** native provider Abilities are preferred; otherwise the bounded GFAPI fallback is used when available.
- **WooCommerce:** verified provider Abilities may be reused. Broad commerce/customer/order fallbacks are not exposed automatically.

See [Integrations](./docs/INTEGRATIONS.md) for details.

## Security model

WP AI Bridge is intentionally not a general remote-administration shell. It combines:

- WordPress OAuth identity;
- WordPress capabilities and object-level checks;
- explicit Bridge access groups;
- closed input/output schemas;
- stale-write/concurrency protection for overwrite-sensitive operations;
- bounded mutation logging;
- provider-native permission checks where integrations are used.

Read [Security](./docs/SECURITY.md) before enabling write, Advanced Metadata, Authentication & Credentials, External Packages, Source Editing, Native Abilities, or destructive access on an important site.

## Documentation

- [Installation and connection](./docs/INSTALLATION.md)
- [User guide](./docs/USER-GUIDE.md)
- [Ability reference](./docs/ABILITIES.md)
- [External package installation](./docs/EXTERNAL-PACKAGES.md)
- [Application Password boundary](./docs/ABILITIES.md#application-password-boundary)
- [Integrations](./docs/INTEGRATIONS.md)
- [Security](./docs/SECURITY.md)
- [Architecture](./docs/ARCHITECTURE.md)
- [Troubleshooting](./docs/TROUBLESHOOTING.md)
- [Development and testing](./docs/DEVELOPMENT.md)
- [Changelog](./CHANGELOG.md)

## License

WP AI Bridge is licensed under **GPL-2.0-or-later**. See [LICENSE](./LICENSE).

## Author

**ACh** — https://ach.li
