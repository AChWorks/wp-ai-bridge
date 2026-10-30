# Advanced administration (source pending acceptance and release)

This package adds explicit administration contracts to the existing approved-client and native WordPress capability model. Installation of a published release, release authorization and live activation are separate from the source candidate. Core upgrades remain read-only detection.

## Configure authority

Open **WP AI Bridge → Administration** as the actual administrator; multisite configuration also requires the actual Super Admin and network capability. The page uses native WordPress forms and supports Persian/RTL. New grants start disabled. Existing Settings holds the six separate database grants; Administration holds the eleven additional file/log/scheduler/backup/configuration grants. Ordinary settings saves cannot rewrite these protected grants.

Directory scopes start empty. Add a stable root ID, label and existing absolute native directory, then select its site/network scope, purposes and read/write/destructive/executable access. The administrator sees absolute paths here; tool callers use root IDs and relative names. Saving captures the directory's device/inode. Replacement or reassignment requires renewed approval. Use trusted directory/ancestor writers and actual hosting permissions. Removing a root revokes access without deleting files. Private transfer/control artifacts cannot become ordinary data sources.

A restore root must be writable, explicitly nonserved by any website and outside the active installation. Add exact empty destination schema names separately. Additional provider backup tables outside the installation prefix also require exact approval; dependencies cannot be silently omitted. Work bytes/seconds are resumable processing budgets, not total collection/file/backup limits. Actual PHP, native DB, filesystem, disk and transfer capacities still apply.

The native-account SQL surface additionally requires hosting policy `WP_AI_BRIDGE_ALLOW_NATIVE_ACCOUNT_SQL === true`, all database grants and per-execution acknowledgement. Its scope is the database account's actual privileges, including other accessible schemas and Bridge controls. It is not an installation sandbox and cannot guarantee recovery if its control storage is destroyed. Keep native/provider business workflows distinct from raw SQL.

## Tool contracts

| Ability | Native path and reference |
| --- | --- |
| `database-schema` | Typed DDL, observed schema preconditions, protected dependencies and one-dispatch recovery. [Database](./DATABASE-ADMINISTRATION.md) |
| `database-native-sql` | Separate prepare/execute token, one native prepared dispatch, bounded private result stream. [Database](./DATABASE-ADMINISTRATION.md) |
| `filesystem-read`, `filesystem-write`, `filesystem-transfer` | Approved roots, byte windows, verified private input/output and resumable file/directory work. [Filesystem](./FILESYSTEM-ADMINISTRATION.md) |
| `logs-read` | Sensitive scoped byte windows and backwards tails with observed identity cursors. [Operations](./OPERATIONS-ADMINISTRATION.md) |
| `scheduler-read`, `scheduler-manage` | Exact WP-Cron and available public Action Scheduler lifecycle. Provider outcome is distinct from business effect. [Operations](./OPERATIONS-ADMINISTRATION.md) |
| `native-configuration` | Current-theme mods and native site/network transients with native lifecycle. [Configuration](./NATIVE-CONFIGURATION.md) |
| `customizer-settings` | Registered current-theme settings through a fresh owned public Customizer changeset. [Configuration](./NATIVE-CONFIGURATION.md) |
| `full-backup` | Quiescent full approved capture, portable ingress and verified isolated restore. [Backup/restore](./BACKUP-RESTORE.md) |

All names above use the `wp-ai-bridge/` prefix. Discover exact schemas and availability on the installed site. Provider absence or an unsupported native contract is an explicit result, not permission to guess private APIs.

## Outcomes and recovery

An operation ID and exact request bind a journaled mutation. Durable acknowledgement precedes one Bridge native dispatch; prepared, armed and historical operations never silently dispatch again. Check status after an interrupted or ambiguous response and retain the same identity. A native acknowledgement does not prove external business success, transactional callbacks, atomic DDL, filesystem/database atomicity or universal rollback. Follow returned recovery/partial state.

Private continuations reauthorize the current user/client/site/network and exact purpose. Revocation takes effect on subsequent calls. Sensitive bytes, SQL, credentials and complete file content do not belong in ordinary Activity. Generic failure diagnostics remain enabled; capture must detect any source drift they create.

Backup requires declared all-writer/DDL/file quiescence and full verification. Unsupported required objects fail before completion. Restore preserves original table names/prefix into an approved empty separate schema and nonserved root. Source authentication and recovery stay authoritative. Live replacement, credential changes and activation require their own explicitly authorized workflow.
