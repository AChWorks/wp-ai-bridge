# Installation and connection

## Requirements

- WordPress 6.9 or newer.
- Official WordPress MCP Adapter installed and active.
- HTTPS with a publicly reachable REST API for direct ChatGPT connections.
- A WordPress user with the capabilities needed for the intended operations.

## Install the plugin

1. Download `wp-ai-bridge.zip` from the latest GitHub release.
2. In WordPress open **Plugins → Add Plugin → Upload Plugin**.
3. Upload the ZIP and activate **WP AI Bridge**.
4. Open **WP AI Bridge → Settings**.

The settings screen reports whether the WordPress Abilities API, MCP Adapter, and public HTTPS endpoint are available.

Current releases install with the canonical `wp-ai-bridge/` directory and `wp-ai-bridge.php` entrypoint. Version 0.4.1 established the native canonical baseline; current v0.4.2 keeps the same identity and contains no pre-0.4.0 migration runtime.

### Updating from 0.4.0 or 0.4.1

An existing canonical 0.4.0 or 0.4.1 installation can be updated normally with WordPress's replace/update flow. The plugin directory, Workspace identifiers, settings identity, OAuth identity, MCP routes, and Ability namespace remain canonical, so the v0.4.2 patch does not require another migration or a new ChatGPT connection.

Before any production plugin update, keep a normal WordPress/database backup appropriate to the site.

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

Current releases serve only the canonical MCP/OAuth routes. A fresh installation needs a fresh OAuth connection. Updating an already-connected canonical 0.4.0 or 0.4.1 installation to 0.4.2 does not by itself invalidate that canonical connection.

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
- Code & Extensions: enable only for managed snippets or extension lifecycle work.
- Source Editing: leave disabled unless installed plugin/theme source must be read or changed. It is separate from Code & Extensions and grants administrator-level code trust, not sandboxed execution. Source apply/recovery additionally require guarded same-filesystem no-overwrite replacement with hard-link support; unsupported filesystems fail closed rather than falling back to an in-place write.
- Native Abilities: leave disabled unless ChatGPT must execute registered Core/provider Abilities directly through WP AI Bridge. Enabling it is broad registered-operation trust, not a sandbox; each target's own WordPress/provider permission callback must still allow the operation. Fresh installs and upgrades keep it disabled until an administrator opts in.
- Comments: leave disabled unless bounded comment discovery, replies, or moderation is needed. It is independent from Site Read/Builder Write and still relies on WordPress Core comment permissions; permanent deletion additionally requires Users & Destructive.
- Users & Destructive: leave disabled unless the requested operation genuinely requires it.

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
