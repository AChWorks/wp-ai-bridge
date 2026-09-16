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

The archive installs under the canonical `wp-ai-bridge/` directory with entrypoint `wp-ai-bridge.php`.

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

Only the canonical `/wp-json/wp-ai-bridge/v1/mcp` resource is registered. A v0.3.0 → v0.4.0 identity migration intentionally retires old OAuth artifacts, so reconnect ChatGPT once after that cutover.

## OAuth discovery endpoints

New connections use canonical protected-resource metadata at:

```text
/.well-known/oauth-protected-resource
```

The canonical resource document uses authorization server metadata at:

```text
/.well-known/oauth-authorization-server
```

The exact MCP endpoint returns an authentication challenge pointing to the metadata document for that same resource.

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

## Migrate from v0.3.0 / the former plugin directory

v0.4.0 is a one-time identity cutover, not an in-place folder replacement. The old plugin must remain installed but **deactivated** until the new plugin has imported and verified its data.

1. Back up the site/database using your normal WordPress hosting procedure.
2. If v0.3.0 reports a pending Source Editing recovery, resolve it before changing plugin identity.
3. Deactivate **WP AI Bridge v0.3.0** / the installation located at `wp-native-builder-bridge/`. **Do not delete it yet**; its uninstall routine removes legacy settings/OAuth state that the importer needs.
4. Upload `wp-ai-bridge.zip`. WordPress installs it separately at `wp-ai-bridge/wp-ai-bridge.php`.
5. Activate the new WP AI Bridge. Activation runs the bounded one-time importer before the normal Bridge runtime starts.
6. Verify the expected access-group settings and Workspace documents/tasks. Existing Workspace record IDs and state are retained. Approved OAuth-client configuration and the OAuth installation identity are migrated; old OAuth sessions are retired, so reconnect ChatGPT to the canonical endpoint.
7. After the new plugin is active and the migration is verified, delete the **deactivated** old plugin. At that point its old uninstall cleanup cannot remove the newly migrated `wp_ai_bridge` / `wpai` state.

The migration renames Bridge-owned rows inside WordPress's normal `options`, `posts`, and `postmeta` storage; the plugin does not create custom database tables. Former admin slugs and MCP/OAuth routes are not registered after cutover.

## Deactivate and uninstall

Deactivation stops Bridge execution but preserves its configuration and Workspace data.

Uninstall removes disposable Bridge settings, activity-log configuration, locks, and Bridge-owned OAuth metadata, and invalidates outstanding Bridge OAuth artifacts. Persistent Workspace content is intentionally preserved so uninstalling the transport plugin does not silently destroy project state. A genuinely pending Source Editing recovery record is also preserved because it may still own exact preimage/private replacement artifacts; reinstall the Bridge and reconcile/recover that state before deleting it manually.

If Workspace data is no longer wanted, clear it explicitly from **WP AI Bridge** before uninstalling.

## Private or local WordPress sites

A direct ChatGPT Workspace App requires an Internet-reachable HTTPS MCP endpoint. Local-only or private-network installations can still use the underlying WordPress MCP Adapter through another supported client/transport, but they cannot be added to ChatGPT as a direct remote App until the endpoint is reachable from ChatGPT.
