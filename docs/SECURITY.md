# Security model

WP Native Builder Bridge is designed as a bounded WordPress capability layer, not a general remote shell.

## Layered authorization

Bridge-owned operations must satisfy every applicable layer:

1. valid OAuth-authenticated WordPress identity for direct ChatGPT connections;
2. the relevant Bridge access group;
3. the required WordPress capability or object-level/meta authorization rule;
4. any provider-native permission check used by an integration;
5. operation-specific live-state, destructive, and stale-state rules.

OAuth never enables a Bridge access group and never grants a WordPress capability. Native provider operations retain their own permission callbacks; current Bridge groups do not uniformly govern provider-native execution. See [current delegation coverage](ARCHITECTURE.md#delegation-current-behavior-and-required-evolution); disabling a Bridge write group is not universal provider-write revocation.

## Access groups

- **Site Read** — read-only inspection surfaces.
- **Builder Write** — bounded content/site-building mutations.
- **Remote Media** - default-off outbound media import; Builder Write and native upload/parent authority remain required.
- **Live Content** — publishing and other live-state transitions.
- **Site Configuration** — bounded global configuration.
- **Advanced Metadata** — protected/private post and term metadata for exact WordPress objects the connected user may edit; disabled by default and intentionally separate from ordinary Site Read/Builder Write access.
- **Code & Extensions** — managed snippets and extension lifecycle.
- **Source Editing** — separately enabled installed plugin/theme source read/preview/apply/recovery; executable PHP is administrator-level code trust, not a sandbox.
- **Users & Destructive** — user administration and destructive operations. Generic post-meta and term-meta deletion require this group in addition to Advanced Metadata.

Only Site Read is enabled by default.

## Advanced post metadata boundary

Advanced Metadata exists for legitimate theme/plugin/builder state that is stored in `post_meta` instead of `post_content`. It is provider- and post-type-neutral and does not require a new hardcoded allowlist entry for every theme, plugin, CPT, or meta key.

The boundary is deliberately layered:

- the Advanced Metadata group must be enabled by a WordPress administrator;
- the target must be a real WordPress post object and the connected WordPress user must be able to edit that exact object;
- revision IDs are canonicalized to the parent before metadata authorization, physical-state inspection, hashing, or mutation, matching the target used by WordPress post-meta mutation wrappers;
- the target post type does not need to be public, REST-exposed, or editor-capable;
- Bridge-private Workspace post types (`wpnb_doc` and `wpnb_task`) are explicitly excluded;
- normal post-meta capabilities remain authoritative for public keys and for keys where Core/provider code registered metadata or installed an explicit authorization filter;
- protected/private unregistered keys may use the target post's `edit_post` authority once Advanced Metadata is enabled, because WordPress otherwise denies such keys generically merely for being protected;
- additional capability requirements and `do_not_allow` returned by the final `map_meta_cap` pipeline remain authoritative;
- credential-like key names are excluded through a provider-neutral normalization rule that covers common separator, camelCase, compact, singular, and plural password/secret/credential, API/private-key, OAuth/access/refresh, session, identity/ID, JWT, bearer/auth, and related security-token forms without banning unrelated uses of the generic word `token`;
- list operations return keys/state summaries only; exact values require an explicitly named key;
- mutation identity comes from the physical `wp_postmeta` rows, including physical row IDs and raw stored values, rather than registered defaults or `get_post_metadata` virtual-read short circuits;
- SQL `NULL` is preserved as a distinct physical raw state and cannot alias the empty string in `state_hash`;
- updates/deletes require an exact `state_hash` and refuse ambiguous multi-row keys;
- existing-row update/delete uses a narrowly bounded internal compare-and-swap against the inspected `meta_id + post_id + meta_key + raw meta_value`; key/value predicates are byte-exact rather than text-collation equality, and SQL `NULL` uses an explicit `IS NULL` branch;
- after an exact mutation, verification remains inside the persistence boundary. If interference is detected, update rolls back only its own unchanged written row and delete restores only its own exact deleted row before returning a stale conflict;
- compensation emits the corresponding WordPress metadata lifecycle actions for the restored final physical state. Integrity compensation is not vetoable by metadata short-circuit filters after the Bridge has already committed the first exact mutation;
- absent-row creation still uses WordPress `add_post_meta(..., true)` for normal Core unslashing, one-pass sanitization, and hooks. The Bridge observes the already-sanitized value without invoking the sanitizer a second time, then treats the returned meta ID as its own only while that exact post/key/raw identity is unchanged;
- because Core uniqueness is a SELECT-then-INSERT check rather than a database unique constraint, a create race can still yield multiple rows. In that case the Bridge byte-exactly removes only its own still-unchanged row and emits the corresponding delete lifecycle. If an observer changed the returned row first, the Bridge leaves it untouched and reports stale instead of claiming ownership;
- metadata values containing PHP objects/resources at any depth, or values that do not survive the generic JSON contract structurally unchanged, are not generically replaceable or deletable;
- delete additionally requires Users & Destructive access.

The exact-row compare-and-swap helper is an internal fixed-purpose implementation detail, not an API surface. It accepts no SQL, table name, column name, meta ID, query fragment, or database selector from the MCP client; it is hard-bound to the already-authorized `wp_postmeta` row. The only raw SQL is the fixed prepared byte-exact update/delete predicate required to avoid database-collation equivalence. Static safety checks confine these two logical exact-row operations to six fixed prepared SQL branches in the post-meta store and keep direct database use forbidden elsewhere in production source except the separately confined term-meta store described below. The Bridge still exposes no arbitrary SQL or general database administration.

This mechanism provides a bounded row-level stale-write/compensation protocol, not a database transaction or serializable isolation guarantee. A write that occurs after the Bridge's verified linearization point is simply a newer state and can make the returned `state_hash` stale immediately, as with any optimistic-concurrency token.

## Advanced term metadata boundary

Issue #36 extends the same default-off group to term metadata, without changing the post-meta persistence or authorization implementation. The secret-key normalization is shared verbatim with post metadata rather than duplicated.

Term identity is **both `term_id` and `taxonomy`**: the taxonomy must be registered, explicit and canonical term resolution must agree, Core's term metadata subtype must agree, and shared legacy term IDs fail closed. WordPress `edit_term` and operation-specific `add_term_meta`, `edit_term_meta`, or `delete_term_meta` mapping determine authority; no global `manage_categories` or `manage_options` substitutes for it. Protected unregistered metadata may override only Core's default protected-key denial under explicit administrator opt-in. The narrowly scoped temporary authorization callback preserves subsequent provider/mapped denials, additional primitive requirements, `do_not_allow`, and original `user_has_cap` object/key context. Registered/global/subtype metadata authorization and explicit authorization filters, including priority zero, are never replaced. Delete additionally requires Users & Destructive.

The term store is separate because term identity, subtype resolution, shared-term behavior and lifecycle differ from posts. It writes only `$wpdb->termmeta`. The fixed inventory contains two bounded reads, one Core-equivalent uniqueness count, four update and two delete CAS branches, and two conditional insert branches shared by creation/restoration. Every write joins the original native `terms`/`term_taxonomy` identity; those tables are never mutated by the store. Complete literal prepared templates and their exact table/identity/value arguments are independently checked by the token-based confinement script. No client-selected table, SQL/query fragment, physical row ID or schema selector is accepted. Broad lists never load values. Named reads remain limited to two rows and 1 MiB per value; SQL errors are not absence. All prior post-meta restrictions remain unchanged.

Term state identity includes physical row ID and typed raw bytes, preserving SQL `NULL` versus empty string and distinguishing absent rows from registered defaults. Stored serialization is preflighted as a complete scalar/array-only stream before native decoding, preventing object/enum autoloading. Decoding additionally disables classes and bounds depth; objects/resources, malformed/non-canonical serialization and structures that cannot round-trip through JSON are not generically exposed as values, replaced, or deleted. Sanitized output is checked before serialization/persistence. Core sanitization runs once, not twice.

Existing-row update/delete checks authority after the pre-mutation lifecycle hook and binds both byte-exact metadata state and the original real term/taxonomy/term-taxonomy identity in the physical DML statement. Primary writes do not rely on a post-insert recheck to avoid wrong-target changes. Conditional inserts include an explicit locking source read, rather than an unprotected snapshot-only identity lookup. No explicit transaction, session-isolation change, query rewrite, or global database interception is used.

Creation reproduces the native one-pass sanitization, completed provider short-circuit pipeline, normal-collation unique precheck, and add/added lifecycle around the conditional insert, since `add_term_meta()` cannot put that identity condition in its own insert. Non-null provider short circuits remain unsupported, not evidence of row ownership. The statement's new ID is captured before added observers execute. The native 255-character key storage bound is checked before direct persistence; the 1 MiB safe-value bound remains unchanged. Core's SELECT-then-INSERT uniqueness behavior remains optimistic rather than a new database uniqueness guarantee.

Create contention cleans only the unchanged Bridge-owned row. Update compensation restores only bytes the invocation still owns; delete compensation inserts only its original positive physical ID, refusing collisions rather than overwriting another owner. All three compensation operations preserve original target checks before/after their pre-hooks and bind the same identity again inside their physical SQL. Only create cleanup has an internal alternative to delete its unchanged orphan when both native term and taxonomy records are absent. A transferred or shared live target never qualifies. Actual compensation emits the matching lifecycle events and cache invalidation. It is not an unrestricted rollback of concurrent writes or provider effects.

The protocol provides bounded optimistic integrity, not serializable isolation. Trusted installed WordPress code can independently change state; newer writes after verification can stale a response immediately. A compensation failure is a diagnostic boundary requiring fresh inspection, not permission to overwrite newer state. The mutation log contains only ability/target type/target ID/status/error code, never term-meta keys, values, full payloads or credentials.

## Stale-write protection

Overwrite-sensitive content, post-metadata, term-metadata, and Workspace operations return change identities. A later update must present the expected current identity. If the object changed after inspection, the Bridge rejects the write and requires the caller to refresh.

Post metadata uses deterministic physical-row identity plus byte-exact row compare-and-swap. Verification is performed within the bounded persistence operation. Concurrent duplicate/add/update/delete interference detected before that verification completes is reported as stale; where the Bridge already changed one row, it performs row-scoped compensation and corresponding lifecycle actions rather than overwriting/deleting concurrent state. If the exact compensation predicate no longer matches, the operation returns a dedicated compensation failure instead of overwriting newer bytes or claiming success.

Workspace documents/tasks use monotonic `version` plus deterministic `state_hash` with an atomic compare-and-swap against the previous state payload.

## Content, Gutenberg, and metadata isolation

Generic content/Gutenberg operations remain limited to their existing eligible editor-capable content predicate. Advanced Metadata is intentionally broader because the site administrator explicitly opts into it, but Bridge-private Workspace storage remains excluded from the generic metadata surface and is reachable only through the dedicated Workspace contract.

## Upload boundary

Media upload accepts bytes and a filename, writes only to a WordPress-generated temporary path, and hands the result to WordPress media/sideload handling. The caller cannot specify a server filesystem path.

The payload cap is the smaller of the WordPress upload limit and 20 MiB.

## Remote media boundary

URL import is separately default-off, including upgrades where Builder Write was already enabled. It uses native safe HTTP(S) URL/redirect validation and TLS verification without relaxing WordPress security filters. Requests stream to a WordPress-owned staging file with a finite timeout, redirect budget, and current upload-limit-plus-one byte cap. WordPress then owns MIME/sideload/attachment handling. Neither arbitrary request headers/cookies nor caller-chosen server paths are accepted.

Authority is rechecked before network activity and before file/attachment mutation, including after temporary-file allocation. Permission-hook exceptions fail closed. Ordinary failures remove known staging files; a returned insertion error or revoked authority before insertion removes only the known newly uploaded destination. The importer rejects executable filenames and never performs package extraction/installation. Expected errors and unexpected import/cleanup exceptions are redacted rather than forwarding provider/HTTP messages containing signed URLs, response bodies or filesystem paths. Mutation logs contain only operation/attachment identity, success and a bounded error code.

Unexpected exceptions, failed cleanup, or failed final audit reporting produce `media_import_recovery_required`, not success. Known cleanup is verified without retrying failed deletion hooks. A native sideload or insert can mutate state before throwing and returning its path/ID; unknown or potentially committed state is retained for inspection, not deleted based on a missing return value. Already-returned attachment IDs remain available in recovery guidance. A fixed English fallback prevents a failing translation or audit hook from disclosing another exception; it does not guarantee audit persistence. This boundary covers the import callbacks and known cleanup, not arbitrary PHP output or hooks executed by Core/Adapter outside the import callback.

The import adds private/reserved-address checks to native URL validation: all returned IPv4 resolver addresses and available DNS A/AAAA records must pass PHP address validation (including the global-range flag where supported). The same check runs through the native Requests redirect hook, scoped to the invocation-owned stream filename and removed in `finally`; unrelated HTTP requests are not governed by this temporary callback. Core URL/TLS checks and finite redirect limits are not relaxed. The native fixture proves that reserved initial URLs fail before HTTP and reserved redirect targets are refused by the actual Core/Requests hook dispatcher without connecting to those targets. Resolver checks are not DNS pinning: transport re-resolution, a configured proxy, trusted PHP hooks and hosting egress policy remain separate environmental boundaries.


This contract does not make imports transactional or retries idempotent. A process crash or arbitrary provider hook can have effects beyond temporary-file cleanup. Core networking and installed filters remain authoritative; native safe HTTP is not a promise of isolation from malicious PHP or a replacement for deployment egress restrictions.

## Extension boundary

Plugin/theme installation accepts WordPress.org slugs resolved through WordPress Core APIs. Arbitrary package URLs, uploaded plugin ZIPs, PHP files, and caller-selected server paths are not accepted.

Deletion requires destructive access in addition to the action-specific WordPress capability. Active extensions are protected where deletion would be unsafe.


## Installed source editing

Source Editing is a separate default-off trust boundary. Enabling `Code & Extensions` does not grant it on either a fresh install or upgrade. Every operation rechecks both Bridge groups, WordPress file-modification policy, and the matching native `edit_plugins`/`edit_themes` capability. `DISALLOW_FILE_EDIT`, `DISALLOW_FILE_MODS`, multisite/Super Admin rules, and target writeability therefore remain authoritative denials rather than settings the Bridge bypasses.

The caller never supplies an OS path. Installed plugin/theme identity and one relative file are resolved from Core editable inventories, then canonicalized. The final real path must stay inside the exact installed extension root; traversal, symlink escape, target switching, and arbitrary server/configuration paths fail closed. Production source mutation is a fixed-purpose same-filesystem replacement protocol, not a generic filesystem API: the complete replacement is staged under an unguessable recovery-token-keyed internal artifact name on the exact source filesystem, the live pathname is quarantined, the bytes actually moved are compared with the expected hash, and publication uses hard-link no-replace semantics. `WP_Filesystem_Direct` remains bounded to source reads. The Bridge does not expose filesystem credentials or caller-selected paths.

PHP candidates pass `TOKEN_PARSE` before any mutation. Preview binds the exact target, preimage SHA-256, candidate SHA-256, and candidate identity. Apply re-resolves the target after a cooperative lock, rechecks the preimage, persists one bounded private recovery record, then enters the guarded replacement boundary. If another writer changes the path before quarantine, the quarantined bytes fail the expected-hash check; if another writer recreates the path after quarantine, no-replace publication fails and those newer bytes win. The staged file must be able to preserve the quarantined file's mode/owner/group, and the exact source directory/filesystem must support the required hard-link primitive and fixed-purpose internal replacement artifacts; otherwise apply fails closed. Active PHP then performs Core-compatible scrape validation through ordinary WordPress boot without synthetic admin authentication. Network-active failures that occur before scraper registration are treated as validation failures rather than success.

Recovery uses the same guarded no-overwrite replacement primitive and can restore only when the bytes moved from the live pathname still equal the exact candidate owned by the pending record. A non-cooperating writer that wins the current pathname before or during recovery is preserved rather than overwritten. Shutdown recovery routes through the same guarded recovery path. Crash reconciliation never treats an absent live pathname as Bridge-owned merely because a known hold remains: that absence may be a legitimate post-publication delete/rename, so automatic recreation fails closed. Private stage/probe/hold removal is verified; cleanup failure retains the recovery record instead of producing terminal success. Pending recovery ownership is preserved across uninstall for later reinstall/reconciliation. Failed or unverifiable compensation retains the private record and reports recovery-required/uncertain state; it never claims a database/filesystem transaction or promises to undo arbitrary PHP side effects that already executed. Source/preimage payloads and full diffs are excluded from the ordinary mutation log.

The integrity guarantee is generation-aware: after the atomic no-replace publication boundary, an already-open descriptor to the previous inode is no longer a descriptor to the installed live pathname. Writes through that stale descriptor cannot alter the published source generation. Bridge detects and preserves changes to the quarantined generation that are observable before retirement, but it does not claim to preserve arbitrary future writes to a superseded inode after the current-path replacement has committed; portable PHP provides advisory `flock`, not revocation of non-participating open descriptors or an atomic content-compare-and-unlink operation. External writers must reopen the pathname after an atomic replacement before making a new installed-source edit.

This boundary grants code trust when explicitly enabled: PHP written into an installed extension runs with the authority available to that WordPress runtime. It is not isolation, evaluation sandboxing, OS-root access, or a generic PHP execution endpoint.

## Managed code snippets

Code Snippets integration is an intentional bounded provider integration. Submitted code is passed through the installed Code Snippets lifecycle API and its capability checks. The Bridge does not evaluate the code directly or provide a generic PHP execution endpoint.

## Explicitly absent generic surfaces

The Bridge does not expose:

- arbitrary SQL or database administration;
- shell/process execution;
- WP-CLI execution;
- unrestricted filesystem access;
- arbitrary `wp_options` access;
- arbitrary user-meta administration;
- credential, session, OAuth-secret, private-key, security-token, or Application Password retrieval;
- arbitrary plugin ZIP/PHP upload;
- direct provider-table administration.

## OAuth storage

Authorization codes, access tokens, and refresh tokens are opaque. Secret-bearing values are not intentionally stored in plaintext. Refresh tokens rotate, revocation is supported, and the direct MCP resource is bound to the OAuth flow.

## Activity logging

The mutation log is bounded and metadata-oriented. It should not be treated as a content archive and must not be used to log credentials, metadata keys, metadata values, or full submitted payloads.
