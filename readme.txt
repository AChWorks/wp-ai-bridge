=== WP AI Bridge ===
Tags: ai, mcp, abilities, oauth, administration
Requires at least: 6.9
Tested up to: 7.1
Stable tag: 0.7.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect compatible AI/MCP clients to WordPress with OAuth, explicit administrator grants, and native WordPress permissions.

== Description ==

WP AI Bridge is an administrator-controlled integration for compatible AI/MCP clients. It uses the official WordPress MCP Adapter and Abilities API, WordPress OAuth authentication, and native WordPress permissions. The current direct connection is tested with ChatGPT; other clients require their own compatibility testing and administrator approval.

Capabilities include authorized content, blocks, media, taxonomy, site settings, user operations, persistent private Workspace documents and tasks, registered REST and provider Abilities, and optional high-trust extension and source-management operations. Advanced administration provides separately delegated database/schema/SQL, native configuration, scoped files/logs, scheduler administration and complete quiescent database/files capture with verified isolated restore. Each sensitive group is separately disabled until a site administrator enables it; WordPress and provider permission checks still apply.

The plugin has no required SaaS subscription. The official MCP Adapter is installed separately and declared as a required plugin; WordPress identifies a missing/inactive dependency and requires its activation before WP AI Bridge can be activated. WordPress 6.9 or newer is required. Consult the detailed GitHub documentation for the supported Adapter builds, hosting requirements and limits.

**Security:** External Packages, Source Editing, Registered REST Invocation, Native Abilities, credentials and destructive operations are privileged features, not code sandboxes. Grant access only to trusted clients and WordPress users. An administrator can revoke OAuth permissions and Bridge grants. Legitimate third-party package installations and executable source changes can run code with the authority of WordPress and its hosting environment.

**GitHub updates:** Maintained releases are distributed as the official installable `wp-ai-bridge.zip` asset on GitHub Releases. After installing a version that includes native GitHub update discovery, WordPress checks published stable releases and can offer Core-native updates. Preview tags, source archives and unverified package names are not offered. Existing installations from before this feature require a first manual ZIP update. WordPress.org submission or acceptance is not asserted.

For setup, supported operations and troubleshooting, see https://github.com/AChWorks/wp-ai-bridge.

Database administration uses six protected default-off grants: Database Inspection, Sensitive Database Read, Database Write, Database Delete, Database schema changes and Native-account SQL; existing ordinary grants never enable them on upgrade. Typed insert/update/delete batches use exact bytes/NULL, live schema/row preconditions, resumable staged values/keys and durable receipt recovery. Account/role/session and ordinary plugin-secret storage are intentionally within powerful database delegation; Bridge authorization/recovery/Workspace controls remain protected. Multisite requires actual Super Admin and network authority. Native WordPress/plugin features remain preferable when their business lifecycle and caches are required. Typed schema changes use observed preconditions and acknowledged native dispatch, without an atomic DDL or rollback promise. Native-account SQL additionally requires hosting policy WP_AI_BRIDGE_ALLOW_NATIVE_ACCOUNT_SQL=true, every database grant and per-request acknowledgement of the account's actual privileges; those privileges may reach other schemas and Bridge controls, making recovery conditional.

Native unregistered and sensitive settings access uses the WordPress Options API. Separate default-off site/current-network read and write consents protect effective reads, complete private snapshots and one native add/update/delete family with inert complete-value inputs and historical recovery. Network access requires actual Super Admin/network authority. The durable fence permits one Bridge setter dispatch; Core callbacks remain nontransactional, and false/unknown outcomes must not be blindly replayed. Native retrieval and mutation still need memory for the full value. Registered REST settings retain their own contract. See https://github.com/AChWorks/wp-ai-bridge/blob/main/docs/NATIVE-OPTION-MUTATIONS.md

Advanced administration also includes explicitly approved filesystem roots, exact sensitive log windows/tails, native Core and available Action Scheduler lifecycle operations, current-theme modifications/transients and compatible registered Customizer settings. The eleven additional administration grants default off; root and restore-schema approvals start empty. Unsupported native/provider/hosting prerequisites are explicit rather than silently omitted.

Full backup requires declared all-writer/DDL/file quiescence throughout capture and verification. Restore targets an approved empty separate schema and nonserved root, preserving source authority and verifying complete schema/data/files. It does not activate the restored site or perform live in-place disaster recovery. Native resources and processing budgets govern resumable work; a chunk/window is not a fixed total backup-size limit. See https://github.com/AChWorks/wp-ai-bridge/blob/main/docs/ADMINISTRATION.md

== Installation ==

1. Install WordPress 6.9 or newer.
2. Install and activate the separate official MCP Adapter plugin from WordPress.org.
3. Upload the installable `wp-ai-bridge.zip` asset from https://github.com/AChWorks/wp-ai-bridge/releases/latest using **Plugins > Add Plugin > Upload Plugin**.
4. Activate WP AI Bridge after MCP Adapter is active, then open **WP AI Bridge > Settings**. If the dependency is missing or inactive, follow WordPress's native Plugins guidance to install/activate it first.
5. Review the optional access groups and connect a compatible client using the site's HTTPS OAuth/MCP endpoint. OAuth authorization alone does not grant administrative capabilities.

Before updating, ensure the separate official MCP Adapter is installed and active. WordPress manages normal dependency activation/deactivation controls; readiness notices remain a fallback for abnormal manual filesystem changes or other unsupported paths. Updating an existing installation keeps the canonical plugin folder `wp-ai-bridge` and its WordPress settings/Workspace. Do not substitute GitHub's automatic source-code archive for the installable release asset. For sites still using version 0.3.0 that need legacy Workspace data, first follow the one-time v0.4.0 migration instructions in the GitHub installation guide.

All settings are under **WP AI Bridge > Settings**, the last submenu entry. Choose among Connection, Content & site, Accounts & sensitive settings, Code & integrations, Database, Files & backups, Capacity & limits and Workspace data. **Save changes** submits edits from all sections, including those not currently visible. If a save is rejected, stale or only partly confirmed, review the group results and retained draft, inspect saved settings in another tab, then reload before trying again. Workspace export and destructive clear are independent actions; neither saves settings. The former Administration URL redirects to Settings.

== Frequently Asked Questions ==

= Is an external MCP gateway or AI subscription required? =

No external gateway is required for supported direct connections. An external AI client/service may have separate requirements. The official WordPress MCP Adapter is required for MCP exposure.

= Can I grant an AI unrestricted WordPress administration? =

The site administrator chooses the Bridge access groups and the connected WordPress user. WordPress permissions and provider-side checks are still enforced. Enabling powerful groups does not grant missing native WordPress authority.

= Does file exchange work with every AI client? =

No. The actual client's binary attachment transport and hosting limits require end-to-end validation. Native WordPress Media and the Bridge's bounded private-transfer operations do not prove that a hosted AI client can materialize attachments.

= How are updates installed? =

Stable GitHub releases can be presented through native WordPress update checks when a version with the updater is installed. Download, confirmation, replacement and optional auto-update preferences use WordPress Core. The plugin does not silently auto-enable updates. GitHub discovery requires this plugin to be active; for inactive installations use the ordinary manual ZIP update flow.

== Privacy and external services ==

OAuth requests share the requested connection identity, approved scopes and authorized operation inputs/outputs with the connected AI/MCP client or an administrator-approved intermediary. The Bridge does not automatically send the site's full content to an AI vendor. Authorizing an operation may cause site information or content to be disclosed to the specific connected client. Review that client's own data handling policy.

The Bridge may contact third parties in the following distinct cases:

* **GitHub** (`api.github.com`, `github.com`) for optional stable release metadata and installable ZIP downloads during normal WordPress update checks/updates. The service receives standard HTTP network metadata such as the server IP and user agent, not Bridge OAuth tokens. Provider: https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement
* **Approved OAuth clients** at the explicitly approved HTTPS metadata and public JWKS URLs, to verify client identity and signing keys. The owner controls which URLs may be added; see https://github.com/AChWorks/wp-ai-bridge/blob/main/docs/OAUTH-CLIENTS.md
* **Administrator-enabled URL media import** at the specified safe public HTTP(S) URL, only when an authorized operation is invoked.
* **Administrator-enabled external plugin/theme packages** at an exact public HTTPS URL, only when an authorized operation is invoked. Installation/replacement then uses the native WordPress Upgrader.

WordPress.org may be contacted by WordPress Core for its own routine plugin/theme checks or ordinary directory installations. WordPress itself, connected plugins, clients and hosting providers may impose their own privacy policies.

WP AI Bridge retains private Workspace data and bounded activity metadata on the WordPress installation. OAuth tokens, connection approvals and revocation remain WordPress-controlled. See the project's Security and OAuth documentation for retention, revocation and advanced permissions.

== Changelog ==

= 0.7.1 =
* Unify settings in one final Settings submenu with eight task sections, Persian/RTL support, keyboard navigation and a no-JavaScript fallback.
* Save edits across sections in one submission with preflight validation, current-authority checks and verified results for each native settings store. Preserve unsaved values after rejected, stale or partly confirmed saves for review.
* Keep existing option stores, OAuth, grants and Workspace data; no settings migration or automatic permission expansion. Workspace export and clear remain separate explicit actions.

= 0.7.0 =
* Declare the official MCP Adapter as a native WordPress plugin dependency; preserve readiness diagnostics for abnormal missing-provider states.
* Add protected default-off database inspection, exact reads, typed transactional writes, typed schema operations and hosting-opt-in native-account SQL.
* Add separately consented native site/network option reads and mutations, appearance/transient settings and compatible registered Customizer publication.
* Add approved-root filesystem operations, sensitive file logs, native Core/Action Scheduler management and resumable private transfers.
* Add quiescent full database/files capture, complete independent verification and isolated restore into an approved empty schema/nonserved root; live activation remains separate.
* Preserve existing OAuth, Workspace and ordinary grants on canonical upgrade; new sensitive groups remain off. Actual client/host and live-site acceptance remain separate.

= 0.6.11 =
* Refresh PHP file-stat metadata after private Media staging so native WordPress sideload receives the actual written byte count; retain source SHA-256 checks and private-file cleanup (#134, #173).
* Retain native WordPress Media destinations and existing scoped security controls. Real LiteSpeed/PHP 8.1 acceptance and native client attachment handoff require separate verification.

= 0.6.10 =
* Fix selected WordPress Site Health checks invoked over MCP when WordPress admin helper functions are not yet loaded; retain native permission checks and real Core results.
* Keep the official Bridge update offer visible through WordPress Core even if the site's WordPress.org plugin update request fails.
* Honor an administrator's Check Again action for the independently cached GitHub release metadata, retaining bounded network use and the canonical installer.
* No executable-code mirror is enabled: a trusted and tested alternate channel requires separate validation and approval.

= 0.6.9 =
* Preserve actually observed provider/WordPress MCP error codes and useful bounded diagnostics instead of generic normalization; redact credentials and private paths, including malformed Cookie material, without fabricating causes.
* Add same-WordPress-user and same-OAuth-client detailed diagnostics-read references through the existing Activity log, preserving administrator-only permissions and safe HTTP context.
* Keep standard WordPress Core GitHub stable-release update discovery. A published ZIP is not a live-site Gateway/media transfer acceptance test.

= 0.6.8 =
* Add native WordPress update notifications from official stable GitHub Releases, preserving normal Core install, authorization and optional auto-update controls.
* Ship the official WordPress-style readme and protect the compiled Persian translation against direct requests; no existing Bridge administration capability was removed.

= 0.6.7 =
* Improve private-file staging portability on constrained hosting and bounded failure diagnostics.

Earlier release history: https://github.com/AChWorks/wp-ai-bridge/blob/main/CHANGELOG.md
