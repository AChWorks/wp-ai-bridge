# WP AI Bridge

[![Latest release](https://img.shields.io/github/v/release/AChWorks/wp-ai-bridge)](https://github.com/AChWorks/wp-ai-bridge/releases/latest)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](./LICENSE)

**Connect compatible AI assistants and approved integrations to your WordPress site, on your terms.** WP AI Bridge lets authorized clients discover and perform supported WordPress administration through native WordPress permissions and access groups controlled by the site administrator.

The plugin is free and self-hosted. A direct **ChatGPT** connection is tested; a separate Gateway is optional. Other compatible clients and services can use approved connections where their authentication and transport actually interoperate. **Not every administrator operation or future service workflow is available yet.**

## Requirements

- **WordPress 6.9 or newer**.
- The separate official [WordPress MCP Adapter](https://wordpress.org/plugins/mcp-adapter/) plugin, installed and active for MCP connections. Published integration tests cover Adapter 0.6.1, 0.7.0 and 0.7.1.
- HTTPS and a publicly reachable WordPress REST API for direct ChatGPT access.
- A WordPress account with the capabilities needed for the operations you authorize.

## Install

1. Download the official **`wp-ai-bridge.zip`** from the [latest stable GitHub Release](https://github.com/AChWorks/wp-ai-bridge/releases/latest). **Do not download “Source code (zip)”; it is not the installable plugin package.**
2. In WordPress open **Plugins → Add Plugin → Upload Plugin**, select the ZIP and install it.
3. Activate WP AI Bridge and the official MCP Adapter.
4. Visit **WP AI Bridge → Settings** to review the connection details and access groups.

Upgrading? Back up your site and database first, then use WordPress's normal **Replace current with uploaded** flow, without uninstalling the old plugin. Existing canonical settings, OAuth identity and private Workspace are intended to remain intact; verify them after upgrading.

If you still use the historical v0.3.0 installation and need to preserve old Workspace data, **follow the special v0.4.0 migration first**. See [complete installation and upgrade instructions](./docs/INSTALLATION.md).

## Connect ChatGPT directly

Your site's settings screen displays the actual MCP endpoint, in this form:

```text
https://YOUR-SITE.example/wp-json/wp-ai-bridge/v1/mcp
```

In a ChatGPT workspace with Developer Mode enabled:

1. Open **Workspace settings → Apps** and create a custom App.
2. Enter your WordPress MCP endpoint and select OAuth authentication.
3. Scan Tools, sign in to WordPress, then review and approve the consent screen.
4. Back in WordPress, enable only the access groups needed for your intended work.

OAuth consent does **not** automatically enable Bridge write privileges or grant missing WordPress capabilities. Another compatible OAuth client must have its own administrator-approved identity; it cannot borrow ChatGPT's connection. See [approved OAuth clients](./docs/OAUTH-CLIENTS.md) and [connection troubleshooting](./docs/TROUBLESHOOTING.md).

## What you can do

The actual operations available depend on your site's WordPress/plugins/themes, the connected user's WordPress authority and the Bridge access groups you enable.

- **Content:** read and manage supported posts, pages, custom post types, revisions, Gutenberg blocks, taxonomies and navigation.
- **Media:** inspect and manage the Media Library; upload files and import media from approved public HTTP(S) URLs.
- **Site administration:** manage supported settings, comments, user operations, registered Core/provider Abilities, and eligible registered REST operations under the applicable extra permissions.
- **Extensions:** manage supported plugins, themes and managed snippets. Explicit additional grants enable approved external package installation or installed plugin/theme source read/edit/recovery.
- **Project continuity:** keep private Workspace documents and tasks inside WordPress, with project references, compact resume, guarded editing and archival.
- **Supported data transfer:** exchange bounded text and file content through authenticated private-transfer workflows and native Media/WXR operations, where the client/hosting transport supports them.
- **Optional providers:** reuse applicable Abilities/APIs from installed integrations such as Astra, Code Snippets and Gravity Forms.

The Bridge's WordPress-side file-transfer capabilities do **not** prove that every AI client or optional Gateway can send or receive native attachments. Check the specific client/transport before relying on a file workflow. See the [Ability reference](./docs/ABILITIES.md), [data exchange guide](./docs/LARGE-PAYLOADS.md) and [integrations](./docs/INTEGRATIONS.md).

## Permissions you control

Go to **WP AI Bridge → Settings**. **Only Site Read is enabled by default.** Every other access group is an administrator choice, and none overrides the connected WordPress account's own permissions.

| Access group | Intended use |
| --- | --- |
| **Site Read** | Inspect permitted site, content, media and extensions. |
| **Builder Write** | Create and edit supported content, Media and Workspace data. |
| **Remote Media** | Import safe remote media; also requires Builder Write and native upload permission. |
| **Live Content** | Publish content and change live status when WordPress permits. |
| **Site Configuration** | Configure supported WordPress and provider settings. |
| **Advanced Metadata** | Work with authorized object metadata, excluding credentials and sessions. |
| **Authentication & Credentials** | Manage WordPress Application Passwords using native APIs. |
| **Code & Extensions** | Perform supported plugin/theme and snippet lifecycle operations. |
| **External Packages** | Install or deliberately replace plugins/themes from approved HTTPS packages, with Code & Extensions and native installer authority. |
| **Source Editing** | Read, preview, apply and recover installed plugin/theme source, with Code & Extensions and native source-editor authority. |
| **Native Abilities** | Invoke applicable registered WordPress/provider Abilities. |
| **Registered REST Discovery** | Inspect registered REST operations without executing them. |
| **High-Trust Registered REST Invocation** | Invoke guarded registered REST operations under additional high-trust grants and native WordPress checks. |
| **Comments** | Read, reply to and moderate supported comments. |
| **Users & Destructive** | Administer authorized users/roles and perform destructive actions. |

**Source Editing and External Packages can execute code with WordPress's ordinary runtime privileges.** They are not sandboxes. Enable high-trust groups only for appropriate accounts and approved clients. See the [security guide](./docs/SECURITY.md) and [user guide](./docs/USER-GUIDE.md).

## Updates from GitHub

Starting with the **published v0.6.8 release**, an **active** WP AI Bridge can discover newer, official **stable GitHub Releases** in WordPress's standard plugin update checks. WordPress Core manages upgrade notices, permission checks, package installation and optional auto-updates; the Bridge does not turn automatic updates on for you.

A site still running **v0.6.7 or earlier needs one manual upgrade** to v0.6.8 or newer using the official installable ZIP and WordPress's replacement flow, because the older version does not contain the GitHub update callback. An **inactive** Bridge cannot register that callback and must also be updated manually. GitHub API failures defer discovery rather than downloading an unverified source archive.

GitHub Releases is the maintained distribution channel. WordPress.org Plugin Directory listing is **not** claimed; it depends on separate review and acceptance of the full-feature plugin.

## Help and documentation

- [Installation, updates and connection](./docs/INSTALLATION.md)
- [Using the WordPress Workspace](./docs/USER-GUIDE.md)
- [Supported operations and permissions](./docs/ABILITIES.md)
- [Authentication and approved clients](./docs/OAUTH-CLIENTS.md)
- [Large text, media and file exchange](./docs/LARGE-PAYLOADS.md)
- [External plugin/theme packages](./docs/EXTERNAL-PACKAGES.md)
- [Security and privacy](./docs/SECURITY.md)
- [Troubleshooting](./docs/TROUBLESHOOTING.md)
- [Release history](./CHANGELOG.md)

**For maintainers:** Start with the [canonical product specification](./MASTER-SPEC.md), [architecture](./docs/ARCHITECTURE.md) and [development/testing guide](./docs/DEVELOPMENT.md). Recover unfinished acceptance from [GitHub Issues](https://github.com/AChWorks/wp-ai-bridge/issues), candidate changes from [Pull Requests](https://github.com/AChWorks/wp-ai-bridge/pulls) and shipped artifacts from [Releases](https://github.com/AChWorks/wp-ai-bridge/releases). A merged source PR is not proof of a live-site installation; an Owner-parked Issue is not permission to start development.

**License:** GPL-2.0-or-later — [LICENSE](./LICENSE) · **Author:** [ACh](https://ach.li)
