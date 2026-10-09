# Large MCP payloads: WordPress content, blocks, and files

MCP clients should be able to administer large WordPress objects without passing entire pages or large binary files as one JSON tool response. The current Gateway transport defaults to a finite 64 KiB decoded response boundary, so simply raising that default is not a reliable solution. WP AI Bridge uses the shared `Bounded_Payload` helper for JSON response budgeting and byte-exact UTF-8 text windows; the bound applies **per response**, not to the size of content WordPress can store.

## Gutenberg: discover, inspect, mutate

1. Call `wp-ai-bridge/blocks-find` with `post_id` and optional exact `block_name` (e.g. `core/button`, not `core/buttons`), `text_contains`, `class_name`, or `attribute_key` plus `attribute_value_contains`. Its flat matches contain canonical numeric `path`, optional `block_hash`, `hash_deferred`, direct-content summary, and the document's `content_hash` and `modified_gmt`. A block containing children defers its expensive recursive fingerprint by default; call `blocks-read(path)` to obtain the exact hash before a guarded write, or explicitly request `include_container_hash=true` when necessary. The finder does not expand or return the complete block tree.
2. If `complete=false`, resume using **`after_path=next_cursor`** and `expected_content_hash`. This cursor initializes traversal at the exact pre-order block path, avoiding any replay of earlier tree nodes. `limit` bounds matches and `scan_limit` bounds newly visited nodes; `walked` reports actual nodes visited by this scan. A batch can contain no matches and still have a continuation. `offset`/`next_offset` remain for compatibility, but offset-only continuations replay skipped nodes and are not recommended for very large pages. WordPress still loads/parses the *entire post content* on each request, so this is a traversal bound, not a constant-memory parsing guarantee. If the content hash changes, restart discovery.
3. Use `blocks-read` with `post_id`, `path`, `expected_content_hash`, `max_depth=0`, and optionally `include_attrs=false` for an attribute-heavy block. The resulting `block_hash` is compatible with the existing `blocks-mutate` stale-block guard. If discovery returned `hash_deferred=true`, read the selected parent first to obtain its exact subtree fingerprint. Increase `max_depth` only when descendants are needed (maximum 8).
4. Use the existing `blocks-mutate` with the returned document identity, block path, and block fingerprint. It returns the complete tree for small results, or `blocks=[]` plus `truncated=true` and the **committed** `content_hash` / `modified_gmt` for large results. The optional `response_mode=summary` requests the compact confirmation explicitly. There is no `response_mode=full` override for an oversized post-write response. Read the edited path again if further block detail is required.

Legacy `blocks-read(post_id)` remains available for small pages. For an oversized result it returns `blocks_response_too_large` with the supported find/targeted-read alternative instead of handing an unbounded result to Gateway. A large block's individual attribute data can also exceed the response budget; request `include_attrs=false` for its bounded fingerprint/summary.

## Raw Gutenberg markup or other large post content

`content-read(action=get,id=...)` returns the usual full `content` for small objects. For large objects it returns metadata, `content_hash`, `content_total_bytes`, `content_next_offset=0`, and `content_complete=false`, without dumping the entire body.

Read the exact text with `content_offset` (byte offset; start at zero), optional `content_max_bytes` (maximum 16384), and `expected_content_hash` for continuation. Every response supplies `content`, `content_next_offset`, `content_total_bytes`, and `content_complete`. Continue until complete. All chunks are UTF-8 safe, join to the exact original bytes, and are tied to the immutable document content hash. The Bridge reduces a requested window when the actual JSON-escaped representation would otherwise overflow.

For list requests with `include_content=true`, a large item may omit its inline body with `content_complete=false`; retrieve that item through `action=get` with windows. If list metadata is too large, Bridge uses a fixed-size compact projection rather than returning unbounded titles/excerpts; reduce `per_page` if a many-item result still exceeds the envelope. A write/restore acknowledgment identifies the **committed** content/state hashes even when full metadata and body cannot be returned.

**Large title/excerpt or other metadata:** When an individual response cannot fit even after shrinking the body, the item returns `projection_truncated=true` and `omitted_fields` with fixed committed identity (`id`, `post_type`, `modified_gmt`, `content_hash`, `state_hash`). Recover each omitted text value with `content-read(action=get,id=...,text_field=title|excerpt|slug|status|template,text_offset=0,text_max_bytes=...,expected_state_hash=...)`, then repeat using `text_offset=text_next_offset` until `text_complete=true`. `text_hash` permits byte-exact reassembly; the state hash guards continuations against an intervening metadata edit. A successful mutation must not be reported as a response-size error merely because its title or excerpt is very large.

## Historical revisions: bounded discovery and exact selected reads

`revisions-read` retains its **array** result and existing small-revision behavior. Request `post_id` and optional `limit` (1-50), `offset` (nonnegative, at most 100000), and `include_content`. A list normally includes the original revision metadata; if an item or aggregate exceeds the encoded-response budget, it returns a compact identity with `projection_truncated=true`, `omitted_fields`, `id`, `parent_id`, `content_hash`, `content_total_bytes`, and hashes/byte counts for title and excerpt. For `include_content=true`, a large body is omitted and clearly marked; the operation never returns an unbounded historical body or silently discards revision IDs. Decrease `limit` if the compact aggregate still exceeds the budget. Offset-based discovery is not a snapshot while new revisions are written; always select a stable revision ID before reconstruction.

Choose an exact revision with `post_id` + `revision_id`. The result is still a **single-element array**, preserving the existing output shape. For full content, set `include_content=true`, `content_offset=0` and optional `content_max_bytes` (4-16384); take `content_hash` and `content_next_offset` from the returned element, and continue with the same parent and revision IDs, `content_offset=content_next_offset` and `expected_content_hash=content_hash` until `content_complete=true`. Offsets count UTF-8 **bytes**, not characters. Concatenate chunks exactly; `content_total_bytes` and SHA-256 `content_hash` verify reconstruction. Incorrect boundaries, missing continuation identity, changes to the selected revision and mismatched parent IDs fail explicitly.

A large revision title or excerpt is not silently truncated: use the same `post_id` + `revision_id`, `text_field=title|excerpt`, `text_offset=0` and optional `text_max_bytes` (4-16384); continue with `text_offset=text_next_offset` and `expected_text_hash=text_hash` until `text_complete=true`. The exact text hashes and lengths allow independent reconstruction. The selected revision is reloaded and its `post_parent` association, Site Read grant and current principal's WordPress `edit_post` capability (as in Core's revision controller) are checked **on every continuation**. `revision-restore` is **not** implied by reading a revision and still requires separate Builder Write/edit authority and its existing stale-write guards.

These semantics are tracked by [Issue #105](https://github.com/AChWorks/wp-ai-bridge/issues/105). They describe the source contract containing this change, not evidence that older deployed plugin versions already support it. Do not increase Gateway response limits or move raw revision bodies into MCP arguments.

## Binary media and executable packages are different

Binary files must not be treated as giant text values merely to pass through MCP JSON. Use WordPress-authorized and streaming transfer paths:
- `media-import-url` already downloads eligible external HTTP(S) sources with bounded streaming into native Media Library storage.
- The existing `media-upload` base64 Ability is for appropriately sized inline MCP payloads; it is **not** a scalable transport for arbitrarily large files.
- Administrator-authorized plugin/theme install from a reviewed public HTTPS package uses `extension-lifecycle` and native Core Upgrader handling. A locally generated private ZIP still needs a safe uploaded-artifact ingress design, governed by Issue #99; this PR does **not** claim that capability exists.

If large private-file upload is implemented, it needs a separate typed ingress/lifecycle using a native HTTP streaming/multipart or approved artifact transport, administrator delegation, bounded storage, per-object hash, expiry/cleanup/recovery, and native WordPress media or extension authority. Do not reassemble huge base64 files in ordinary MCP tool arguments, expose arbitrary filesystem paths, or relax Gateway decoded response limits. The related Gateway diagnostic/limit work remains at [mcp-gateway#122](https://github.com/AChWorks/mcp-gateway/issues/122).

## Reusing this pattern elsewhere

For every potentially large read/result:
- discover/list cheaply and target by stable WordPress identity;
- include a content/version hash, bounded continuation, and an explicit completeness indicator;
- budget **serialized JSON bytes**, including Unicode escapes and protocol headroom;
- never return ambiguous failure after persistence only because full readback is too large;
- reuse WordPress/provider capabilities and preserve per-object authority on each continuation;
- route actual binary blobs through the owning media/extension API, not a general filesystem or PHP/SQL endpoint.

The shared `WP_AI_Bridge\Support\Bounded_Payload` handles the encoded result budget and UTF-8 content windows. Each owning Ability retains its own authorization and query semantics.
