# Native site and network option mutations

This guide describes the v0.7.0 package. Source, release, installed plugin, exposed Ability and successful client execution are separate facts.

`wp-ai-bridge/native-options-write` adds, updates or deletes one current-site or current-network option through WordPress's native Options API. It also provides historical operation status and private input preparation. WordPress owns sanitization, defaults, serialization, caches, filters, callback effects and races. Use a provider's supported operation when the task requires its business workflow.

| Action | Current site | Current network |
| --- | --- | --- |
| `add` | `add_option()` | `add_network_option()` |
| `update` | `update_option()` | `update_network_option()` |
| `delete` | `delete_option()` | `delete_network_option()` |

## Consent and scope

| Request | Required authority |
| --- | --- |
| Site add/update and input preparation | Sensitive Native Site Settings Read + Sensitive Native Site Settings Write + Site Configuration, with current native `manage_options`. |
| Network add/update and input preparation | The site requirements, both current-network sensitive read/write consents, actual Super Admin, `manage_network_options` and `manage_options`. |
| Delete | All corresponding write requirements plus Users & Destructive and `confirm=true`. |
| Historical `status` | Current scope-specific sensitive native read authority and the original scope, blog, network, WordPress user and authenticated client. Status never invokes a setter. |

The new site write grant `sensitive_native_settings_write` defaults off on fresh installs and upgrades. The new network write grant `sensitive_network_settings_write` defaults off and is stored in the separate current-network-owned `wp_ai_bridge_network_settings_write` scalar. The existing network read consent remains independent. Ordinary site settings saves cannot change either network-owned consent; the network forms retain their own nonce and native authorization checks.

`scope=site` means the current blog; `scope=network` means the current network on multisite. Callers cannot supply another blog, network, user, client, connection, table, callback or path. Every input continuation and sensitive return rechecks current authority and the original context. The retained native connection's actual selected database is checked before journal sessions, before and after native getters, and after audit before sensitive output. These checks detect observable namespace drift; they do not lock provider callbacks.

## Closed actions

Every request requires `action` and `scope`. Additional properties are action-specific; unknown actions and unrelated fields are rejected.

| Action | Required additional input |
| --- | --- |
| `add`, `update` | Exact `name`, `operation_id`, `value_source`; optional `expected_observed_sha256`. |
| `delete` | Exact `name`, `operation_id`, `confirm=true`; optional `expected_observed_sha256`. |
| `status` | `operation_id`. |
| `input_begin` | Complete document `total_bytes` and lowercase `sha256`. |
| `input_append` | `transfer_id`, exact byte `offset` and canonical `base64`. |
| `input_verify`, `input_status`, `input_release` | `transfer_id`. |

The mixed Ability is statically `readonly=false`, `destructive=true` and `idempotent=false`, including status and input actions. There is no batch, patch, rename or autoload selector.

`operation_id` is mandatory for each mutator: 8–128 ASCII letters, digits, underscores, hyphens, dots or colons. Keep it with the exact request for recovery. Its durable identity includes purpose, scope, blog, network, user and client. Action, exact name, input-value identity and optional precondition belong to its request fingerprint. Reusing the same ID for a different request conflicts.

## Complete values

`value_source` is exactly one of:

- `{"value_json":"<complete canonical v1 document>"}`
- `{"transfer_id":"<verified native write input>","sha256":"<complete document digest>","total_bytes":123}`

The first alternative contains a JSON **string**, not a nested value object. This complete request supplies the byte string `hello` to native update:

```json
{
  "action": "update",
  "scope": "site",
  "name": "example_plugin_message",
  "operation_id": "example-message-0001",
  "value_source": {
    "value_json": "{\"format\":\"wpai-native-option-value-v1\",\"value\":{\"type\":\"string\",\"base64\":\"aGVsbG8=\"}}"
  }
}
```

Only the embedded document must use canonical bytes; ordinary formatting of the outer request is allowed. The exact embedded document is:

```json
{"format":"wpai-native-option-value-v1","value":{"type":"string","base64":"aGVsbG8="}}
```

This is the same v1 representation returned by [native reads and snapshots](./NATIVE-OPTIONS.md#lossless-observed-value-representation). A direct closed decoder accepts only canonical encoder output: no added whitespace, reordered/extra/duplicate properties, JSON escape alternatives, noncanonical Base64 or integer text. It preserves binary strings, native-range integers, IEEE754 float bits and ordered arrays with exact integer/string keys. Duplicate or PHP-coercing keys, unsupported types, non-round-tripping float bits and nesting beyond depth 64 are rejected. Serialized-looking strings remain bytes; input decoding never calls `unserialize()`, class constructors or magic methods.

Codec fidelity describes the complete PHP value supplied to Core. Native sanitizers, filters and serialization may change its stored or later observed representation.

### Private write inputs

For a value larger than one inline request:

1. Encode the complete canonical document and compute its byte length and SHA-256.
2. Call `input_begin`, then append canonical Base64 chunks at the exact returned progress offset within `chunk_bytes`.
3. Call `input_verify`; it verifies complete bytes and SHA-256 only. It does not promise valid canonical syntax or an acceptable native value.
4. Submit the resulting `transfer_id`, `sha256` and `total_bytes` as `value_source` with one mutator.
5. Release unused input bytes with `input_release`.

Input receipts expose `transfer_id`, `total_bytes`, `sha256`, `received_bytes`, `chunk_bytes`, `expires_at` and `state`. Release additionally reports `released=true` and `state=released`. The mutator independently verifies the complete immutable input identity and strictly decodes it before target dispatch.

The fixed purposes are `native_site_settings_write` and `native_network_settings_write`. Read snapshots, media, database and generic leases cannot serve as native write inputs. These stages reuse existing private storage, reservations, owner/client/blog/network binding and cleanup; no public temporary path is returned.

If `input_begin` fails after creating a lease, cleanup is limited to that invocation's own newly created input, including after authority is revoked. It does not discard an older input or undo a native journal/effect. Unverified cleanup returns `native_option_write_input_cleanup_uncertain`; inspect private storage and active reservations before preparing another input.

Release can stop loading an input before materialization completes. Once an immutable value has been fully materialized, releasing its stage does not atomically cancel a native mutation. No stage or database lock is held across native callbacks.

### Capacity

Inline requests must fit the existing request policy. Metadata/schema/journal processing uses the larger configured request or response budget, capped at one eighth of known free PHP memory; the actual outgoing JSON must independently fit the response budget. A smaller response window is not itself the metadata-processing ceiling. Staging uses existing request, chunk, file, reserved-byte, active-session and TTL policies plus real disk capacity. Native mutation still materializes the whole encoded document and native PHP value. Chunked transport does not make that work constant-memory.

Processing admission uses the operator's file policy and conservative headroom from known free PHP memory, with actual allocation checks. Responses report `processing_limit_bytes` and `processing_memory_limit_known`. If no finite PHP memory bound is known, a finite operator-policy fallback applies and the latter is false; there is no blanket lockout or fixed node/lifetime quota. Increase the relevant operator/host capacity when a complete value cannot be admitted; do not truncate it or split one native setting into artificial partial mutations.

## Exact targets and owning APIs

Names must be exact nonempty UTF-8, contain no NUL, require no trimming and fit the real native column representation. Before any target getter or setter, the guard checks proposed and matched names under native database collation. Bridge control namespaces, non-exact collating storage aliases, and Core protected/deprecated aliases are rejected with owning or canonical API guidance. This includes protected variants when no exact row exists.

The seven specialized site setting names remain owned by `site-settings-update`:

| Native name | Specialized input |
| --- | --- |
| `blogname` | `site_title` |
| `blogdescription` | `tagline` |
| `show_on_front` | `show_on_front` |
| `page_on_front` | `page_on_front` |
| `page_for_posts` | `page_for_posts` |
| `posts_per_page` | `posts_per_page` |
| `permalink_structure` | `permalink_structure` |

Ordinary plugin secrets and role/account settings are not excluded merely for being sensitive. Their native/provider semantics still matter. Exact duplicate network names retain Core behavior; ambiguous non-exact collating aliases are rejected.

`expected_observed_sha256` compares a fresh effective native value with the supplied observed-value digest. It is a stale-intent check, not atomic compare-and-swap, physical-row identity or a schema lock. Target observations can change before a later native call. Native add is not exclusive creation; concurrent callers and callback re-entry retain Core semantics.

## One dispatch and historical recovery

A durable reservation precedes the one Bridge native setter invocation. Only a positively acknowledged winning invocation receives an internal dispatch claim. After the final hook-capable authorization check, a fresh empty journal section must be acknowledged and restored before dispatch. No WordPress callback occurs between that acknowledgement and the native setter call.

Journal sections reuse the existing native database connection and shared verified receipt schema. InnoDB and verified trigger/default/foreign-key eligibility apply to journal storage; this does not impose the typed raw-DML target-effect restrictions on native network setters. Positive `HANDLER OPEN/CLOSE` clean-session admission rejects incompatible active transactions and SQL table locks. Journal transactions end before callbacks; completion cannot commit or repair a transaction left open by provider code. Unverified storage, session state, commit acknowledgement or restoration fails closed. The existing typed database writer retains its separate transaction and SQL-lock behavior.

The protected native journal uses exact indexed `wpai_native_option_operation_<64hex>` keys in existing native options storage. It has finite per-operation metadata budgets and actual storage errors, with no new lifetime operation count, aggregate coordinator or count scan. Existing generic/canonical create quotas are unchanged.

Historical status and same-key retry never resend a setter after a pending, completed or uncertain reservation. Keep the original ID and inspect status after a lost reply; do not substitute a new ID to evade the fence. A missing historical receipt is not proof that no invocation is in flight.

Successful envelopes identify `action`, `scope`, `blog_id` and `network_id`. Mutation/status envelopes also contain an opaque `operation_ref`, `historical`, `receipt` and journal `state`:

| Journal state | Meaning |
| --- | --- |
| `not_recorded` | Status found no committed receipt; `receipt=null` and `operation_ref` is empty. This does not prove no attempt or in-flight invocation. |
| `fenced` | A reservation exists without a completed result. Its identity cannot dispatch again. |
| `completed` | A retained outcome record is available. This records journal completion, not proof of a desired business effect or successful Activity projection. |

`historical=true` marks a historical readback. The protected receipt includes its action/name/fingerprint and original scope/owner metadata. `observed_after_sha256`, `observed_after_available` and `observation_error` describe a separate point-in-time native read, not transactional persistence verification. Optional `native_errno` and `native_sqlstate` appear only when safely captured by the journal; they do not attribute a native callback failure. Fresh mutation results additionally report `diagnostic_phase=after_native_call`, `native_diagnostics_available=false` and `last_error_present` (boolean or null). That last field records only whether `wpdb` held error text at that point, not its cause or the native setter's driver status. No raw SQL or provider exception text is returned.

Outcome reporting distinguishes `not_dispatched`, `native_call_returned` and `unknown`, with `native_return=true|false|null`, `callback_threw`, safely available bounded native numeric diagnostics and separately classified point-in-time observed hashes. Interpret them as follows:

- `false` can mean a Core no-op, rejection or failure after callback effects. It does not prove no changes occurred.
- `true` means the native call returned true. It does not prove unchanged persistence after observers, external business durability or an exact intended value.
- A callback throw after a write, lost reply or missing final record can remain unknown. Completion/status cannot roll back native or external effects.
- Required Activity reporting failure after dispatch is a reporting failure; it cannot be relabeled “not applied.”

Safe error metadata includes `phase`, per-call `dispatch_attempted` and an operation reference when known. `dispatch_attempted=true` means this invocation reached the native dispatch boundary, not that it succeeded. False refers only to this invocation and does not negate an earlier historical effect. When required Activity confirmation fails, an existing safe source error retains its code with `audit_confirmed=false`; a successful sensitive result is withheld behind a reporting error. Recover with the original operation ID in either case.

The fence bounds Bridge dispatch. It is not a transaction around callbacks, rollback protocol, native CAS or exactly-once business-effect guarantee.

## Recovery privacy and remaining coverage

Receipts contain no setting values or raw exception messages. Exact names may be retained in the protected purpose-owned journal and returned by authorized status for functional recovery. General Activity and errors retain scope/name fingerprints and bounded outcome metadata instead of raw names, values or provider exception text. Sensitive readback reauthorizes the original context; site-only authority cannot expose a network receipt.

This package covers one native Options family. Broader theme-mod/transient/provider workflows, advanced SQL/schema, accessible logs, queues, scoped files and full database/files backup with verified restore remain accepted work in [Issue #199](https://github.com/AChWorks/wp-ai-bridge/issues/199). Passing engine prerequisites do not establish implemented feature acceptance. Exact candidate checks, native OAuth/MCP/multisite execution, independent review, release and installed-client verification remain separate evidence.
