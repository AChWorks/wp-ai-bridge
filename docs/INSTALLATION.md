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

The 0.4.0 package installs with the canonical `wp-ai-bridge/` directory and `wp-ai-bridge.php` entrypoint. See the migration section below before replacing a published 0.3.0 installation.

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

Connections from the former plugin are intentionally not migrated. Version 0.4.0 serves only the canonical MCP/OAuth routes, so create a fresh ChatGPT connection after migration.

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

## Migrate from the published 0.3.0 package

Version 0.4.0 intentionally changes the actual WordPress plugin installation identity to `wp-ai-bridge/wp-ai-bridge.php`. Do **not** use the replace-existing-plugin flow for this migration.

The supported one-time sequence is:

1. Take a normal WordPress/database backup before the migration.
2. Deactivate the former plugin.
3. Delete it from **Plugins → Installed Plugins**. WordPress runs the former plugin's uninstall routine; that routine removes its disposable settings/OAuth state while intentionally preserving private Workspace Documents and Tasks.
4. Upload and activate the 0.4.0 `wp-ai-bridge.zip` package. It installs under `wp-ai-bridge/`.
5. Activation migrates only the preserved Workspace Documents and Tasks to the canonical Workspace identifiers, preserving their WordPress IDs, content/state, state hashes, and versions.
6. Open **WP AI Bridge → Settings** and enable the access groups you now want. Former access-group settings, mutation/activity state, OAuth clients/tokens, and other connection/runtime state are intentionally not imported.
7. Reconnect ChatGPT using the canonical MCP endpoint shown on the Settings page.

For WP-CLI automation, use `wp plugin uninstall wp-native-builder-bridge` for step 3; `wp plugin delete` only removes plugin files and does not run the uninstall routine.

After 0.4.0 is active, the maintained runtime serves only canonical `wp-ai-bridge` admin/OAuth/MCP routes and `wp-ai-bridge/*` Ability identifiers. Former admin bookmarks and OAuth/MCP endpoints are not aliases in the new plugin.

## Deactivate and uninstall

Deactivation stops Bridge execution but preserves its configuration and Workspace data.

Uninstall removes disposable Bridge settings, activity-log configuration, locks, and Bridge-owned OAuth metadata, and invalidates outstanding Bridge OAuth artifacts. Persistent Workspace content is intentionally preserved so uninstalling the transport plugin does not silently destroy project state. A genuinely pending Source Editing recovery record is also preserved because it may still own exact preimage/private replacement artifacts; reinstall the Bridge and reconcile/recover that state before deleting it manually.

If Workspace data is no longer wanted, clear it explicitly from **WP AI Bridge** before uninstalling.

## Private or local WordPress sites

A direct ChatGPT Workspace App requires an Internet-reachable HTTPS MCP endpoint. Local-only or private-network installations can still use the underlying WordPress MCP Adapter through another supported client/transport, but they cannot be added to ChatGPT as a direct remote App until the endpoint is reachable from ChatGPT.
