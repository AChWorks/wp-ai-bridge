# Registered REST settings

WP AI Bridge can expose WordPress settings that are already part of the site's supported REST settings contract without maintaining a provider-specific option allowlist.

This surface is intentionally narrower than arbitrary option access.

## Abilities

- `wp-native-builder/registered-settings-list` discovers currently registered, non-sensitive REST settings and returns contract metadata only. It never returns setting values.
- `wp-native-builder/registered-setting-read` reads one exact non-sensitive REST-visible setting under **Site Configuration** authority.
- `wp-native-builder/registered-setting-update` updates one exact non-sensitive REST-visible setting under **Site Configuration** authority.

The historical `site-settings-read` and `site-settings-update` abilities remain available for their existing bounded Core behavior, including front-page invariants and permalink/rewrite impact handling.

## WordPress remains authoritative

The generic surface is derived from settings registered with `register_setting()` and `show_in_rest`. The Bridge reuses the fixed Core `/wp/v2/settings` contract rather than exposing `get_option()`, `update_option()`, an arbitrary REST route, or provider-private storage.

The connected WordPress principal must retain Core `manage_options` authority. Provider/Core REST aliases, JSON schemas, sanitization, `rest_pre_get_setting`, and `rest_pre_update_setting` behavior remain authoritative.

A provider can therefore add a normal REST-registered setting without a WP AI Bridge source edit.

## Discovery does not expose values

Broad discovery returns only the REST-visible name plus bounded type/title/description/schema metadata. The physical option name and current/default value are not part of the public discovery result. Schema metadata removes `default`, `example`, `examples`, and runtime `arg_options` fields before serialization.

Exact reads and updates shape the result back to the one requested setting. WP AI Bridge never relays the complete `/wp/v2/settings` response even though Core may internally produce it for an authorized administrator.

## Sensitive setting boundary

The provider-neutral credential/session key policy also applies to registered settings. A setting whose physical or REST-visible identity is recognized as credential-like is omitted from discovery and unavailable through generic exact read/update. The registered-settings provider also treats common access, consumer, license, encryption, and signing key identities as credential-like.

Structured object/array settings are inspected recursively through their registered REST schema. If a nested property is credential-like, or a structured schema is too open to establish a bounded property contract, the entire setting fails closed instead of allowing the generic settings surface to become a nested secret-management path.

This generic surface is not a secret-management API. API keys, passwords, credentials, private keys, OAuth/client secrets, access/refresh/session/authentication tokens, license/access/consumer keys, and equivalent setting identities require a purpose-specific lifecycle if they ever need Bridge management.

## Value transport

Exact values use `value_json` so the Bridge can carry WordPress REST setting types without pretending every provider setting is a string. `value_available` tells callers whether that bounded JSON representation is present. Update input must contain one valid, bounded, non-null JSON value. Core's registered schema and sanitization then decide whether that value is acceptable.

`null` is deliberately not accepted by the generic update Ability because Core interprets a null settings update as option deletion/reset. A purpose-specific reset/delete operation can be added separately when its semantics and authority are explicit.

Ordinary setting values are bounded to 1 MiB. If an exact Core read produces a value that cannot be safely represented inside that bound, the Bridge returns `value_available=false` and an empty `value_json` rather than leaking an unbounded payload. After an exact update, a successful Core POST remains a successful mutation even if the provider's resulting value cannot be safely represented; in that case the result likewise reports `value_available=false` instead of falsely claiming the already-committed mutation failed.

Public schema JSON is bounded separately; an oversized schema remains discoverable with `schema_available=false` but cannot leak an unbounded contract payload.

## Authorization

- Discovery: **Site Read** must be enabled and the connected WordPress principal must have `manage_options`.
- Exact value read: **Site Configuration** must be enabled and the connected WordPress principal must have `manage_options`.
- Exact update: **Site Configuration** must be enabled and the connected WordPress principal must have `manage_options`.

These grants do not weaken provider/Core authorization and do not create a Gateway-specific permission lane.

## Non-goals

The registered-settings surface does not provide:

- arbitrary unregistered options;
- raw Options API or database access;
- network-option administration;
- generic secret/credential/session management;
- provider-private tables or undocumented APIs;
- caller-selected REST routes or HTTP methods.
