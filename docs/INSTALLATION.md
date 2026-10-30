# Installation and connection

## Requirements

- WordPress 6.9 or newer.
- Official WordPress MCP Adapter installed and active; it is a native required-plugin dependency for activating WP AI Bridge.
- HTTPS with a publicly reachable REST API for direct ChatGPT connections; independent clients need a mutually supported transport and authentication contract.
- A WordPress user with the capabilities needed for the intended operations.

## Install the plugin

1. Download `wp-ai-bridge.zip` from the latest GitHub release.
2. In WordPress open **Plugins → Add Plugin → Upload Plugin**.
3. Upload the ZIP. Install and activate the separate official **MCP Adapter** from WordPress.org if it is not already active; the native Plugins screen identifies a missing or inactive required dependency.
4. Activate **WP AI Bridge**, then open **WP AI Bridge → Settings**.

The settings screen reports whether the WordPress Abilities API, MCP Adapter, and public HTTPS endpoint are available.

Canonical builds install with the `wp-ai-bridge/` directory and `wp-ai-bridge.php` entrypoint. Version 0.4.1 established the native canonical baseline; later releases retain the identity and do not contain the retired pre-0.4.0 migration runtime. The GitHub source branch or release candidate is not evidence of an installed build.

### v0.6.0 transfer and WXR distribution

Starting with v0.6.0 source/distributable, Bridge provides private, principal/client/site-bound byte windows for text/Media/WXR, and native WXR Core export and official WordPress Importer 0.9.6 import planning/execution. Install a **published tagged release ZIP**, not an unmerged branch. The importer requires WordPress/Bridge administrator authorization and the official WordPress Importer activated and loaded with `WP_LOAD_IMPORTERS=true` **before** request bootstrap. Published imports/existing-target effects additionally require deliberately enabled Live Content authorization. Neither transfer staging nor export automatically imports, installs, publishes or fetches remote attachments.

Native WXR import is non-atomic; it returns a durable receipt and disallows blind replay after uncertain/partial execution. Inspect WordPress state before attempting another import. WXR does not include a complete database/media backup; a standard MCP JSON response is not automatically a downloaded ChatGPT attachment. Public-network, Gateway-brokered and multi-host environments require their own acceptance. Validate the exact published tag, installed plugin version and current authorization before use.

### Updating an already-canonical installation

An existing canonical 0.4.0-or-newer installation can be updated normally with WordPress's replace/update flow, subject to the selected release's compatibility and hosting notes. The plugin directory, Workspace identifiers, settings identity, OAuth routes and Ability namespace remain canonical; the version number alone is not a reason to erase persistent Workspace data or reset a working OAuth client. Verify actual post-upgrade OAuth health and current Bridge delegation rather than inferring it from source or a successful ZIP upload.

Before any production plugin update, keep a normal WordPress/database backup appropriate to the site. For v0.7.0, ensure the official MCP Adapter is installed and active before replacing the Bridge package. WordPress owns normal dependency activation/deactivation controls; the Bridge keeps readiness diagnostics for abnormal manual filesystem removal or other paths outside those controls. An upload or activation does not silently install/activate the Adapter. Core does not automatically deactivate an already-active Bridge solely because this dependency metadata was added or the Adapter was manually removed; the Plugins screen shows the missing/inactive dependency, and Bridge readiness diagnostics remain available outside that screen. Restore the official Adapter before relying on MCP operations.

The v0.7.0 package adds separately protected database, native settings and advanced-administration grants; existing ordinary grants do not enable them on upgrade. Approved filesystem roots and restore-schema lists start empty. Open **WP AI Bridge → Administration** to configure intended scopes and consult the [administration guide](./ADMINISTRATION.md) before enabling SQL, filesystem, scheduler or backup workflows. Native-account SQL requires an additional hosting opt-in and account-scope acknowledgement. Full capture requires declared writer quiescence; verified restore is isolated and does not activate the restored site. Keep the source installation authoritative during recovery.

### GitHub release updates in the WordPress dashboard (v0.6.8 and later)

The **published v0.6.8 release** includes native GitHub update discovery. Once that release (or a later updater-equipped version) is installed and **active**, WP AI Bridge can advertise newer **published stable** GitHub Releases to WordPress's normal **Plugins** update interface. This uses WordPress Core's `Update URI` and its normal package upgrader; the Bridge does not install another updater, turn on automatic updates, require a GitHub token, or change the `wp-ai-bridge/wp-ai-bridge.php` installation identity. An administrator may use the usual WordPress **Enable auto-updates** preference independently.

The older **v0.6.7** package predates this feature. If that version (or an earlier one) is still installed, upgrade it **once** through **Plugins → Add Plugin → Upload Plugin**, select the official `wp-ai-bridge.zip` file from [GitHub Releases](https://github.com/AChWorks/wp-ai-bridge/releases/latest), and approve the WordPress **Replace current with uploaded** flow after a normal site/database backup. Avoid GitHub's automatically generated `Source code (zip)` archive: it is not the installable release asset. Later qualifying stable versions can then be offered natively. When the Bridge is inactive its updater callback cannot run, so update inactive installations manually through the same canonical ZIP path.

If the server cannot reach GitHub's public Release API (including a timeout or rate limit), no external update is offered until a future successful check; the existing installation remains unchanged. The published v0.6.10 release adds a Core-transient fallback that can offer canonical Bridge updates to authorized administrators or WP-Cron even if WordPress.org's general plugin-update request fails. The administrator's **Check Again** action will also refresh Bridge's independently cached release metadata; this new behavior does not retroactively repair older installed versions. If GitHub is blocked, the official ZIP still needs an approved manual transfer; an alternate mirror must first establish verifiable source authenticity and package integrity. A GitHub release, CI success or a merged PR is **not** proof that a version is installed or available on WordPress.org. Verify the installed version, active state, Workspace content and OAuth connection after any upgrade. The plugin's own README and [release changelog](../CHANGELOG.md) document the exact current version and limitations.

### Sites still on the published 0.3.0 package

Version 0.4.1 and later deliberately do not contain the retired pre-0.4.0 importer. If a site is still on 0.3.0 and must preserve its old Workspace Documents/Tasks, first use the immutable **v0.4.0** release and its documented one-time migration procedure. Verify the canonical Workspace after that migration, then update the resulting 0.4.0 installation to the current release.

Do not install v0.4.1 or later directly over a 0.3.0 installation when preservation of the pre-canonical Workspace is required. The historical migration implementation remains available only through the immutable v0.4.0 release; it is not part of current maintained runtime code.

## Connect a ChatGPT Workspace App

The settings screen displays the canonical endpoint in this form:

```text
https://YOUR-SITE.example/wp-json/wp-ai-bridge/v1/mcp
```

With Developer Mode enabled in the ChatGPT workspace:

1. Open **Workspace settings → Apps**.
2. Create a custom App.
3. Enter the endpoint above.
4. Select OAuth authentication.
5. Run **Scan Tools**.
6. Sign in to WordPress in the browser window.
7. Review the requested connection and select **Authorize ChatGPT**.

The OAuth connection acts as the WordPress user who approved it. Bridge access groups and WordPress capabilities are still checked for every operation.

Canonical builds serve only the canonical MCP/OAuth routes. A fresh installation needs a fresh OAuth connection. A normal canonical update does not rename those routes, but the operator must still verify the actual authenticated connection after an upgrade; connection refresh failure must not be treated as proof of a successful reconnect.

## Other approved AI/MCP clients

The same WordPress MCP endpoint is not specific to ChatGPT. WP AI Bridge already supports administrator-approved additional HTTPS OAuth Client ID Metadata identities when those clients implement its validated `private_key_jwt`, JWKS, PKCE and resource-binding contract. Configure those identities under **WP AI Bridge → OAuth Clients**, following [Approved OAuth clients](./OAUTH-CLIENTS.md).

ChatGPT is the tested built-in profile. Other AI products (including Claude and Gemini) are **not automatically compatible** simply because they support MCP; verify the client's actual remote-MCP/OAuth requirements before attempting direct onboarding. Never reuse the ChatGPT client identity or signing keys for another client.

## OAuth discovery endpoints

Canonical protected-resource metadata is available at:

```text
/.well-known/oauth-protected-resource
```

Authorization server metadata is available at:

```text
/.well-known/oauth-authorization-server
```

The MCP endpoint returns an authentication challenge pointing to the canonical protected-resource metadata document.

## Configure access

Open **WP AI Bridge → Settings** and enable only the groups required by the intended workflow.

A conservative starting point is:

- Site Read: enabled.
- Builder Write: enable when drafts or edits are needed.
- Live Content: leave disabled until publishing is intentionally required.
- Site Configuration: enable only for site/theme configuration work.
- Database and sensitive native settings: enable only the particular protected read/write/schema/SQL or site/network grants required. Raw database access is distinct from native options/provider lifecycle; native-account SQL can affect the account's other schemas and Bridge controls.
- Advanced administration: select the separate file/log/scheduler/configuration/backup grants and approve exact roots/restore schemas. Executable-file changes, immediate scheduled-action execution and full restore carry separate consent. Multisite retains actual Super Admin/network requirements.
- Code & Extensions: enable only for managed snippets or extension lifecycle work.
- External Packages: leave disabled unless the administrator explicitly permits a validated public HTTPS plugin/theme installation via `extension-lifecycle`. Code & Extensions, exact native install authority and hosting filesystem policy must also allow the operation.
- Registered REST Discovery: leave disabled unless bounded Core/provider route metadata is needed. It requires the native `manage_options` capability and is not proof that a route can be executed.
- High-Trust Registered REST Invocation: leave disabled unless the administrator deliberately grants conservative administrator-equivalent generic REST execution. The current policy requires every protected Bridge consent and mapped native capability, and still runs the exact provider/Core permission callback. HTTP GET is not classified safe merely because it reads like a query; do not enable this group to bypass a specialized control.
- Source Editing: leave disabled unless installed plugin/theme source must be read or changed. It is separate from Code & Extensions and grants administrator-level code trust, not sandboxed execution. Source apply/recovery additionally require guarded same-filesystem no-overwrite replacement with hard-link support; unsupported filesystems fail closed rather than falling back to an in-place write.
- Native Abilities: leave disabled unless ChatGPT must execute registered Core/provider Abilities directly through WP AI Bridge. Enabling it is broad registered-operation trust, not a sandbox; each target's own WordPress/provider permission callback must still allow the operation. Fresh installs and upgrades keep it disabled until an administrator opts in.
- Comments: leave disabled unless bounded comment discovery, replies, or moderation is needed. It is independent from Site Read/Builder Write and still relies on WordPress Core comment permissions; permanent deletion additionally requires Users & Destructive.
- Users & Destructive: leave disabled unless the requested operation genuinely requires it.

## Native installer host readiness

WordPress Core's package installer requires current WordPress permissions and hosting file modifications to be allowed. Bridge-owned native installer paths coordinate through the existing `WP_CONTENT_DIR` file lock and the database advisory lock where available. Do not infer lock coherence from a shared path string across independent physical filesystems. The published WordPress-side private-transfer Abilities support authenticated, purpose-bound exchange, but real client/Gateway-native binary handoff still requires independently verified **bidirectional** transport (see #134). This is not a WordPress administrator upload page.

## Canonical runtime baseline

The maintained plugin uses only the WP AI Bridge runtime identity: `wp-ai-bridge/wp-ai-bridge.php`, `WP_AI_Bridge`, `wp-ai-bridge`, canonical `wp-ai-bridge/*` Abilities, and canonical Bridge-owned storage identifiers.

The one-time 0.3.0 -> 0.4.0 Workspace importer, its migration schema marker, engine preflight, migration-only localization, and migration-only CI fixtures were retired after the sole real installation was verified on canonical v0.4.0 with its Workspace intact. They are not loaded or shipped by v0.4.1 or later.

Published tags/releases remain immutable and preserve the historical v0.4.0 migration implementation for audit or recovery of an installation that never performed that transition.

## Deactivate and uninstall

Deactivation stops Bridge execution but preserves its configuration and Workspace data.

Uninstall removes disposable Bridge settings, activity-log configuration, locks, and Bridge-owned OAuth metadata, and invalidates outstanding Bridge OAuth artifacts. Persistent Workspace content is intentionally preserved so uninstalling the transport plugin does not silently destroy project state. A genuinely pending Source Editing recovery record is also preserved because it may still own exact preimage/private replacement artifacts; reinstall the Bridge and reconcile/recover that state before deleting it manually.

If Workspace data is no longer wanted, clear it explicitly from **WP AI Bridge** before uninstalling.

## Private or local WordPress sites

A direct ChatGPT Workspace App requires an Internet-reachable HTTPS MCP endpoint. Local-only or private-network installations can still use the underlying WordPress MCP Adapter through another supported client/transport, but they cannot be added to ChatGPT as a direct remote App until the endpoint is reachable from ChatGPT.
