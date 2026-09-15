# Ability reference

Bridge-owned Ability names use the `wp-native-builder/` namespace.

The baseline installation registers the core Bridge surfaces below. Optional Gravity Forms and Code Snippets fallbacks are registered only when their supported provider APIs are available. Astra and other suitable provider Abilities are reused rather than duplicated.

| Ability | Primary access group | Purpose |
| --- | --- | --- |
| `bridge-info` | Site Read | Bridge/dependency state and enabled groups. |
| `site-context` | Site Read | Bounded WordPress/theme/plugin/content-type context. |
| `abilities-read` | Site Read | Paginated public Core/Bridge/provider contract list and exact named schema inspection; never executes target callbacks or grants permission. |
| `integration-status` | Site Read | Optional-provider availability and observed Ability mode. |
| `content-read` | Site Read | Read eligible posts/pages/custom post types. |
| `content-upsert` | Builder Write | Create/update eligible content; Live Content is additionally required for live status. |
| `content-delete` | Users & Destructive | Trash/delete content with WordPress delete authority. |
| `revisions-read` | Site Read | Read revisions for one content object. |
| `revision-restore` | Builder Write | Restore a revision with stale-state checks. |
| `blocks-read` | Site Read | Parse Gutenberg blocks for eligible content. |
| `blocks-mutate` | Builder Write | Targeted Gutenberg append/insert/replace/remove with stale-state protection. |
| `post-meta-read` | Advanced Metadata | Discover physical post-meta keys or read one exact physical key for a WordPress post object the connected user may edit. Values are returned only for an explicitly named key. |
| `post-meta-update` | Advanced Metadata | Create/replace one single-value post-meta key with exact physical-row state identity and stale-write protection. Ambiguous or non-lossless cases fail closed. |
| `post-meta-delete` | Advanced Metadata + Users & Destructive | Delete one single-value post-meta row with exact physical-row state identity and row-scoped stale-write protection. |
| `term-meta-read` | Advanced Metadata | List bounded physical term-meta key/count summaries or read one exact key for an authorized `term_id` + `taxonomy` target. |
| `term-meta-update` | Advanced Metadata | Create/replace one losslessly representable term-meta value using exact physical state and row-level stale-write protection. |
| `term-meta-delete` | Advanced Metadata + Users & Destructive | Delete one exact term-meta row with stale-write protection; ambiguous or lossy values fail closed. |
| `user-meta-read` | Advanced Metadata | List bounded user-meta key/state summaries or read one exact non-sensitive key for a user the connected principal may edit; values require an exact key. |
| `user-meta-update` | Advanced Metadata | Create/replace one authorized single-value user-meta key with exact physical state, native/provider authorization, sanitization, and stale-write protection. |
| `user-meta-delete` | Advanced Metadata + Users & Destructive | Delete one exact authorized user-meta row with stale-write protection; role/capability/session/application-password and credential-like state remains excluded. |
| `comment-meta-read` | Advanced Metadata | List bounded comment-meta key/state summaries or read one exact non-sensitive key for a comment the connected principal may edit; values require an exact key. |
| `comment-meta-update` | Advanced Metadata | Create/replace one authorized single-value comment-meta key with exact physical state, native/provider authorization, sanitization, and stale-write protection. |
| `comment-meta-delete` | Advanced Metadata + Users & Destructive | Delete one exact authorized comment-meta row with stale-write protection; ambiguous, lossy, or sensitive cases fail closed. |
| `media-read` | Site Read | Read Media Library attachments. |
| `media-upload` | Builder Write | Upload bounded file bytes through WordPress Media APIs. |
| `media-import-url` | Remote Media + Builder Write | Stream a safe HTTP(S) resource into the Media Library using current upload/parent authority. |
| `media-update` | Builder Write | Update bounded attachment metadata/parent. |
| `media-delete` | Users & Destructive | Permanently delete an attachment when WordPress permits it. |
| `terms-read` | Site Read | Read terms from eligible taxonomies. |
| `term-upsert` | Builder Write | Create/update a taxonomy term. |
| `terms-assign` | Builder Write | Assign existing terms to content. |
| `term-delete` | Users & Destructive | Delete a taxonomy term. |
| `navigation-read` | Site Read | Inspect classic menus, locations, and block navigation. |
| `classic-navigation-mutate` | Builder Write | Create/update/reorder classic navigation; permanent item removal is destructive. |
| `site-settings-read` | Site Read | Read the bounded site-settings allowlist. |
| `site-settings-update` | Site Configuration | Update bounded site settings. |
| `extensions-read` | Site Read | Read installed plugin/theme metadata. |
| `extension-lifecycle` | Code & Extensions | WordPress.org install/update/activate/deactivate; deletion is destructive. |
| `source-files-read` | Code & Extensions + Source Editing | List or read exact installed plugin/theme editable source targets; source payloads require the elevated boundary. |
| `source-file-preview` | Code & Extensions + Source Editing | Validate and bind an exact candidate to the current target/preimage without writing. |
| `source-file-apply` | Code & Extensions + Source Editing | Apply one preview-bound candidate with exact persistence verification and recovery ownership. |
| `source-file-recover` | Code & Extensions + Source Editing | Restore the Bridge-owned exact preimage only while current bytes still match the owned candidate. |
| `users-read` | Site Read | Read bounded user/role information without credential material. |
| `user-upsert` | Users & Destructive | Create/update a user and assign an editable role. |
| `user-remove` | Users & Destructive | Remove a user with explicit reassignment. |
| `application-passwords-read` | Authentication & Credentials | List bounded Application Password metadata or read one exact UUID through the fixed Core REST controller; stored hashes and reusable credentials are never returned. |
| `application-password-create` | Authentication & Credentials | Create one Core Application Password for an exact authorized user; the generated plaintext credential is returned only in this successful create response. |
| `application-password-update` | Authentication & Credentials | Rename one exact Application Password through Core authority and lifecycle checks. |
| `application-password-delete` | Authentication & Credentials | Revoke one exact Application Password through Core. |
| `application-passwords-delete-all` | Authentication & Credentials | Revoke all Application Passwords for one exact user only with the explicit `revoke_all` confirmation token. |
| `comments-read` | Comments | List/get bounded standard WordPress comment data through the fixed Core comments REST routes; private author transport fields and arbitrary meta are omitted. |
| `comment-reply` | Comments | Create one bounded reply on an exact post/parent through Core comment creation; caller cannot override author/IP/status/meta. |
| `comment-status` | Comments | Apply one closed moderation status transition through Core comment lifecycle and native moderation authority. |
| `comment-delete` | Comments; permanent delete also Users & Destructive | Move one standard comment to Trash; an already-trashed non-force request is idempotent, while permanent deletion requires the additional destructive grant. |
| `workspace-resume` | Site Read | Return compact durable Workspace orientation. |
| `workspace-document` | Site Read / Builder Write | List/read/create/update/archive private Workspace documents. |
| `workspace-task` | Site Read / Builder Write | List/read/create/update/transition/archive private Workspace tasks. |




## Application Password boundary

`Authentication & Credentials` is a separate default-off delegation group. Enabling Users & Destructive, Advanced Metadata, Native Abilities, or another historical group does not enable it implicitly.

The Bridge uses only the fixed WordPress Core Application Password REST route family for an exact user. Core remains authoritative for Application Password availability, multisite membership and the `list_app_passwords`, `read_app_password`, `create_app_password`, `edit_app_password`, `delete_app_password`, and `delete_app_passwords` capability checks.

The generated Application Password is intentionally returned only by `application-password-create`, because Core exposes the plaintext credential only when it is created. The Bridge never persists that plaintext value, never exposes the stored Core hash, and does not include credentials, UUIDs, app IDs, or request payloads in the mutation log. Later list/get/update/revoke responses contain only bounded administrative metadata or deletion confirmation.

Create recovery does not use a Bridge correlation token on `wp_create_application_password` and never treats a public action callback as cleanup provenance. Before any credential-storage snapshot, Bridge runs Core's exact target-specific `create_item_permissions_check()`; this matters because Core's Application Password reader can backfill UUIDs into legacy rows, so an unauthorized request must not trigger that storage normalization. After authorization, Bridge snapshots the exact target and observes the `_application_passwords` pre-write boundary only for the direct Core persistence chain `update_metadata()` → `update_user_meta()` → `WP_Application_Passwords::set_user_application_passwords()` → `create_new_application_password()` → the exact internal `WP_REST_Application_Passwords_Controller::create_item()` request. The nearest metadata write must be that direct Core chain, and the full call stack must contain exactly one matching `WP_REST_Application_Passwords_Controller::create_item()` invocation plus exactly one `rest_do_request()` for the exact request object. A re-entrant/provider `update_user_meta()` call or nested re-dispatch of that same `WP_REST_Request` therefore cannot inherit outer-create provenance. The genuine outer write is accepted only when no earlier metadata filter has already short-circuited it and its proposed state is exactly current state plus one new credential; otherwise that outer persistence attempt is blocked and recovery is required. Bridge retains only that proposed credential's UUID, stored-hash fingerprint and full-item fingerprint in memory. The later exact-request `rest_after_insert_application_password` event remains a success cross-check for the same UUID and Core-formatted one-time password, not cleanup authority.

Cleanup still uses the fixed Core REST DELETE route, but the decisive ownership check is repeated at the actual `_application_passwords` delete persistence boundary. A request-scoped pre-write guard accepts only the direct Core chain ending in `delete_application_password()` → the exact internal `delete_item()` request, requires the currently stored UUID to retain the captured hash/full-item fingerprint, and requires the proposed state to equal current state minus exactly that credential with every unrelated credential unchanged. If REST-lifecycle/provider code changes or replaces the record while retaining the UUID, the Core write is blocked and Bridge returns `application_password_create_recovery_required`; the replacement does not inherit cleanup authority. Successful create still requires final stored state to equal the pre-request baseline plus exactly the captured credential. These checks provide bounded optimistic integrity around the exact Core writes, not serializable isolation: unprovable, nested, re-entrant or externally changed state fails closed rather than widening destructive authority.

This surface does not manage WordPress account passwords, password-reset keys, sessions, cookies, nonces, WP AI Bridge OAuth tokens, or generic authentication metadata. `_application_passwords` remains outside generic user metadata.

## Comments administration boundary

`Comments` is a separate default-off administrator delegation group. Existing Site Read, Builder Write, or other historical grants never enable it on upgrade. Bridge-owned comment operations use only the fixed Core `/wp/v2/comments` collection/item routes through internal `WP_REST_Request` + `rest_do_request()`; callers cannot supply an arbitrary REST route or method, and Core validation plus permission callbacks remain authoritative.

The typed surface is intentionally narrow: bounded list/get, one reply, one moderation/status transition, and one Trash/permanent-delete target. Public list pagination requires an exact post that Core permits the current principal to read, so aggregate totals never span comments on unreadable posts; a positive parent filter is accepted only after that parent itself is readable, approved, standard, and belongs to the same post. Moderation scope retains broader queue pagination for principals with native moderation authority. List/detail results omit author email/IP, user-agent, arbitrary comment meta, avatar payloads, and other hidden transport fields. Comment content is byte-bounded (with an explicit `content_truncated` flag) so a large database row cannot create an unbounded MCP response. Reply input does not expose author identity, IP, status, or metadata overrides. Standard-comment mutations reject non-comment objects such as editor/Core notes.

Trash is permitted under Comments plus exact WordPress authority. Non-force deletion never calls Core DELETE: it uses the status-update lifecycle to move the comment to Trash. Repeating it on an already-trashed comment is idempotent and performs no mutation, so it cannot accidentally become permanent deletion even under a concurrent trash transition. Permanent deletion is reachable only through explicit `force=true` and additionally requires **Users & Destructive** plus WordPress target authority.

## Installed source editing boundary

`Source Editing` is a separate elevated access group and defaults off on both fresh installs and upgrades. Existing `Code & Extensions` consent never enables it implicitly. Source read, preview, apply, and recovery require both groups plus the current WordPress `edit_plugins` or `edit_themes` authority for the selected installed target. WordPress file-modification policy remains authoritative.

Targets are provider-neutral: callers identify one installed plugin main file or theme stylesheet plus one relative editable file. The Bridge starts from WordPress's editable-file inventory, then adds canonical real-path containment. Traversal, symlink escape, arbitrary OS paths, unrelated configuration files, and target switching are rejected. Ordinary discovery returns identities and bounded state only; source bytes are returned only by the elevated exact-read operation.

Preview parses PHP candidates without executing them and returns an exact preimage hash, candidate hash, and target-bound candidate identity. Apply rechecks all of that state immediately before mutation. The fixed-purpose replacement protocol fully stages bytes in a recovery-token-keyed artifact on the exact source filesystem, atomically quarantines the live pathname, verifies the bytes actually moved, and publishes only with hard-link no-replace semantics. If another writer recreates the live pathname, that writer wins and Bridge never overwrites it. Private stage/probe/hold retirement is verified before recovery ownership can be cleared. If an artifact cannot be retired, Bridge keeps recovery ownership and returns recovery-required instead of reporting terminal success. The source filesystem must support hard links plus preservation of the quarantined file's mode/owner/group; unsupported environments fail closed. Persisted bytes are reverified, opcode/theme caches are invalidated as applicable, and FTP/SSH filesystem credentials are never collected.

The concurrency contract follows the installed **pathname/current generation**, not an indefinitely retained descriptor to a superseded inode. Once no-replace publication commits, a file descriptor opened before quarantine still refers to the old generation and cannot overwrite the newly published installed path. Bridge rechecks the quarantined generation before retiring it and preserves any mutation already observable there, but portable PHP exposes no compare-and-unlink primitive and cannot revoke a non-cooperating descriptor opened before the atomic replacement. A process that keeps such a descriptor must reopen the installed pathname before making a later source edit. Bridge does not claim advisory `flock` serializes non-participants.

Active PHP additionally uses WordPress's edited-file scrape protocol against normal WordPress boot without fabricating an administrator session or editor nonce. Missing/invalid scrape sentinels fail closed, including network-active plugin failures that can occur before Core registers the scraper. Runtime failure, explicit recovery, and shutdown recovery all use the same guarded no-overwrite replacement boundary: restoration proceeds only while the candidate still owns the live pathname, and a non-cooperating writer that recreates or changes the path is preserved. Crash reconciliation does not recreate an absent live pathname merely because a known hold exists, because pre-publication absence cannot be distinguished safely from a legitimate post-publication delete/rename. One private bounded recovery record is retained when state or artifact cleanup cannot be verified safely, including across uninstall. Mutation logs contain operation/status metadata only, not source payloads or full diffs.

Explicitly authorized PHP source has normal WordPress-runtime authority. **Source Editing is administrator-level code trust, not a sandbox.**

## Advanced Metadata boundary

`Advanced Metadata` is disabled by default and must be enabled by a WordPress administrator from **WP AI Bridge → Settings**. It is intentionally provider- and object-type-neutral across the bounded post, term, user, and comment metadata surfaces; the Bridge does not maintain provider-specific meta-key allowlists.

Post metadata can target a real WordPress post object when the connected WordPress user may edit that exact object. The post type does not need to be public, REST-exposed, or editor-capable. Bridge-private Workspace types (`wpnb_doc` and `wpnb_task`) remain explicitly excluded. Revision IDs are canonicalized to their parent post before metadata authorization, physical-state inspection, hashing, or mutation so the authorized object is the object whose post metadata is changed.

Protected/private keys (including keys beginning with `_`) can be reached under that administrator-controlled boundary. When Core or a provider explicitly registers a key or installs a metadata authorization filter, that explicit authorization contract remains authoritative. Protected unregistered keys with no explicit authorization contract may use the exact object's native edit authority once Advanced Metadata is enabled instead of WordPress's generic protected-meta default denial. Additional primitive capabilities or `do_not_allow` injected by `map_meta_cap` remain authoritative.

The generic metadata surface deliberately does not expose arbitrary WordPress options, Bridge Workspace internals, role/capability/session/application-password authority state, or credential-like metadata keys. User and comment metadata additionally require exact native `edit_user` / `edit_comment` authority; multisite and Super Admin boundaries remain WordPress-owned. See [User and comment metadata](USER-COMMENT-METADATA.md) for the bounded user/comment contract.

Credential filtering is provider-neutral and normalizes separator, camelCase, compact, singular, and plural forms for password/secret/credential, API/private-key, OAuth/access/refresh, session, identity/ID, JWT, bearer/auth, and related security-token concepts. Unrelated metadata such as a design token is not blocked merely for containing the word `token`.

`state_hash` is computed from physical stored row identity (physical meta ID plus typed raw stored value) for the exact canonical key, not from registered default expansion and not from a virtual metadata-read short circuit. SQL `NULL` and an empty string are distinct states. An absent registered key and a stored row equal to that key's default are also distinct mutation states.

Existing-row update/delete is bound to the one inspected physical row through an internal fixed-schema, byte-exact compare-and-swap. The raw key/value predicate does not use database text-collation equivalence, and SQL `NULL` is matched with an explicit null branch. Identical or collation-equivalent concurrent rows/values therefore cannot be silently updated/deleted as if they were the inspected bytes.

Verification and compensation remain inside the persistence boundary. If interference is detected before verification completes, update restores only its own unchanged written row and delete restores only its own exact deleted row. The compensating change emits the corresponding WordPress metadata lifecycle actions so observers can reconcile to the final physical state. If the exact compensation predicate no longer matches newer bytes, the Bridge returns a compensation failure instead of overwriting them.

Post metadata creation uses normal WordPress `add_post_meta(..., true)` semantics; user/comment metadata creation uses the corresponding Core metadata lifecycle. Core performs the one sanitizer pass and normal add hooks; the Bridge observes the resulting sanitized value without sanitizing it again. A returned meta ID counts as Bridge-owned only while the exact object/key/raw value is unchanged. If Core's non-atomic uniqueness check races, the Bridge removes only its still-unchanged created row. If an added-metadata observer changes that same row, the Bridge preserves the observer state and returns stale rather than cleaning it up.

The compare-and-swap helpers are not database tools exposed to callers: they accept no caller-controlled SQL, table, column, query fragment, or row ID and are hard-bound to their authorized metadata tables/rows. Their fixed prepared raw update/delete statements exist only to provide byte-exact predicates that WordPress's generic text-column helpers cannot express. Generic SQL/database administration remains absent. This is an optimistic row-level integrity protocol, not a transaction/serializable-isolation guarantee; a later write after the verified operation can immediately make a returned state hash stale.

Metadata values containing PHP objects/resources at any depth, or other values that cannot round-trip through the generic JSON contract without structural loss, are not generically replaceable or deletable. Generic metadata deletion additionally requires **Users & Destructive**.

## Generic term metadata

The same default-off **Advanced Metadata** group controls `term-meta-read`, `term-meta-update`, and `term-meta-delete`. Every request requires both an integer `term_id` and its exact `taxonomy` name. Core categories, tags, and registered custom taxonomies (including non-public/non-REST taxonomies) use the same provider-neutral implementation. There is no taxonomy or meta-key allowlist and no WooCommerce/theme-specific adapter.

The term must resolve unambiguously through WordPress, agree with Core's metadata subtype, and pass `edit_term` for that exact target. Legacy shared term IDs are refused even when a taxonomy was supplied, because the physical term-meta owner and Core capability/subtype resolution would otherwise be ambiguous. Registered metadata and all explicit provider authorization filters remain authoritative. For a protected unregistered key only, a temporary exact user/term/key/operation authorization filter replaces Core's default protected-key denial; it does not remove any final `map_meta_cap` requirement or change the original `user_has_cap` context. This filter is removed before the Ability returns.

Read inputs add `page` (1..10000, default 1) and `per_page` (1..100, default 50). Without a key, items contain **only `key` and `count`**. They never contain values, value-derived hashes, or types. Pagination advances through physical key pages before authorization/secret filtering, so a page may be short or empty while `has_more` is true. Treat `has_more` as navigation, not a total-count promise; concurrent metadata changes may move keys between pages.

A named-key read returns the normal `key`, `count`, `state_hash`, `value_types`, and `values` fields inside `items`. `include_values=true` is allowed only for a named key. Read output also identifies `term_id`, `taxonomy`, `page`, `per_page`, and `has_more`. Exact reads inspect at most two physical rows and reject a multi-row key; listing can still report its count. Read that exact key again immediately before a mutation to obtain `expected_state_hash`.

Update accepts `key`, `value_json`, and `expected_state_hash` in addition to the target. It returns the verified metadata item. Delete accepts `key` and `expected_state_hash`, also requires **Users & Destructive**, and returns `term_id`, `key`, `deleted`, and `state_hash`. Deleting an already absent key with the current empty-state hash returns `deleted=false`.

Term values have a 1 MiB byte bound for submitted JSON and physical/sanitized stored data. PHP objects/enums/resources, excessive depth, non-finite numbers, malformed/non-canonical serialization, reference structures lost by JSON, integer overflow, and JSON object shapes that PHP's array contract would silently change are refused. For example, `{}` and an object consisting only of sequential numeric keys cannot be losslessly mapped by this interface. Safe scalars/arrays still use normal WordPress metadata coercion: a newly stored scalar is normally returned as a string, while SQL `NULL` is a distinct physical state. Existing opaque metadata may be inspected for type/state without exposing its value, but cannot be replaced or deleted generically.

Registered defaults and `get_term_metadata` virtual reads do not determine physical state. Creation preserves Core's one-pass sanitizer, completed `add_term_metadata` filter, native-collation unique precheck, and `add_term_meta`/`added_term_meta` lifecycle around a fixed conditional insertion. The Core wrapper cannot express the target condition in its insert, so the term store owns that bounded physical step without rewriting Core queries. A non-null provider short circuit is refused instead of being treated as ownership of its returned row ID. Keys must fit WordPress's native 255-character column; that storage constraint is not a provider/key allowlist.

Every primary and compensating term-meta insert/update/delete conditions the physical write on the original `term_id`, `taxonomy`, and `term_taxonomy_id`, with a real native term and no second taxonomy owner. The helper writes only termmeta; native terms/taxonomy tables are joined for current identity only. Inserts use a locking source read; updates/deletes retain byte-exact key/value and SQL NULL CAS. No application transaction, schema migration or generic query dispatcher is added.

Create contention removes only the current invocation's unchanged inserted row, including when other rows precede it; an observer-modified row is preserved and reported as stale. The insert ID is captured before added observers run, so a nested/provider-returned ID is not adopted. Compensation retains its pre/post-hook target checks and additionally binds that identity in SQL. Only create cleanup may remove its unchanged orphan when both native term and taxonomy rows have disappeared; it may not touch a transferred live target. Compensation failure requires fresh inspection, not an automatic overwrite/retry.

This is an optimistic row-level integrity protocol, not a transaction or serializable isolation guarantee. A later external write may immediately stale a returned hash. Nothing in these abilities grants arbitrary SQL/options/provider-table access, changes the administrator's group defaults, or deploys a release.

## Optional Code Snippets fallback

- `snippets-read`
- `snippet-upsert`
- `snippet-lifecycle`
- `snippet-delete`

## Optional Gravity Forms fallback

- `gravity-forms-read`
- `gravity-form-upsert`
- `gravity-form-status`
- `gravity-form-delete`

Provider-native Abilities discovered in the WordPress registry may also be available to the MCP client. Their schemas and permissions remain owned by the provider.

## URL media import

`wp-native-builder/media-import-url` requires `url` and `filename`. Optional `post_id`, `title`, `caption`, `description`, and `alt_text` use the normal attachment contract. The result is the same attachment summary returned by `media-upload`. No server path, arbitrary headers, cookies, HTTP method, credentials, or package-install action is accepted.

An administrator must explicitly enable **Remote Media** and **Builder Write**. Existing settings without Remote Media remain disabled on upgrade. The current WordPress principal also needs `upload_files` and authority to edit any supplied parent. Permission is checked before HTTP and again before upload/attachment mutation.

Downloads use `wp_http_validate_url()` and `wp_safe_remote_get()` with native initial/redirect URL validation, verified TLS, a 30-second request timeout, at most five redirects, and uncompressed streaming to a WordPress-generated temporary file. The size limit is the current `wp_max_upload_size()` with a one-byte overflow sentinel, not the in-memory Base64 upload's 20 MiB ceiling. WordPress/hosting network and upload policies remain authoritative.

Only complete HTTP 200 responses with nonempty, permitted-size bytes and a consistent Content-Length (when supplied) are accepted. WordPress validates MIME/extension and creates the attachment and image metadata through its normal lifecycle. Executable filenames are rejected; media import never extracts or installs an extension package. Known temporary files are removed and their removal verified on ordinary success/error paths. A returned attachment-insertion error or revoked authority before insertion also removes this invocation's known uploaded file. Source URLs, response bodies, filesystem paths, and exception messages are not logged or returned as diagnostics.

Unexpected lifecycle or cleanup exceptions return `media_import_recovery_required`. Native error data contains only `attachment_id`, `attachment_state` (`not_created`, `unconfirmed`, or `created`), and `known_cleanup_complete`; the last field describes known cleanup candidates, not transaction rollback or removal of unknown provider effects. An insertion callback can commit before returning its ID, so an interrupted insertion is unconfirmed and its destination is retained rather than blindly deleted. A returned attachment ID is preserved in the recovery message because the supported Adapter forwards error messages but not native error data. Inspect the reported attachment or, for an unconfirmed outcome, the Media Library and upload storage before retrying. Translation/audit failures fall back to a fixed diagnostic-free message; a failed audit cannot be represented as a guaranteed persisted log.


The import adds private/reserved-address checks to native URL validation: all returned IPv4 resolver addresses and available DNS A/AAAA records must pass PHP address validation (including the global-range flag where supported). The same check runs through the native Requests redirect hook, scoped to the invocation-owned stream filename and removed in `finally`; unrelated HTTP requests are not governed by this temporary callback. Core URL/TLS checks and finite redirect limits are not relaxed. The native fixture proves that reserved initial URLs fail before HTTP and reserved redirect targets are refused by the actual Core/Requests hook dispatcher without connecting to those targets. Resolver checks are not DNS pinning: transport re-resolution, a configured proxy, trusted PHP hooks and hosting egress policy remain separate environmental boundaries.

Import is not idempotent: importing the same URL again can create another attachment. An interrupted request is not proof of failure; inspect the Media Library before retrying. This is not a transaction or a sandbox for third-party hooks; process termination and provider side effects may require inspection and recovery. WordPress's own network policy and installed filters remain the runtime trust boundary; the Bridge does not override them or claim to replace host egress controls.

## Public Ability contract inspection

Use `wp-native-builder/abilities-read` with `action: "list"` (the default), optional exact `namespace`, case-insensitive `search` over public name/label/description, `page` (default 1) and `per_page` (default 25, maximum 100). Results sort by exact Ability name and report `total` and `total_pages`; all pages of the current registry are reachable. The registry is a live view, not an immutable snapshot across requests.

An exact `action: "get"` plus `name` returns the provider's actual input/output schemas. An absent native schema stays empty; the Bridge does not invent one. Public name, namespace, label, description, category, MCP type and the three standard boolean-or-null annotations are selected explicitly. Arbitrary provider metadata and callbacks are not returned. Explicit MCP opt-out remains authoritative for both list and exact reads, with no name-guessing bypass. Malformed MCP metadata and non-boolean inherited public flags fail closed, matching the pinned official Adapter exposure rule.

Both actions require **Site Read** and native `read` authority, including when called through the official Adapter. They do not call the target's permission or execute callback. `execution_permission: "not_evaluated"` means the target's real input and current authorization must be checked at execution; public discovery or a read-only annotation is never permission. `bridge-info` remains the source for the current Bridge group settings. The current groups do not uniformly govern all reused provider-native operations; see [architecture and coverage](ARCHITECTURE.md#delegation-current-behavior-and-required-evolution).

Responses are bounded to 1 MiB; an oversized or unrepresentable contract returns an explicit error, never a truncated schema. Reduce a list's page size or consult the native provider contract for a larger exact schema. Ordinary lists omit schemas and do not read object values, source files or private site data. Provider-authored public schemas/descriptions are the same public contract data the native inspection interfaces expose; providers must not embed credentials in them.

This capability supplements the existing compact `site-context` reuse hints. It does not implement missing administrative workflows or grant new write/executable authority. Required coverage remains defined by `MASTER-SPEC.md`, with implementation gaps described in `ARCHITECTURE.md`.
