# Logs and native scheduler administration

These contracts use the current WordPress principal, the existing grouped delegation settings, and the current blog/network/OAuth client. They do not add a provider registry or queue database. New sensitive grants default off. Source integration does not imply a published release or verified support in an external client.

## Approved log sources

`wp-ai-bridge/logs-read` requires Sensitive Logs Read and current native administration authority. An operator must first configure an existing canonical root with the `logs` purpose and read permission. Root configuration defaults empty. Each root retains its actual site/network scope; a network root requires current Super Admin/network authority. A site administrator can use an approved site root.

The log consumer uses the shared scoped filesystem for every pathname resolution, open and identity check. It cannot follow symlinks or read Bridge private transfer/control artifacts through this surface. A caller selects an approved root ID and a relative pathname, or an array of canonical Base64 filename components. Absolute host paths and transfer IDs are not accepted as sources.

`sources` reports approved roots and whether the actual file-based WordPress/PHP log configuration resolves to an accessible approved source. PHP syslog, stream wrappers, relative PHP destinations with no established base, inaccessible files and unconfigured external paths are reported unavailable. Provider file logs work through their approved root and relative path, without a helper plugin for each provider.

```json
{"action":"tail","root_id":"site-logs","path":"provider.log","lines":30,"max_bytes":8192}
```

`read` accepts a byte `offset` or an opaque continuation `cursor`. A cursor from a tail can read later appended bytes:

```json
{"action":"read","root_id":"site-logs","path":"provider.log","cursor":"<previous cursor>","max_bytes":8192}
```

Responses contain exact `data_base64`, byte count, SHA-256, offset, next offset, observed file size, EOF, inspected byte count and explicit limited/partial-line flags. No UTF-8 replacement or generic content redaction changes the selected bytes. Sensitive log authority intentionally permits the selected log contents, which may contain credentials or personal data. Contents never enter the general Activity log or unrelated errors.

Tail scanning proceeds backwards in bounded chunks. A long unterminated line yields an explicitly partial byte window instead of reading the entire file. Work obeys protected administration step policies, PHP memory and the existing encoded MCP response/window limits. Cursor verification reads at most a small boundary anchor in addition to the selected window.

A cursor binds the root configuration revision, file key, relative source, original blog/network/user/client and prior byte boundary. Every continuation reauthorizes and opens the current path. Renamed/replaced files, shrinkage, changed anchors and root/context changes invalidate continuation. This is observed file/boundary continuity: it cannot prove immutable historical bytes or detect every truncate-and-regrow sequence that reproduces the same checked boundary. Appends during an individual read may invalidate that window. Restart with a fresh tail when rotation is reported.

## Native scheduler discovery

`wp-ai-bridge/scheduler-read` offers:

| Action | Input and behavior |
| --- | --- |
| `providers` | Reports current Core and initialized Action Scheduler feature availability; other public providers remain accessible through native Ability/REST discovery |
| `list` | Select `provider`, optional exact `hook`, `offset`, `limit`; Action Scheduler also supports native `group`/`status` filters |
| `get` | Select `provider` and exact `target_id` |
| `recurrences` | Reports current Core registered recurrence names/intervals or Action Scheduler schedule kinds |

Inventory returns metadata and an opaque target `snapshot`; it does not return callback argument values. Pagination is explicitly live offset pagination, not a stable queue snapshot. A changing native queue can move entries between pages. Exact mutation checks use a freshly observed target fingerprint.

Scheduler access requires Scheduler Manage, Site Configuration and native administration authority. Shipped `cron-events-read/manage` authority remains unchanged. Additive current-blog Action Scheduler operations follow its supported site-administrator path. Supported built-in stores must retain their declared current-site selection. A custom/global store with no established site partition requires network authority in multisite; a matching arbitrary table prefix is not tenant proof. All operations retain original blog/network/user/client binding.

Core itself materializes its Cron option; Bridge does not accumulate another complete event collection for a short page. Action Scheduler queries bounded IDs through its public API and fetches selected actions. The public Action Scheduler logger has no bounded pagination contract, so this adapter does not call its unbounded `get_logs()` and then pretend the read was bounded. Approved file logs and separately delegated database inspection remain distinct available paths.

## One-dispatch lifecycle

`wp-ai-bridge/scheduler-manage` supports `schedule`, `cancel`, `run` and `status`. Every effect requires `confirm:true` and a stable 8–128-character `operation_id`. Retain that ID through an uncertain response; changing the ID is not recovery.

```json
{"action":"schedule","provider":"action_scheduler","hook":"installed_registered_hook","args":["example"],"group":"provider-group","schedule_type":"single","timestamp":1893456000,"operation_id":"client-operation-001","confirm":true}
```

Only registered native hooks and inert scalar/array arguments are accepted. Installed callbacks remain trusted effectful code, not a PHP sandbox. The adapter never accepts a caller-selected PHP callable, unserializes a submitted object, edits queue tables directly or invents a private provider business workflow. An exact `source_id` plus its `expected_snapshot` may reuse server-owned hook/arguments/group instead of submitting those values. This reuses arguments; callers still choose the new schedule kind/time.

Core uses `wp_schedule_single_event()`, `wp_schedule_event()`, current `wp_get_schedules()` and exact `wp_unschedule_event()`. Core interval scheduling selects a registered recurrence name, rather than inventing a new cadence from arbitrary seconds.

Action Scheduler uses its public async/single/interval/cron scheduling functions, exact store cancellation and native `process_action()`. Its real native priority range is 0–255. The active installed provider must already be initialized; Bridge does not install, include or initialize another provider copy.

Cancellation requires Users & Destructive. Immediate Action Scheduler processing additionally requires Scheduler Run and Code & Extensions. Select an existing action using `target_id` and `expected_snapshot`. Claimed/nonpending actions are rejected. Native store/runner permissions and lifecycle remain authoritative.

Ordinary Core has no public exact-event processing API. Core selected execution therefore continues through the existing installed-provider `Cron_Targeted_Runner` contract and `wp_ai_bridge_cron_profiles`. The new adapter reports that gap explicitly. It does not trigger the entire due queue or directly execute a generic hook; doing so could also initiate prohibited Core upgrade workflows. Direct Core automatic-upgrade scheduling/processing is excluded.

Each new mutation uses the shared fixed `scheduler` journal: reserve, acknowledge an armed fence, call one native mutating API, then record observations. No transaction spans provider callbacks. Repeated prepared/armed/completed identities cannot automatically rearm. Pending/unknown outcomes must be inspected with:

```json
{"action":"status","provider":"action_scheduler","operation_id":"client-operation-001"}
```

Responses distinguish native acknowledgement, observed provider status, provider failure and unknown outcome. `native_api_threw` describes an escaped native API exception; Action Scheduler may catch a callback exception internally. Its void return alone cannot establish success. A completed native provider status is reported as `provider_status_observed`, while business effects remain explicitly `unverified`. A failed callback may already have changed external/business state. Hook dispatch state is restored even when the provider catches an exception internally.

Bridge fencing prevents this Bridge operation from replaying. It does not promise exactly-once callbacks against concurrent native workers, native target CAS, rollback, transactional recurrence or durable provider business receipts. Native scheduling/cancellation races and failed observation/persistence remain explicit limitations. Ordinary rescheduling can be performed as separately identified native scheduling/cancellation steps; no hidden multi-call atomic replacement is claimed.

Existing permanent targeted-Core fences remain retained without a fixed lifetime-count ceiling. Native storage/resource failures still refuse admission. General Activity records contain fixed operation/status metadata, never callback arguments, log contents or raw provider failures.

## Compatibility evidence

The focused native fixture is `tests/integration/issue199-native-schedulers-smoke.php`. It exercises standalone Core/no-provider behavior and real initialized Action Scheduler scheduling, processing, cancellation, recurrence and partial callback failure. Actual 3.9.3 and 4.2.0 runs belong to the coordinated integration evidence; a pure test double is not proof of those versions.

Public upstream contracts:

- [WordPress scheduling API](https://developer.wordpress.org/reference/functions/wp_schedule_event/)
- [WordPress exact unscheduling API](https://developer.wordpress.org/reference/functions/wp_unschedule_event/)
- [Action Scheduler scheduling/query APIs](https://actionscheduler.org/api/)
- [Official native administrative processing/cancellation](https://github.com/woocommerce/action-scheduler/blob/4.2.0/classes/ActionScheduler_ListTable.php)
- [Official processing and recurrence lifecycle](https://github.com/woocommerce/action-scheduler/blob/4.2.0/classes/abstracts/ActionScheduler_Abstract_QueueRunner.php)
