# Large MCP payloads: WordPress content, blocks, and files

MCP clients should be able to administer large WordPress objects without passing entire pages or large binary files as one JSON tool response. The current Gateway transport defaults to a finite 64 KiB decoded response boundary, so simply raising that default is not a reliable solution. WP AI Bridge uses the shared `Bounded_Payload` helper for JSON response budgeting and byte-exact UTF-8 text windows; the bound applies **per response**, not to the size of content WordPress can store.

## Gutenberg: discover, inspect, mutate

1. Call `wp-ai-bridge/blocks-find` with `post_id` and optional `block_name`, `text_contains`, `class_name`, or `attribute_key` plus `attribute_value_contains`. Its flat matches contain canonical numeric `path`, `block_hash`, direct-content summary, and the document's `content_hash` and `modified_gmt`. The finder does not expand or return the complete block tree.
2. If `complete=false`, call again with `offset=next_offset` and `expected_content_hash` from the previous response; otherwise use the returned match. `limit` bounds matches and `scan_limit` bounds how many tree nodes each request searches. A batch can legitimately contain no matches and still have a continuation. If the document changed, restart at offset zero.
3. Use `blocks-read` with `post_id`, `path`, `expected_content_hash`, `max_depth=0`, and optionally `include_attrs=false` for an attribute-heavy block. The matching `block_hash` is compatible with the existing `blocks-mutate` stale-block guard. Increase `max_depth` only when descendants are needed (maximum 8).
4. Use the existing `blocks-mutate` with the returned document identity, block path, and block fingerprint. It returns the complete tree for small results, or `blocks=[]` plus `truncated=true` and the **committed** `content_hash` / `modified_gmt` for large results. The optional `response_mode=summary` requests the compact confirmation explicitly. Read the edited path again if further block detail is required.

Legacy `blocks-read(post_id)` remains available for small pages. For an oversized result it returns `blocks_response_too_large` with the supported find/targeted-read alternative instead of handing an unbounded result to Gateway. A large block's individual attribute data can also exceed the response budget; request `include_attrs=false` for its bounded fingerprint/summary.

## Raw Gutenberg markup or other large post content

`content-read(action=get,id=...)` returns the usual full `content` for small objects. For large objects it returns metadata, `content_hash`, `content_total_bytes`, `content_next_offset=0`, and `content_complete=false`, without dumping the entire body.

Read the exact text with `content_offset` (byte offset; start at zero), optional `content_max_bytes` (maximum 16384), and `expected_content_hash` for continuation. Every response supplies `content`, `content_next_offset`, `content_total_bytes`, and `content_complete`. Continue until complete. All chunks are UTF-8 safe, join to the exact original bytes, and are tied to the immutable document content hash. The Bridge reduces a requested window when the actual JSON-escaped representation would otherwise overflow.

For list requests with `include_content=true`, a large item may omit its inline body with `content_complete=false`; retrieve that item through `action=get` with windows. If a list result itself is too large, reduce `per_page`. Write/restore confirmations can contain a partial body, always accompanied by the completeness and offset fields. No successful write should be represented as an output-size failure.

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
