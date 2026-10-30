# Scoped filesystem administration

The current source exposes administrator-configured filesystem roots through filesystem-read, filesystem-write and filesystem-transfer. Configuration starts empty. Public inputs select a configured root ID and a UTF8 relative path or canonical Base64 filename components. Absolute paths, stream wrappers, dot/dot-dot, NUL and separator components are rejected. Base64 components preserve non-UTF8 Unix filenames.

Filesystem Read, Write, Destructive and Executable are independent default-off delegations. Each request also requires current WordPress administrator authority, configured root purpose/flags and the PHP process's actual host permission. Shared installation/executable roots require actual Super Admin/network authority on multisite. A network-approved site-bound uploads data root preserves the site's native administrator scope. Executable/configuration changes respect WordPress file-modification and code capabilities. This is explicit code trust, not a PHP sandbox.

Source Editing retains its existing installed-extension inventory, grants, validation and recovery. Generic filesystem administration does not inherit an old Source Editing, Media, Builder Write or Site Read opt-in. Operations neither execute shell/PHP nor collect FTP/SSH credentials.

## Read and exchange

filesystem-read supports roots, stat, list, read and hash. Stat supplies an identity and opaque stable file key. Byte windows are lossless Base64 with exact offsets, SHA256 per window and explicit EOF. Hashing streams the complete file without materializing it in PHP memory. An identity binds root configuration revision, original site/network/principal/client, purpose, relative filename bytes and observed filesystem metadata.

filesystem-transfer supports inert input begin, exact-offset append, verify, status and release. Export uses export_begin, repeated export_step, then read. Changing the selected live source during capture rejects the continuation. The existing private transfer backend supplies the only resumable byte store. Its finite administrator-configurable transfer and host disk/memory policies apply. Staging never grants publication or execution.

A verified filesystem input is published directly from its private inode when private storage and destination share a filesystem. Publication releases its byte reservation while keeping transfer metadata and the separate durable effect receipt. Explicit release removes remaining disposable transfer state; it cannot undo a filesystem effect or erase the operation fence.

Configuration, Customizer, backup and native SQL result consumers use fixed server-selected private purposes. They do not acquire public generic transfer purposes. New leases retain original blog/network/user/client and complete server binding, including configuration revision or theme when applicable. A failed creation may be erased after revocation only by the exact object holding its original creation proof. This grants no data access.

## Writes, copies and recovery

filesystem-write supports mkdir, write, delete, copy, move/rename, status and exact-preimage recover. Replacement/deletion requires current identity and complete SHA256 preimage. Creation requires absence; mkdir also binds current parent identity. The configured root itself cannot be removed or replaced.

Regular-file publication uses private same-device staging/quarantine, verification of bytes actually moved, and hard-link no-replace publication. A competing current pathname wins and is preserved. The host must support private same-device staging and the no-replace primitive; otherwise publication fails before quarantining the live path. Mode/owner/group preservation may also be denied by the host.

Directory copy/move is a bounded continuation: exclusive destination creation, verified file copy and, for moves, conditional source deletion. Empty directories and ordinary permission bits are retained. Directory modes are finalized after children. This sequence is not an atomic directory rename or merge. Existing destinations are rejected. Later failure may leave a verified destination and preserved/partial source.

Each visible effect has a stable operation ID, complete request fingerprint and durable administration-journal fence before dispatch. Retries return historical outcomes and never silently rearm. A missing/uncertain receipt does not prove no effect occurred. Interruption after source deletion is recovered through the same child receipt before advancing the directory cursor.

Recovery restores a private preimage only while the exact owned candidate remains the current pathname. Missing paths are not automatically recreated: another writer may have legitimately deleted or renamed them. Unverified generations and cleanup failures retain evidence. There is no filesystem/database transaction, automatic undo of executed code, or guarantee of preserving future writes to a superseded inode.

## Privacy and consistency boundaries

The root/path layer excludes Bridge private transfer, staging, artifact, source-CAS, operation/control paths, including beneath broad roots. Symlinks, unexpected hard-linked files and special files are not followed. Backup source and restore verification fail explicitly when a required directory contains excluded entries. They never silently omit them. Private absolute paths and internal stream descriptors do not cross the public filesystem Ability boundary.

Git and Subversion metadata are legitimate deployment data: configured sensitive filesystem reads and full-backup inventory include .git and .svn without expanding a root or silently omitting entries. Writes, deletion and moves touching those components require the root executable flag, explicit filesystem code delegation where applicable, and current native code capabilities. VCS configuration and hooks can influence later code execution, so ordinary data-write trust does not authorize those changes. Actual Bridge-private/control artifacts remain excluded even when nested beneath VCS data.

Portable PHP provides pathname/opened-inode checks and advisory locks. It lacks ordinary portable fd-relative openat/O_NOFOLLOW confinement against hostile same-UID ancestor swaps. Administrators must configure roots with trusted ancestor writers; mode bits alone do not establish that trust. HTTP aliases, shared-storage routing and nonparticipating writers remain real environmental boundaries.

Directory cursors use native enumeration order and require unchanged source directory identity. Reopening an ordinal cursor scans earlier names; the configured time policy may reject a scan unable to reach its cursor within current resources. There is no permanent file-count ceiling. Complete SHA256 verification is a streaming synchronous scan, subject to actual host request time. Increasing Bridge policy cannot override PHP, filesystem, hosting, transport or client limits.

## Internal dynamic streams

The same backend provides finite dynamic stream reservations. append_verified_frame atomically checkpoints received offset and frame chain; exact last-frame replay does not duplicate bytes. seal_administration_stream releases unused reservation and declares integrity=sha256-frame-chain-v1. Its sha256 field is a frame-chain digest, not whole-content SHA256:

- Initial chain: SHA256 of wpai-administration-stream-v1.
- Next chain: SHA256 of previous_chain + ":" + frame_length + ":" + frame_sha256.

Consumers verify frame payload checksums and final chain. Raw fixed-width file inputs also supply original chunk width; publication recomputes that chain and content digest. Ordinary SHA256 mode remains separate.

Private application checkpoints use compare-and-swap revisions in the same journal. Their admission budget is configurable. Accepted checkpoints remain readable after lowering message policy, subject to current PHP memory resources. The one-request producer writer reserves capacity before dispatch and offers callback-free append_native while an unbuffered native result is active. Consumers close their native statement before returning from the callback.
