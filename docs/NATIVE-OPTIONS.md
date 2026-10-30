# Native site and network settings reads

This guide describes current source functionality pending release. A source commit, published release, installed plugin, exposed Ability and successful authorized operation are separate facts.

`wp-ai-bridge/native-options-read` reads the effective native WordPress Options API view and creates complete private value snapshots. Use it for legitimate settings that do not have a registered REST setting, including sensitive provider configuration under explicit administrator consent. The existing registered REST setting Abilities retain their own schemas, permissions and lifecycle. One separately consented native add/update/delete family is documented in [Native option mutations](./NATIVE-OPTION-MUTATIONS.md), also pending release.

## Consent and scope

- **Sensitive Native Site Settings Read** defaults off and belongs to the current site's Bridge settings. Site execution requires the current principal's `manage_options` capability.
- **Sensitive Native Network Settings Read** also defaults off and is owned by the current network. Network reads require both grants, actual Super Admin and `manage_network_options`, as well as site administration authority.
- Network consent uses a separate nonce-checked current-network form in Bridge Settings. Saving ordinary site settings cannot enable, revoke or restore the network grant.
- `scope=site` always means the current blog. `scope=network` always means the current network and requires multisite. There is no caller-selected blog/network/database/table/path.

Every generic native value may contain secrets, even under a neutral name. These grants do not inherit Site Read, Site Configuration, registered REST, database or media/source transfer authority. Permission, execution, sensitive return and every snapshot continuation recheck current authority and the original principal/client/site/network identity.

## Actions

| Action | Input beyond `action` and `scope` | Result |
| --- | --- | --- |
| `list` | Optional `after_id`, `name_prefix`, `limit` | Keyset page of physical option identities and size metadata; never values. |
| `read` | `name` | One effective native value, complete inline representation when it fits, otherwise explicit snapshot guidance. |
| `snapshot` | `name` | Capture one effective value as an immutable private encoded snapshot. |
| `snapshot_read` | `transfer_id`, `offset`, optional `length` | An authenticated Base64 window of that snapshot. |
| `snapshot_status` | `transfer_id` | Current private lease metadata. |
| `snapshot_release` | `transfer_id` | Release the snapshot bytes. |

The mixed Ability is statically non-readonly and non-idempotent because snapshot creation persists private bytes. It is not a settings mutation API.

`list` inventories stored current-site options or current-network metadata. IDs and continuation cursors are decimal strings; native keysets avoid repeated offset scans. Physical inventory cannot enumerate every virtual name a provider implements through filters. Its row size/autoload observations are storage metadata, not an effective native value or a stable snapshot of the entire collection.

`read` and `snapshot` accept exact nonempty UTF-8 names without NUL or implicit trimming. They call `get_option()` or `get_network_option()` with the current scope. Native filters, defaults, caches and serialization semantics remain authoritative. A returned `false` is not proof that a physical row is absent. `storage_presence=unknown` is intentional; a native observed-value hash is neither a raw-storage digest nor an atomic revision.

## Lossless observed-value representation

`value_json` contains a complete JSON document with `format=wpai-native-option-value-v1` and a tagged `value`. Private snapshot bytes are that same complete document.

| PHP value | Tag |
| --- | --- |
| NULL | `{"type":"null"}` |
| Boolean | `{"type":"boolean","value":true}` |
| Integer | `{"type":"integer","decimal":"9223372036854775807"}` |
| Float | `{"type":"float","base64":"<8-byte big-endian IEEE754>"}` |
| Byte string | `{"type":"string","base64":"<canonical Base64>"}` |
| Array | `{"type":"array","entries":[{"key":<integer or string tag>,"value":<tag>}]}` |

Array entries preserve observed order, sparse indexes, and integer versus string keys. Integer decimal text avoids JSON numeric precision loss. Byte strings preserve NUL and invalid UTF-8. Float bytes preserve negative zero and native nonfinite values without converting them to lossy JSON numbers. A serialized-looking PHP string stays a byte string.

The initial encoder explicitly rejects objects, resources, references/cycles and nesting beyond its supported representation depth. It does not invoke string casts, magic methods or JSON serializers to disguise unsupported data. These are current representation boundaries, not permanent exclusions from the administrator program. WordPress's getter itself may already deserialize stored objects before the encoder sees them; the transport does not claim to prevent that Core behavior.

## Complete private snapshots and capacity

A snapshot captures the provider's effective value once. Window reads never call the provider again or mix later versions into the saved bytes. Creation/status return `transfer_id`, `total_bytes`, `sha256`, `expires_at` and `state`. Reads additionally return `offset`, `next_offset`, `complete`, `base64` and `chunk_sha256`. Reconstruct every byte in order and independently verify the complete length and SHA-256, then release the lease.

Site and network snapshots have new fixed private purposes. Generic private-transfer/media/source/database consent cannot read them. The existing owner/client/blog binding is retained; network snapshots also retain their originating network identity. No public URL, arbitrary path or source filename is exposed.

Inline values are all-or-unavailable, never truncated. Snapshot windows fit the actual encoded MCP response policy. Existing administrator request/response/chunk/file/reserved-byte/session/TTL policies and real PHP/disk capacity govern the operation. There is no new lifetime option/value/operation count ceiling.

The native getter materializes a complete PHP value. Private chunked output does not make native retrieval constant-memory: PHP must have enough capacity for the provider value and its encoded representation. Encoding is admitted against the operator's transfer file policy and, when finite PHP memory is known, one eighth of currently free memory; it checks output incrementally. With no known finite PHP bound, the finite operator policy remains the admission bound. Read metadata reports processing_limit_bytes and processing_memory_limit_known. A provider filter may allocate memory before returning; Bridge cannot make that provider computation streaming. Use the separately authorized raw database byte-window tool when physical storage inspection is the appropriate operation.

## Integrity, diagnostics and remaining coverage

Hook-capable calls use the existing exception-safe WordPress dispatch recovery boundary. Principal/client/blog/network drift denies sensitive output. Audit metadata uses fixed actions, scope/name fingerprints and unique events; values, raw names and data-bearing provider exception text are not written into general Activity or errors. A sensitive read whose required audit cannot be confirmed does not return its value. Confirmation requires the exact generated redacted event in bounded native Activity readback under unchanged caller and storage context; a successful write that transforms or drops that event is insufficient. This remains a finite operational Activity history, not an immutable archival audit.

Native reads preserve the filters installed in the current request. They do not execute provider admin screens, run arbitrary UI hooks or prove provider-specific business workflows. No add/update/delete, transaction, atomic CAS or exactly-once mutation claim is made by this read/export contract.

The separate [native mutation contract](./NATIVE-OPTION-MUTATIONS.md) covers one add/update/delete family, inert write-input staging and historical recovery. Read snapshots cannot be used as write inputs. Broader theme-mod/transient/provider workflows, schema/advanced SQL, logs/queues/files and complete backup with verified restore remain accepted work in [Issue #199](https://github.com/AChWorks/wp-ai-bridge/issues/199). See [database administration](./DATABASE-ADMINISTRATION.md) for the distinct raw storage contract and [registered REST settings](./REGISTERED-SETTINGS.md) for its narrower REST lifecycle.
