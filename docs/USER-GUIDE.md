# User guide

## Dashboard

**WP AI Bridge → Dashboard** provides a compact view of Workspace state and the Bridge connection environment.

Use it to confirm that the plugin is active and to orient a connected client before performing site work.

## Documents

**WP AI Bridge → Documents** stores durable project documents inside private WordPress-native storage.

Documents support:

- create;
- list and read;
- update;
- archive;
- optimistic concurrency using `version` and `state_hash`.

A client must refresh a document after another actor changes it before overwriting it. Stale writes are rejected instead of silently replacing newer state.

## Tasks

**WP AI Bridge → Tasks** stores durable work items. Each task keeps progress, review, and delivery as independent state dimensions so one status does not accidentally imply another.

Task filters are available for progress, review, delivery, and archived state.

Tasks use the same `version` + `state_hash` stale-write protection as Workspace documents.

## Activity

**WP AI Bridge → Activity** shows the bounded Bridge mutation log. The log records operation metadata needed for administration and troubleshooting; it is not intended to store submitted content, credentials, OAuth secrets, or arbitrary payloads.

## Settings

**WP AI Bridge → Settings** contains:

- the canonical direct ChatGPT MCP endpoint;
- OAuth metadata links;
- dependency/HTTPS readiness;
- Bridge access groups.

Enable the smallest access set needed for the current work. Existing bookmarks that use the former `wp-native-builder...` admin page slugs continue to resolve through hidden compatibility aliases; new navigation uses `wp-ai-bridge...` slugs.

## Workspace resume

The `workspace-resume` Ability returns compact orientation information for a connected client. Its public Ability identifier remains in the stable `wp-native-builder/*` namespace for compatibility even though the product is now named WP AI Bridge. It is designed to help continue a site project without dumping complete document bodies, task notes, or chat history into every new conversation.

## Export and clear

Workspace administration provides explicit export/clear lifecycle controls. Clearing Workspace data is intentionally separate from ordinary plugin deactivation/uninstall because durable project state should not disappear as a side effect of replacing a transport plugin.

## Language and RTL

The plugin uses standard WordPress localization APIs. Persian (`fa_IR`) is bundled, and the admin screens are designed to work in both RTL and LTR WordPress installations. The existing text domain remains `wp-native-builder-bridge` as a compatibility identifier; it is not the current public brand.
