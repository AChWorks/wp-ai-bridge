# Installation and connection

## Requirements

- WordPress 6.9 or newer.
- Official WordPress MCP Adapter installed and active.
- HTTPS with a publicly reachable REST API for direct ChatGPT connections.
- A WordPress user with the capabilities needed for the intended operations.

## Install the plugin

1. Download `wp-native-builder-bridge.zip` from the latest GitHub release.
2. In WordPress open **Plugins → Add Plugin → Upload Plugin**.
3. Upload the ZIP and activate **WP Native Builder Bridge**.
4. Open **WP Native Builder → Settings**.

The settings screen reports whether the WordPress Abilities API, MCP Adapter, and public HTTPS endpoint are available.

## Connect a ChatGPT Workspace App

The settings screen displays an endpoint in this form:

```text
https://YOUR-SITE.example/wp-json/wp-native-builder/v1/mcp
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

## OAuth discovery endpoints

The plugin publishes standards-based OAuth metadata at:

```text
/.well-known/oauth-protected-resource
/.well-known/oauth-authorization-server
```

The MCP endpoint returns an authentication challenge when accessed without a valid Bearer token.

## Configure access

Open **WP Native Builder → Settings** and enable only the groups required by the intended workflow.

A conservative starting point is:

- Site Read: enabled.
- Builder Write: enable when drafts or edits are needed.
- Live Content: leave disabled until publishing is intentionally required.
- Site Configuration: enable only for site/theme configuration work.
- Code & Extensions: enable only for managed snippets or extension lifecycle work.
- Source Editing: leave disabled unless installed plugin/theme source must be read or changed. It is separate from Code & Extensions and grants administrator-level code trust, not sandboxed execution. Source apply/recovery additionally require guarded same-filesystem no-overwrite replacement with hard-link support; unsupported filesystems fail closed rather than falling back to an in-place write.
- Users & Destructive: leave disabled unless the requested operation genuinely requires it.

## Update

Upload the new release ZIP from **Plugins → Add Plugin → Upload Plugin** and use WordPress's replace-existing-plugin flow. Replacing the plugin files does not intentionally clear Bridge settings, OAuth state, or Workspace data.

## Deactivate and uninstall

Deactivation stops Bridge execution but preserves its configuration and Workspace data.

Uninstall removes disposable Bridge settings, activity-log configuration, locks, and Bridge-owned OAuth metadata, and invalidates outstanding Bridge OAuth artifacts. Persistent Workspace content is intentionally preserved so uninstalling the transport plugin does not silently destroy project state. A genuinely pending Source Editing recovery record is also preserved because it may still own exact preimage/private replacement artifacts; reinstall the Bridge and reconcile/recover that state before deleting it manually.

If Workspace data is no longer wanted, clear it explicitly from **WP Native Builder** before uninstalling.

## Private or local WordPress sites

A direct ChatGPT Workspace App requires an Internet-reachable HTTPS MCP endpoint. Local-only or private-network installations can still use the underlying WordPress MCP Adapter through another supported client/transport, but they cannot be added to ChatGPT as a direct remote App until the endpoint is reachable from ChatGPT.
