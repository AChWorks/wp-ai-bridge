# Integrations

The default runtime is intentionally small: **WordPress MCP Adapter + WP AI Bridge**. Optional plugins/themes remain normal site components, not additional AI infrastructure.

The canonical integration policy is documented in [Architecture](./ARCHITECTURE.md#discovery-first-provider-architecture). In short, the Bridge discovers and reuses provider-owned Abilities first, uses a bounded public-API fallback only for a real gap, and otherwise reports the surface as unavailable. A newly installed plugin or theme that already exposes compatible public WordPress Abilities should normally require no Bridge source change.

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

`wp-native-builder/integration-status` reports observed provider mode and Ability names. An installed provider may legitimately report `unavailable` when the supported API/Ability contract required by the Bridge is not available in the current environment.
