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

The public artifact name changed, but the archive deliberately retains the existing `wp-native-builder-bridge/` plugin directory and entrypoint. Uploading `wp-ai-bridge.zip` therefore upgrades an existing installation in place instead of creating a second plugin installation.

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

Existing connections created with the former `/wp-json/wp-native-builder/v1/mcp` resource remain available through a bounded legacy endpoint. Tokens remain bound to the exact resource for which they were issued: a legacy token is not accepted by the canonical WP AI Bridge endpoint, and a canonical token is not accepted by the legacy endpoint.

## OAuth discovery endpoints

New connections use canonical protected-resource metadata at:

```text
/.well-known/oauth-protected-resource
```

The retained legacy MCP resource has its own migration metadata document:

```text
/.well-known/oauth-protected-resource/wp-native-builder/v1/mcp
```

Both resource documents use the same authorization server metadata:

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
- Users & Destructive: leave disabled unless the requested operation genuinely requires it.

## Update from the former product name

Install the new `wp-ai-bridge.zip` through WordPress's replace-existing-plugin flow. The migration intentionally preserves the installed plugin directory/entrypoint, text domain, PHP namespace/constants, settings/OAuth/Workspace storage keys, and `wp-native-builder/*` Ability identifiers. Existing data and clients therefore do not need a second storage migration merely because the public product name changed.

The canonical admin slugs now start with `wp-ai-bridge`; old `wp-native-builder...` admin bookmarks are retained as hidden compatibility aliases.

## Deactivate and uninstall

Deactivation stops Bridge execution but preserves its configuration and Workspace data.

Uninstall removes disposable Bridge settings, activity-log configuration, locks, and Bridge-owned OAuth metadata, and invalidates outstanding Bridge OAuth artifacts. Persistent Workspace content is intentionally preserved so uninstalling the transport plugin does not silently destroy project state. A genuinely pending Source Editing recovery record is also preserved because it may still own exact preimage/private replacement artifacts; reinstall the Bridge and reconcile/recover that state before deleting it manually.

If Workspace data is no longer wanted, clear it explicitly from **WP AI Bridge** before uninstalling.

## Private or local WordPress sites

A direct ChatGPT Workspace App requires an Internet-reachable HTTPS MCP endpoint. Local-only or private-network installations can still use the underlying WordPress MCP Adapter through another supported client/transport, but they cannot be added to ChatGPT as a direct remote App until the endpoint is reachable from ChatGPT.
