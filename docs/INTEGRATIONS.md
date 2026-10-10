# Integrations

The default runtime is intentionally small: **WordPress MCP Adapter + WP AI Bridge**. Optional plugins/themes remain normal site components, not additional AI infrastructure.

The canonical integration policy is documented in [Architecture](./ARCHITECTURE.md#discovery-and-reuse). Bridge is the authenticated AI connection, delegation and data-exchange layer, **not** a replacement for WordPress or a feature-plugin business engine. Reuse provider-owned public Abilities first, then supported WordPress/provider public APIs with minimal necessary typed adapters. The current Bridge does **not yet** expose every registered REST route through a generic execution contract; that work is [#119](https://github.com/AChWorks/wp-ai-bridge/issues/119), separate from provider business logic. If neither a supported Ability nor public API exists, report the precise gap rather than invoking provider-private storage. A new plugin/theme with compatible public Abilities should normally need no Bridge source edit. The private Bridge Workspace remains intentional transport/project-continuity infrastructure, not a feature to extract.

## Astra / Astra Pro

Astra exposes native `astra/*` Abilities when its **Abilities** setting is enabled. The Bridge reuses those registered Abilities instead of creating duplicate Astra tools.

For this setup:

- enable Astra **Abilities** if Astra operations should be available;
- a separate Astra MCP option/server is not required;
- the Bridge does not silently enable Astra Abilities for the site owner.

## Code Snippets

Compatible Code Snippets versions expose a namespaced programmatic lifecycle that the Bridge can use for managed snippets.

Supported operations include read, create/update, activate/deactivate, trash/restore, and permanent deletion of an already-trashed snippet when the required access is enabled.

The Bridge:

- supports the provider model generations used by Code Snippets 3.9.x and 3.10.x;
- asks Code Snippets for its current management capability rather than hardcoding an obsolete capability name;
- validates the scope through the provider;
- rejects locked snippets;
- never writes Code Snippets tables directly;
- never directly evaluates the submitted snippet itself.

## Gravity Forms

If native `gravityforms/*` Abilities are registered, the Bridge defers to that provider surface and does not create a parallel fallback.

When no native surface is registered and documented `GFAPI` is available, the bounded fallback can manage form definitions and form status. Form-definition reads follow Gravity Forms' documented `gravityforms_edit_forms` capability contract; the provider does not define a separate read-only form capability. Entry/submission data is not part of this fallback.

## WooCommerce

When stable WooCommerce product Abilities are registered, the Bridge may expose them through normal Ability discovery/reuse.

The Bridge does not automatically create broad fallbacks for orders, customers, payments, or other sensitive commerce data merely because WooCommerce is installed.

## Provider visibility

`wp-ai-bridge/integration-status` reports observed provider mode and Ability names. An installed provider may legitimately report `unavailable` when the supported API/Ability contract required by the Bridge is not available in the current environment.
