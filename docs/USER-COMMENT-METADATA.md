# User and comment metadata

WP AI Bridge exposes bounded provider-neutral metadata operations for WordPress users and comments under the existing **Advanced Metadata** administrator delegation boundary.

This surface is not an arbitrary database, user-role, authentication, session, or credential-management API.

## Abilities

| Ability | Bridge delegation | WordPress authority |
| --- | --- | --- |
| `wp-native-builder/user-meta-read` | Advanced Metadata | exact target `edit_user` plus applicable metadata capability/provider authorization |
| `wp-native-builder/user-meta-update` | Advanced Metadata | exact target `edit_user` plus `add_user_meta` or `edit_user_meta` as applicable |
| `wp-native-builder/user-meta-delete` | Advanced Metadata + Users & Destructive | exact target `edit_user` plus `delete_user_meta` |
| `wp-native-builder/comment-meta-read` | Advanced Metadata | exact target `edit_comment` plus applicable metadata capability/provider authorization |
| `wp-native-builder/comment-meta-update` | Advanced Metadata | exact target `edit_comment` plus `add_comment_meta` or `edit_comment_meta` as applicable |
| `wp-native-builder/comment-meta-delete` | Advanced Metadata + Users & Destructive | exact target `edit_comment` plus `delete_comment_meta` |

The Bridge rechecks these boundaries during execution. Enabling Advanced Metadata never grants a WordPress capability that the connected principal does not already have.

## Exact targets and provider authorization

User targets must resolve through `get_userdata()` and pass `edit_user`. Comment targets must resolve through `get_comment()` and pass `edit_comment`.

Registered metadata and explicit `auth_*_meta_*` provider contracts remain authoritative. For an unregistered protected key, Advanced Metadata may replace only WordPress's generic protected-key default denial. Any explicit provider denial, `do_not_allow`, or additional primitive capability produced by WordPress capability mapping still denies the operation.

## Multisite authority

User metadata does not grant network-user administration. WordPress's current `edit_user` mapping remains authoritative on multisite, including Super Admin and `manage_network_users` boundaries. A site administrator who cannot natively edit another network user cannot use generic metadata to read or mutate that user's metadata.

Site-specific role and capability storage remains outside the generic surface. Keys such as `<blog-prefix>capabilities` and `<blog-prefix>user_level` are omitted from broad discovery and rejected for exact access even for a Super Admin. Role changes remain owned by the user-administration contract rather than metadata mutation.

## Privacy boundary

Broad inspection returns bounded key/state metadata only. Values require one exact requested key.

The shared provider-neutral sensitive-key policy applies to both user and comment metadata. User metadata additionally fails closed for role/authority/authentication state, including capability maps, user-level metadata, session-token storage, and application-password state. Role assignment remains owned by the user-administration contract rather than generic metadata.

Credential-, password-, secret-, token-, session-, OAuth-, API-key-, private-key-, and equivalent security identities are outside this generic surface. Mutation logs contain only operation/status metadata and target identity; they do not contain metadata keys or values.

## Physical state and stale-write protection

An exact read returns a `state_hash` derived from physical metadata row identity and raw stored bytes. Update and delete require that exact hash. A stale hash fails closed.

The generic mutator supports only a single physical row for an exact key. Multi-row keys are readable as bounded state but cannot be generically updated or deleted because selecting one row would be ambiguous.

Creation uses normal WordPress `add_metadata(..., true)` behavior so provider sanitization and add lifecycle hooks remain authoritative. Existing-row update/delete use a fixed-purpose internal persistence helper bound only to `usermeta` or `commentmeta`. It accepts no SQL, table, column, query fragment, or caller-selected row ID.
The create path captures the exact row identity from Core’s `added_*_meta` lifecycle by returned `meta_id`, so nested same-key writes cannot replace Bridge ownership. If a provider sanitizer expands the stored value beyond the 1 MiB bound, the Bridge removes only its own still-unchanged row before returning the bounded-value error; observer-modified or concurrent state is preserved.

The helper performs byte-exact compare-and-swap on the inspected physical row, emits the normal dynamic WordPress metadata lifecycle, invalidates the normal metadata cache, and verifies the resulting physical state. If concurrency is detected after a Bridge-owned mutation, compensation is itself bound to the exact row and exact bytes written by that invocation. Newer unrelated state is preserved rather than overwritten.

The fixed direct database statements exist only because the generic WordPress metadata helpers cannot express the required byte-exact physical-row preimage predicate. Static confinement checks keep that persistence surface closed.

## Value contract

Submitted values use `value_json` and must round-trip through the generic JSON contract without structural loss. PHP objects/resources, excessive or non-representable structures, and physical values larger than the bounded contract fail closed.

Registered WordPress sanitizers remain authoritative. A caller cannot use the generic metadata surface to bypass a provider sanitizer or short-circuit contract.

## Non-goals

This surface does not provide:

- arbitrary SQL or database administration;
- caller-selected tables, columns, queries, or physical row IDs;
- WordPress option access;
- user role/capability assignment through metadata;
- session-token lifecycle or generic application-password metadata mutation (Application Password administration uses the separate default-off Authentication & Credentials contract);
- generic credential/secret management;
- bulk value dumps;
- ambiguous multi-row mutation;
- network-user or cross-site authority bypass.
