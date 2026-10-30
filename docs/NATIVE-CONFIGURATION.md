# Native theme, transient and Customizer configuration

This guide describes source functionality; [Issue #199](https://github.com/AChWorks/wp-ai-bridge/issues/199) tracks exact native acceptance, integration and release evidence. A source commit, passing fixture, published release, installed plugin and verified client workflow are separate facts.

`wp-ai-bridge/native-configuration` uses Core's current-theme Theme Modification API and exact Transients API. `wp-ai-bridge/customizer-settings` discovers and publishes settings registered through the current theme's native Customizer. They complement the existing native Options, registered REST settings, navigation, content/blocks, public Abilities and registered REST contracts.

## Authority and scope

Both new grouped grants default off in the protected administration settings. Ordinary settings saves and historical all-enabled grants cannot enable them.

| Operation | Required current authority |
| --- | --- |
| Configuration read/list/snapshot/status | Native Configuration, Sensitive Native Site Settings Read and `manage_options`. |
| Configuration set/input preparation | Read prerequisites, Sensitive Native Site Settings Write and Site Configuration. |
| Configuration delete | Write prerequisites, Users & Destructive and `confirm=true`. |
| Current-theme operations | Corresponding prerequisites and `edit_theme_options`. |
| Multisite site-wide transient reads | Site prerequisites, current-network sensitive read consent, actual Super Admin and `manage_network_options`. |
| Multisite site-wide transient writes/inputs | Read prerequisites plus current-network sensitive write consent. |
| Customizer operations | Separate Customizer grant, sensitive native prerequisites, `customize`, `edit_theme_options`, and each actual setting's capabilities/theme support. |

Customizer registration, value reads and publication can execute unclassified provider callbacks. Before initializing that registry, Bridge requires the same conservative protected-lifecycle grant/capability set used by generic registered REST execution: Builder Write, Remote Media, Live Content, Site Configuration, Advanced Metadata, Authentication & Credentials, Code & Extensions, External Packages, Source Editing, Comments and Users & Destructive. Discovery never grants target permission. Historical status and inert input/snapshot continuations do not initialize the provider registry.

Every operation and sensitive output rechecks current authorization and the original WordPress user, authenticated client, blog and network. Theme stages/snapshots additionally bind the captured stylesheet. No public argument selects another principal, client, site, network, database, callback, class, theme to preview, private path or existing Customizer changeset.

A site transient is local to the current blog. A **site-wide transient** uses Core's `get_site_transient()` family: it is installation-local on single-site WordPress and belongs to the current network on multisite. Single-site site-wide transients remain supported.

## Native configuration input

Every request requires `action` and `target`. Targets are `theme_mod`, `transient` and `site_transient`. Each action has a closed schema; unrelated fields are rejected.

| Action | Additional input |
| --- | --- |
| `list` | Theme-mod keys only; optional `after_name`, `limit`. |
| `read`, `snapshot` | Exact `name`; optional current `expected_stylesheet` for theme mods. |
| `set` | `name`, stable `operation_id`, `value_source`; theme mods require `expected_stylesheet`; transients require explicit nonnegative `expiration_seconds`. Optional `expected_observed_sha256`. |
| `delete` | `name`, `operation_id`, `confirm=true`; theme mods require `expected_stylesheet`. Optional `expected_observed_sha256`. |
| `status` | Original `operation_id`. No native target getter/setter is rerun. |
| `input_begin` | Complete v2 document `total_bytes`, `sha256`. |
| `input_append` | `transfer_id`, exact `offset`, canonical `base64`. |
| `input_verify`, `input_status`, `input_release` | `transfer_id`. |
| `snapshot_read` | `transfer_id`, exact `offset`; optional positive `length`. |
| `snapshot_status`, `snapshot_release` | `transfer_id`. |

Theme-bound input and snapshot continuation requests also require their original `expected_stylesheet`. Name cursors are exact strings, not offsets. Theme-key inventory describes the materialized native stored mod array; it does not enumerate every virtual key implemented by filters. There is no generic complete transient-key registry when a persistent object cache is active. Exact named transient operations work through the actual native backend.

Examples:

```json
{"action":"read","target":"transient","name":"example_provider_cache"}
```

```json
{"action":"set","target":"theme_mod","name":"example_accent","expected_stylesheet":"active-child-theme","operation_id":"accent-update-0001","value_source":{"value_json":"{\"format\":\"wpai-native-option-value-v2\",\"value\":{\"type\":\"string\",\"base64\":\"IzExNjZiYg==\"}}"}}
```

Transients have Core's documented name limits: 172 characters for ordinary transients and 167 for site-wide transients. Bridge protects its own credential/control transient namespaces and checks the actual native option/network storage representation and collating aliases before target APIs run. Ordinary provider credentials remain sensitive administrator-controlled data; a neutral name is not a safe-data classification.

Native filters, serialization, caches, expiration and callback effects remain authoritative. Reading an expired transient can delete its native storage. A returned `false` cannot distinguish absence, expiry and stored false. Replies report `storage_presence=unknown`; these mixed Abilities advertise `readonly=false`, `destructive=true`, `idempotent=false`. Expiration is Core's maximum cache lifetime, not guaranteed retention. Theme-mod deletion removes a key and may remove the containing option when it was the final key.

## Complete v2 values and private exchange

These new contracts explicitly opt into `wpai-native-option-value-v2`. Existing native Options v1 encode/decode defaults and existing transfer purposes remain unchanged.

V2 retains v1's exact null, boolean, machine integer decimal text, IEEE754 float bytes, binary string Base64 and ordered typed array entries. It adds only an exact `stdClass` data node:

```json
{"format":"wpai-native-option-value-v2","value":{"type":"stdClass","entries":[{"key":{"type":"string","base64":"ZW5hYmxlZA=="},"value":{"type":"boolean","value":true}}]}}
```

Object property names are string nodes, including binary/NUL and numeric property names; order and byte identity are preserved. The decoder uses a native array-to-`stdClass` cast, with no supplied class name, arbitrary constructor, `unserialize()`, magic method or custom serializer. Other classes, resources, references, repeated object aliases, cycles, duplicate keys/properties, noncanonical encodings and unsupported depth are rejected. This is a precise representation boundary, not a claim to transport arbitrary provider object classes.

`value_source` is exactly one of:

- `{"value_json":"<complete canonical v2 document>"}`
- `{"transfer_id":"<verified purpose-owned input>","sha256":"<complete digest>","total_bytes":123}`

For large values, upload the complete v2 document through input begin/append/verify, then submit the verified identity to one setter. A verified stage proves byte integrity; the consumer still strictly decodes it. The stage lock ends before native callbacks. Releasing a stage after materialization is not an atomic cancellation of a native effect.

Reads return the entire `value_json` or `value_available=false`, `snapshot_required=true`, without truncation. `snapshot` captures a complete immutable v2 document; window reads return Base64 bytes, per-window SHA-256, complete length/digest and continuation offsets. Reassemble and verify every byte, then release it. Receipt fields are `transfer_id`, `total_bytes`, `sha256`, `received_bytes`, `chunk_bytes`, `expires_at`, `state` where applicable.

Configuration inputs/snapshots and Customizer inputs/snapshots use four distinct fixed private purposes. Existing media, native Options, database or other family stages cannot be adopted as configuration inputs. Failed creation/output/audit paths can delete only their own new inert stage using the original private creation proof.

Existing administrator request/response/chunk/file/reserved-byte/session/TTL policies and actual PHP/disk resources govern capacity. Complete native getters, codecs and setters still materialize values. Chunked exchange does not make provider execution constant-memory. Lists paginate by actual response capacity rather than a lifetime object count.

## Customizer publication

Customizer requests use the same actions for list/read/snapshots/input/status, with implicit target `customizer_setting`. Every request requires the exact current `expected_stylesheet`. Updates use `action=update`, exact registered setting `name`, `operation_id`, `value_source` and optional observed hash. There is no caller-selected theme, changeset UUID, author or arbitrary setting implementation.

Bridge initializes a fresh owned manager without previewing settings or switching themes. For late initialization, it registers Core defaults before provider `customize_register` callbacks, matching Core's own late-publication ordering. Exact dynamic setting names may resolve through Core's public registration mechanism; dynamic names are not claimed to be enumerable. Providers must make their Customizer registration dependencies available in an ordinary authenticated request after theme bootstrap. Providers requiring an earlier admin/preview-only bootstrap are unavailable through this late context: `customizer_late_bootstrap_unavailable` returns before journal reservation or publication, with ambient request/global state restored. Use the provider's supported native configuration contract or have its maintainer support late public registration; Bridge does not load provider-private files or replay theme bootstrap.

The target must be an actual `WP_Customize_Setting`. Native existence/capability validation is explicitly enabled, `check_capabilities()` preserves theme-support checks, and native sanitizers remain authoritative. Publication goes through public `save_changeset_post(status=publish)` and Core's normal transition callback, preserving synchronous `customize_save`/per-setting/`customize_save_after` hooks. Bridge never calls protected `update()` or private publication helpers. Its fresh changeset contains only the requested value under the current user. Ambient `$_POST['customized']`, request theme/changeset selectors and the global Customizer manager are isolated and restored.

Core's multidimensional setting cache is request-static; its reset method is documented for unit tests rather than production. Bridge therefore permits one newly initialized Customizer context in a PHP request. Use a fresh HTTP/MCP request for another discovery/read/publication; historical replay and inert continuations do not initialize another context. This avoids silently using stale sibling values across fresh managers. A `customizer_request_context_used` result requires a new request, not a new operation ID after an uncertain publication.

Changesets use native JSON storage. Invalid UTF-8, nonfinite values and other non-JSON changeset values receive an explicit representation error. The v2 transport's broader byte support does not change Core's native changeset format. Core settings already owned by the specialized site-settings adapter and Bridge control settings remain protected through the existing target guard. Theme switching uses its existing owning extension contract.

## Outcomes and recovery

Keep the original stable `operation_id` and exact request. Its fixed-purpose journal identity binds scope, blog, network, user and client; the fingerprint binds target, name, stylesheet, value identity, expiration and precondition. The durable states are `prepared`, `dispatch_armed` and `completed`. Historical status and same-ID retries never resend a reserved/armed/completed effect. `not_recorded` is not proof that no invocation is in flight.

`expected_observed_sha256` compares a fresh complete native observation. It is a stale-intent check, not atomic CAS or a lock over provider state. Journal transactions finish before native callbacks. An acknowledged arm is followed by one selected native API effect; no provider callback is run inside the journal transaction.

Results distinguish `not_dispatched`, `native_call_returned` and `unknown`. `native_return` is the actual boolean when available, otherwise null; `native_error` reports a native error object without exposing its message. A void return is not evidence of failure. True/false/returned changeset metadata is not proof of a desired durable business result. After-effect exceptions and missing completion can remain unknown. An independent observed hash is explicitly a later native observation, not transactional verification or rollback.

Required Activity uses redacted unique event metadata and exact readback under unchanged identity. Values, raw provider errors and input documents do not enter general Activity. Protected journals may retain exact target identities for authorized recovery. Audit/output failure after dispatch does not undo or relabel the effect; inspect the original operation status and actual native/provider state before deciding what to do next.

Public provider Abilities and registered REST routes remain the preferred dynamic reuse surfaces. The four entries in `integration-status` are convenience reporting, not a provider execution allowlist. Widget/sidebars, navigation, template/global-style and commerce workflows depend on the actual registered public contracts and native permissions. A private visual-builder or provider business workflow with no supported interface remains an explicit gap. Raw option access does not establish visual-editor parity. WordPress Core upgrade initiation/execution remains outside Bridge.

## Primary native contracts

- [Theme modification APIs](https://developer.wordpress.org/reference/functions/set_theme_mod/)
- [Transient API and native cache behavior](https://developer.wordpress.org/reference/functions/set_transient/)
- [Site-wide transients](https://developer.wordpress.org/reference/functions/set_site_transient/)
- [Customizer setting capabilities and theme support](https://developer.wordpress.org/reference/classes/wp_customize_setting/check_capabilities/)
- [Explicit validation options](https://developer.wordpress.org/reference/classes/wp_customize_manager/validate_setting_values/)
- [Public changeset saving](https://developer.wordpress.org/reference/classes/wp_customize_manager/save_changeset_post/)
- [Core's late registration ordering; private helper is not called by Bridge](https://developer.wordpress.org/reference/functions/_wp_customize_publish_changeset/)
