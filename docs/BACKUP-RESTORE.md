# Full approved backup and isolated restore

This contract describes source functionality. [Issue #199](https://github.com/AChWorks/wp-ai-bridge/issues/199) tracks exact-candidate native validation, independent review, integration and release evidence. It does not imply that an installed client supports archive ingress or egress.

`wp-ai-bridge/full-backup` captures the approved installation database and files into a private portable archive, then restores that archive into an explicitly approved **empty separate database schema** and **nonserved filesystem root**. Original table names, table prefix, stored values and logical file roots are preserved. Live activation and in-place disaster recovery are separate operations.

## Authority and configuration

Full Backup and Full Restore are independent default-off protected administration grants. Backup additionally requires Database Inspection and Sensitive Database Read. Restore additionally requires Database Write. Every action rechecks the actual administrator; multisite requires actual Super Admin and network authority.

The administrator configures canonical filesystem roots, approved restore schemas and exact additional installation tables. These lists default empty. API callers select configured root/schema identities, never arbitrary paths, database credentials or another account. Restoring executable/configuration files requires the destination root's explicit executable trust.

Every installation-prefixed table is required. Explicit `backup_tables` add approved nonprefix provider tables. Prefixes do not prove ownership of unrelated provider tables. Required native installation locations—Core, content, plugin/MU-plugin/theme directories, uploads and the actual configuration file—must be covered by approved source roots. A missing required root is an explicit gap.

The existing WordPress mysqlnd connection and account perform native qualified target operations. The feature does not read archived credentials, create another connection, invoke a shell/CLI or provision databases. The approved empty target schema must already exist and be accessible to that account.

## Consistency prerequisite

The response reports `consistency=declared_quiescent_verified_capture`, `quiescence_assumption=true` and `activation_pending=true`.

The owner must stop all selected database writers, DDL, file writers, cron, queues, deployments, direct database clients and write-producing bootstrap/authentication/provider hooks throughout capture and every continuation. Metadata privileges and ownership must remain stable. WordPress maintenance mode alone does not establish this condition.

The producer captures data with private checkpoints, then reads the complete source again to compare table/schema identities, row multisets, file bytes and file identities/modes. It also checks the source Activity value at final seal admission so required failure diagnostics cannot silently change the captured source after its earlier table scan.

There is no database/filesystem atomic snapshot and no transaction held across requests. Equal observations cannot prove that no concurrent change occurred and was reversed. Quiescence is an explicit prerequisite, not an inferred guarantee.

Successful capture does not write a source DB journal or Activity entry during capture. Returned capture failures remain incomplete/restart-required when their private checkpoint can be updated. Generic functional failure diagnostics remain available; if they write source Activity, source verification must reject drift. Lost responses can recover acknowledged private frames, but a failed capture is not promised to resume successfully.

## Supported objects and exact representation

Native dictionary/FK inspection requires the existing account’s metadata visibility, including `PROCESS` for MySQL `INNODB_FOREIGN` and MariaDB dictionary views. Bridge neither grants privileges nor opens an administrative connection. The disposable CI account receives that metadata privilege while third-schema data and DDL remain denied. See the [MySQL prerequisite](https://dev.mysql.com/doc/refman/8.4/en/information-schema-innodb-foreign-table.html).

The first native blueprint supports persistent InnoDB base tables, ordinary ordered columns, supported scalar/text/blob/JSON types, primary/ordinary/unique/fulltext column indexes and in-scope foreign keys. Keyless tables and duplicate rows retain their exact multiplicities. Row hashes are projected into a separate derived-table namespace before grouping and pagination; source columns named `digest`, `multiplicity` or the generated namespace names remain ordinary data. Digest groups are read in resource-derived request-local metadata pages. Established single integer primary keys provide validated private cell locators, with the complete row hash still checked in every lookup; other key shapes retain generic hash matching. Restore also uses a verified staged integer-key value for indexed before/after counts while retaining the complete row hash and existing transaction/receipt ordering. Locators never enter v1 archive records. Pages are discarded on fresh requests and before independent source/destination verification. Large unindexed tables still incur native hash-search work.

Definitions are validated before they are compiled into qualified target operations. Archived SQL is untrusted and is never executed blindly. Values remain opaque; serialized PHP and provider data are never decoded, rewritten or searched/replaced. SQL-visible TIMESTAMP values are captured/restored in UTC. Explicit zero auto-increment values are retained. Raw archived DDL hashes remain exact integrity inputs. Destination verification accepts only the native addition/removal of a redundant matching `CHARACTER SET` clause at a parsed column boundary with the same explicit collation; other definitions, defaults, quoted literals, comments, options and foreign keys remain strict. The independent oracle compares complete physical schema metadata. Original native session settings are restored. The fixed backup SQL-mode setter accepts only a complete single native 3135/HY000 strict-mode deprecation warning with its exact known message, followed by exact mode-set readback. Generic SQL warnings and time-zone setters remain strict. Failed session restoration quarantines the retained connection; fixtures do not alter WordPress's original zero-date modes.

Current unsupported shapes include views, triggers, routines, events, CHECK/generated columns, partitioned/versioned tables, nontransactional engines, external dependencies, external storage clauses, expression indexes and unproven CREATE options. Native metadata visibility is mandatory. Presence of a required unsupported object prevents full completion and returns its exact object identity where available; nothing is silently omitted. This is not a universal MySQL/MariaDB dump importer.

Native statement/metadata/packet limits remain real. A row or definition which cannot fit the actual native or current finite processing capacity fails explicitly.

## Public workflow

| Action | Required fields beyond `action` |
|---|---|
| `inspect` | `source_roots` |
| `export_begin` | Same roots, `expected_plan_hash`, `quiescence_confirmed=true`, `bootstrap_writers_confirmed=true` |
| `export_step`, `export_status` | `session_id` |
| `read` | `purpose=backup_archive\|backup_input`, `session_id`, byte `offset`, bounded `max_bytes` |
| `input_open` | Finite `max_total_bytes` reservation |
| `input_append` | `session_id`, `offset`, canonical `content_base64` containing exactly one complete frame, its `sha256` |
| `input_seal` | `session_id`, exact final byte `offset`, frame-chain `sha256` |
| `input_status` | `session_id` |
| `restore_prepare` | `archive_id`, `archive_sha256`, `archive_purpose=backup_archive\|backup_input`, configured `target_schema`, `target_root` |
| `restore_step` | `session_id`; first scans the entire archive until `ready` |
| `restore_begin` | Ready `session_id`, exact returned `expected_plan_hash`, `quiescence_confirmed=true` |
| `restore_step`, `restore_status` | `session_id`; continue effects and verification until `verified` |
| `cancel` | `purpose=backup_archive\|backup_input\|backup_state`, `session_id` |

Imported column names, native type and character set must agree with the accepted quoted definition; the restore codec is derived from that definition. Descriptive archive metadata cannot select a different conversion. Imported cell bytes are hashed with native PHP SHA-256 before their declared digests contribute to row identities.

Preflight alone is not a restore. No target schema/file effects occur before the complete immutable package passes validation and the ready destination plan is acknowledged. Destination schema/root emptiness and identity are rechecked immediately before starting.

Source logical root `installation`, for example, restores under `<target root>/roots/installation/`. Multiple approved roots retain separate logical namespaces. File bytes, ordinary permission bits, empty files and empty directories are preserved. Destination files and directories are compared again, including unexpected extra entries.

## Portable frame integrity

Format: `wpai-installation-backup-v1`.

Each frame consists of `WPAI1:`, eight lowercase hexadecimal header-length digits, `:`, eight payload-length digits, newline, bounded JSON header and raw payload. Headers include ordered `seq`, `type`, `payload_bytes` and payload SHA-256. Exact identifiers/components use canonical Base64; raw data is not Base64 inside the archive.

Integrity is explicitly `sha256-frame-chain-v1`, not a whole-file SHA-256:

```text
initial = SHA256("wpai-administration-stream-v1")
next = SHA256(previous + ":" + frame_byte_length + ":" + SHA256(exact_frame_bytes))
```

The final footer binds the preceding chain, complete database/file digests and verified-source result. No complete receipt exists until the footer and private seal are acknowledged. Import must preserve frame boundaries; arbitrary network read windows can be buffered into complete frames by the client.

Hashes establish integrity, not sender authenticity. Import and restoration still require current owner authority and explicit exact-plan approval. Private bytes contain credentials, users, OAuth state and configuration; do not publish them or expose filesystem paths.

Internal complete-schema processing uses the larger of configured administration `step_bytes`, incoming request bytes and public response bytes, capped at one eighth of the currently remaining effective PHP/WordPress memory allowance. It does not inherit a single message or archive-frame quantum. When PHP and WordPress ceilings are unlimited/unknown, the operator's already-finite processing allowance remains; it is not silently replaced by a transport-sized cap or treated as unlimited RAM. Initial source planning rejects insufficient schema-processing resources before capture reservation, with a distinct capacity domain and measured processing/headroom feedback. A complete table definition must fit the conservative native metadata estimate (eight times its catalog aggregates plus the existing 8,192-byte guard), while its actual serialized record must fit the independently configured archive frame block; the existing minimum frame quantum is 16 KiB. These are processing/block requirements, not an aggregate database or value-size ceiling. The plan's separate private-checkpoint metadata reservation is reported distinctly. Actual request, response, archive-frame and native packet limits remain independent; increasing a transport setting is not the prescribed fix for unavailable PHP processing headroom.

All persistent bytes/checkpoints use the existing private-transfer backend. The feature does not maintain another manager database or private filestore. Finite policies derive from transfer reservation/request/chunk/lease settings, PHP processing resources, actual disk, native packets and configured step work. A cell spanning frames is verified when its final frame arrives: the validator remembers only its archive offset/sequence, then replays that complete cell from the same pinned immutable stream through native SHA-256 and restores the stream position. No HashContext is persisted and no second payload copy/backend is created. Memory remains bounded by frame resources. One complete cell read/hash is the minimum synchronous work; it must fit the configured step and actual PHP request execution budget. Insufficient time or an unavailable seekable archive returns explicit cell-verification resource/compatibility feedback while preflight remains before target effects. Retain the archive and restart preflight with adequate resources; increasing policy may require a fresh job because configuration identities are bound.

Each bounded capture or admitted restore step applies and restores the native session once, retaining per-frame authority checks and durable continuation checkpoints. Preflight does not apply archived session settings. Terminal archive sealing or verified-restore checkpoints are published only after original-session restoration succeeds; cleanup failure quarantines the handle and cannot create a new complete receipt. Session admission never persists across requests. After hook-capable private/authority boundaries, dependent native work samples the selected database, exact SQL-mode name set, time zone, autocommit, foreign-key and unique-check flags; drift aborts before that work. This read-only sample does not prove absence of an explicit empty transaction or SQL table locks. Initial step admission and native DDL/DML retain their existing positive clean-session fences; the observer does not commit or roll back a callback-owned transaction. Database/check-flag drift discards the unsafe shared handle so outer WordPress callbacks cannot reuse it; no compensating USE, flag reset or reconnect is attempted. Mode/time-zone-only drift uses the existing proven original-setting cleanup. Already-known Activity drift is checked before each admitted capture loop, including footer recovery, and the final full source comparison remains mandatory.

Individual native queries and final private-inode verification remain subject to real host execution limits; cooperative step budgets are not database query cancellation.

## Restore ordering and recovery

Restore creates validated base schemas, streams complete rows, adds deferred foreign keys with native checks enabled, then verifies the complete destination. Re-enabling checks alone would not validate previously loaded rows; it is not used as proof.

Each InnoDB row and its compact source-side receipt commit together through the same connection. This also protects duplicate/keyless row occurrences after a lost reply. DDL has a durable acknowledged dispatch fence before one native send. It is never advertised as transactional rollback. Native OK with warnings remains acknowledged; failed reporting cannot convert applied DDL into rolled-back work.

Unknown DDL, noncommitted filesystem publication or unavailable receipts stop restoration. No unknown effect is automatically resent. Destination state remains isolated for reconciliation or explicit owner-authorized disposal/restart. Canceling private input does not undo restored effects.

The running installation's users, options, credentials and Bridge controls remain active throughout. Their restored copies are inert data. Copied tokens/grants never become recovery authority. Exact verification leaves the restored root unchanged; only a separate derived copy may receive target-only configuration for a disposable bootstrap check. Publishing or activating restored credentials/code requires a separate explicit workflow.

## Evidence

Pure regressions are in `tests/issue199-backup.php`, `tests/issue199-backup-preflight-integrity.php`, `tests/issue199-backup-capacity.php`, `tests/issue199-backup-session.php`, `tests/issue199-backup-plan.php`, `tests/issue199-backup-step.php`, `tests/issue199-backup-pages.php` and `tests/issue199-backup-entries.php`. The real scoped-iterator regression checks explicit consumption acknowledgements, finite-page cursor progress, canonical export order and resumed restore directory verification. Fully re-framed malicious cell-SHA/type/charset packages are checked before ready and before target effects; valid multi-megabyte/chunked, NULL, empty and binary inputs exercise JSON-only checkpoint continuation. The native family fixture also runs those preflight cases against an unchanged actual empty target, and covers native MySQL JSON separately from MariaDB's explicit JSON/CHECK gap. The native family flow is `tests/integration/issue199-backup-smoke.php`, using the separately authored complete-schema/row-multiset/file oracle. Its keyless collision table includes source columns `digest`, `multiplicity`, `wpai_row_sha256` and `wpai_backup_rows`, two distinct rows sharing those values and an exact duplicate; the full roundtrip must preserve all three occurrences. It captures actual installation files, transports the complete package through bounded ingress, verifies revocation, performs an actual same-account isolated restore, compares all bytes/schema/modes/empty directories and rejects source Activity drift. Native execution and final acceptance must be recorded against the exact candidate and both database engines; source presence alone is not proof.
