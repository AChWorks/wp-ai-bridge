# WP AI Bridge

[![Latest release](https://img.shields.io/github/v/release/AChWorks/wp-ai-bridge)](https://github.com/AChWorks/wp-ai-bridge/releases/latest)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](./LICENSE)

**Connect compatible AI assistants and approved integrations to your WordPress site, on your terms.** WP AI Bridge lets authorized clients discover and perform supported WordPress administration through native WordPress permissions and access groups controlled by the site administrator.

The plugin is free and self-hosted. A direct **ChatGPT** connection is tested; a separate Gateway is optional. Other compatible clients and services can use approved connections where their authentication and transport actually interoperate. **Not every administrator operation or future service workflow is available yet.**

**Advanced administration uses explicit delegation.** Separate default-off grants cover database inspection/read/write/delete/schema/native-account SQL; scoped files and sensitive logs; native scheduler management; theme mods/transients/Customizer; and quiescent database/files capture with isolated restore. Configure explicit roots and restore schemas under **WP AI Bridge → Settings → Files & backups**. Native-account SQL additionally requires a hosting constant and explicit acknowledgement of the database account’s broader privileges. See the [administration guide](./docs/ADMINISTRATION.md), [database boundary](./docs/DATABASE-ADMINISTRATION.md) and [backup contract](./docs/BACKUP-RESTORE.md). Check the installed version and current grants before relying on a workflow.

**Native settings access includes unregistered and sensitive site/network options.** Separate site/network read and write consents protect effective option reads, complete private snapshots and one native add/update/delete family with durable historical recovery. Native callbacks remain nontransactional. See [reads and snapshots](./docs/NATIVE-OPTIONS.md) and [mutations, complete inputs and recovery](./docs/NATIVE-OPTION-MUTATIONS.md).

## Requirements

- **WordPress 6.9 or newer**.
- The separate official [WordPress MCP Adapter](https://wordpress.org/plugins/mcp-adapter/) plugin, installed and active before activating WP AI Bridge. Integration tests cover Adapter 0.6.1, 0.7.0 and 0.7.1.
- HTTPS and a publicly reachable WordPress REST API for direct ChatGPT access.
- A WordPress account with the capabilities needed for the operations you authorize.

## Install

1. Download the official **`wp-ai-bridge.zip`** from the [latest stable GitHub Release](https://github.com/AChWorks/wp-ai-bridge/releases/latest). **Do not download “Source code (zip)”; it is not the installable plugin package.**
2. In WordPress open **Plugins → Add Plugin → Upload Plugin**, select the ZIP and install it.
3. Install and activate the official **MCP Adapter**, then activate **WP AI Bridge**. WordPress identifies a missing or inactive Adapter as a required dependency on the Plugins screen.
4. Visit **WP AI Bridge → Settings** to review the connection details and access groups.

Upgrading? Back up your site and database first and ensure the official MCP Adapter is installed and active, then use WordPress's normal **Replace current with uploaded** flow, without uninstalling the old plugin. Existing canonical settings, OAuth identity and private Workspace are intended to remain intact; verify them after upgrading.

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
- **Database and configuration:** inspect installation/plugin tables, read exact values, execute typed row/schema changes, and use separately enabled native-account SQL. Native options, current-theme modifications, transients and compatible registered Customizer settings retain their own WordPress/provider lifecycle.
- **Files, logs and scheduling:** read or change files under approved roots, tail authorized file logs and manage supported Core/Action Scheduler events through native contracts.
- **Backup and recovery:** capture approved database objects and installation files under declared quiescence, then verify an isolated restore into an approved empty schema and nonserved directory. Live activation is separate.
- **Extensions:** manage supported plugins, themes and managed snippets. Explicit additional grants enable approved external package installation or installed plugin/theme source read/edit/recovery.
- **Project continuity:** keep private Workspace documents and tasks inside WordPress, with project references, compact resume, guarded editing and archival.
- **Supported data transfer:** exchange bounded text and file content through authenticated private-transfer workflows and native Media/WXR operations, where the client/hosting transport supports them.
- **Optional providers:** reuse applicable Abilities/APIs from installed integrations such as Astra, Code Snippets and Gravity Forms.

The Bridge's WordPress-side file-transfer capabilities do **not** prove that every AI client or optional Gateway can send or receive native attachments. Check the specific client/transport before relying on a file workflow. Native ChatGPT `fileParams` attachment ingress via the optional Gateway is **PARKED / currently unsupported** as of Gateway v2.3.6; its host-to-MCP handoff has not been reliably verified. This does not disable existing authenticated private-transfer, Media or WXR operations or other supported file-transfer routes, nor mean native file delivery is universally impossible. See the [Ability reference](./docs/ABILITIES.md), [data exchange guide](./docs/LARGE-PAYLOADS.md) and [integrations](./docs/INTEGRATIONS.md).

## Permissions you control

Go to **WP AI Bridge → Settings**, the last submenu entry. Its eight sections group connection details, access, scopes and limits by task. **Save changes** submits edits across every section, including sections you are not viewing. Workspace export and clear remain separate explicit actions. See [settings and save results](./docs/USER-GUIDE.md#dashboard-and-settings).

**Only Site Read is enabled by default.** Every other access group is an administrator choice, and none overrides the connected WordPress account's own permissions.

| Access group | Intended use |
| --- | --- |
| **Site Read** | Inspect permitted site, content, media and extensions. |
| **Database Inspection** | Discover installation/plugin table metadata. |
| **Sensitive Database Read** | Read exact raw database values, including secrets; also requires Inspection. |
| **Database Write** | Stage exact bytes and execute typed insert/update batches with recovery; also requires Inspection and Sensitive Read. |
| **Database Delete** | Additionally authorize typed delete batches and explicit destructive receipt recovery. |
| **Database schema changes** | Authorize typed native DDL with explicit preconditions and recovery; DDL is not atomic CAS or rollback. |
| **Native-account SQL** | Use the database account's actual privileges only with hosting enablement, every database grant and per-execution acknowledgement. |
| **Builder Write** | Create and edit supported content, Media and Workspace data. |
| **Remote Media** | Import safe remote media; also requires Builder Write and native upload permission. |
| **Live Content** | Publish content and change live status when WordPress permits. |
| **Site Configuration** | Configure supported WordPress and provider settings. |
| **Sensitive Native Site Settings Read / Write** | Separate native option read/write consent. Write also requires Site Configuration. |
| **Sensitive Native Network Settings Read / Write** | Separate network-owned read/write consent, corresponding site grants and actual Super Admin/network authority. |
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
| **Sensitive filesystem read / Filesystem write** | Separate read/write grants for explicitly approved roots and exact file bytes. |
| **Filesystem deletion and moves / Executable filesystem changes** | Separate destructive and executable-file authority, in addition to the root's permissions and native capabilities. |
| **Sensitive log access** | Read approved file-log windows and tails, including potentially sensitive contents. |
| **Scheduler management / Immediate scheduled-action execution** | Separate schedule/cancel and supported immediate-run authority; provider callbacks keep their native effects. |
| **Full backup export / Full backup restore** | Separate capture and isolated-restore grants, approved scopes and declared quiescence. |
| **Native appearance and transient settings / Registered Customizer settings** | Separate native configuration and compatible current-theme Customizer authority, plus the required sensitive settings grants. |

On builds that include the **Settings → Database → Native-account SQL server setup** action, a suitably authorized WordPress administrator can request a *separate* verified edit of the actually loaded `wp-config.php` to add `define( 'WP_AI_BRIDGE_ALLOW_NATIVE_ACCOUNT_SQL', true );` before the WordPress bootstrap. When unsupported or denied, the page shows the proven file path, insertion/replacement line where possible and the real failure reason; custom/dynamic/ambiguous configuration must be handled by the hosting operator. The action opens a separate tab to preserve unsaved settings, never executes SQL or changes grants, and is confirmed only by a new WordPress request. This setup is not in the published v0.7.1 asset. [Database administration](./docs/DATABASE-ADMINISTRATION.md#server-side-sql-opt-in-setup) explains the full limits.

Database and advanced-administration grants retain their separate authority checks within the unified settings form; an ordinary edit or upgrade never enables them. Configure approved roots and restore schemas under **Settings → Files & backups**; roots start empty. Backup requires all selected database/DDL/file writers to remain quiescent throughout capture and verification. Native-account SQL can reach other schemas and Bridge controls within the account's privileges, so recovery may become unavailable if those controls are changed. Typed raw writes bypass WordPress/plugin business APIs; use native features when their hooks, validation, caches or lifecycle are required.

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
- [Native option mutations and historical recovery](./docs/NATIVE-OPTION-MUTATIONS.md)
- [External plugin/theme packages](./docs/EXTERNAL-PACKAGES.md)
- [Security and privacy](./docs/SECURITY.md)
- [Troubleshooting](./docs/TROUBLESHOOTING.md)
- [Release history](./CHANGELOG.md)

**For maintainers:** Start with the [canonical product specification](./MASTER-SPEC.md), [architecture](./docs/ARCHITECTURE.md) and [development/testing guide](./docs/DEVELOPMENT.md). Recover unfinished acceptance from [GitHub Issues](https://github.com/AChWorks/wp-ai-bridge/issues), candidate changes from [Pull Requests](https://github.com/AChWorks/wp-ai-bridge/pulls) and shipped artifacts from [Releases](https://github.com/AChWorks/wp-ai-bridge/releases). A merged source PR is not proof of a live-site installation; an Owner-parked Issue is not permission to start development.

**License:** GPL-2.0-or-later — [LICENSE](./LICENSE) · **Author:** [ACh](https://ach.li)
