# Installation database administration

## Authorization and scope

Two separate administrator settings govern the read-only database contracts:

- **Database Inspection** enables table, column and index discovery. No record values, column defaults or comments are returned.
- **Sensitive Database Read** additionally enables exact raw values, which may include credentials, OAuth state and private plugin data under any column name. It requires Database Inspection as well.

Both default off, including upgrades from existing all-enabled older settings. Settings are rechecked in permission and execution callbacks. The connected principal needs native manage_options. Multisite additionally requires actual Super Admin and manage_network_options; a site administrator cannot infer network/table ownership from wp_ or its own site prefix. Approved OAuth identity, scope, client revision and revocation remain enforced by the existing transport; a locally invoked native Ability still uses the actual current WordPress principal.

Only the existing WordPress connection/current database and exact installation-base-prefix tables are available. This includes dynamically discovered plugin tables and network tables for an authorized Super Admin. No database selector, alternate credentials, view execution, SQL text or database mutation is accepted. Unprefixed plugin tables, tenant-local multisite delegation, advanced SQL, writes and full backup/restore require additional accepted contracts; these reads do not advertise those capabilities. Correct connection/prefix ownership is a deployment prerequisite, not a cryptographic tenant guarantee. An independently authorized database actor performing concurrent DDL is outside a SELECT-only preflight isolation guarantee.

## Metadata: wp-ai-bridge/database-inspect

Input action tables supports limit and after_table (the previous returned table name). It returns installation base-table metadata, approximate engine row counts and keyset continuation without scanning record values. Input action schema requires table, supports section columns or indexes, limit and offset, and returns next_offset/has_more.

Column metadata preserves native data_type and bounded sql_type definitions, with sql_type_bytes/sql_type_truncated. Large ENUM/SET definitions are reported as truncated metadata, not falsely complete schemas. Use appropriate native database tooling when a complete oversized type definition is required.

## Values: wp-ai-bridge/database-read

The rows action requires table and a nonempty columns array. Select only relevant fields. Optional filters maps exact column names to canonical base64 strings, or null for SQL NULL; matching is byte-exact in addition to native indexed equality. There are no caller-selected query expressions or functions.

Each returned item has a key map containing complete base64-encoded primary-key values and a cells map for the requested columns. Primary-key bytes are also bounded in SQL: an oversized TEXT/BLOB prefix-index key is omitted from key, key_complete becomes false, and key_hashes carries its full SHA-256. Pass both key and key_hashes to a later cell request; a hash match must still identify exactly one current record. Hash-key lookup can scan/hash the table and does not claim indexed-lookup performance. Each cell reports sql_type, is_null, encoding=base64, data, bytes, total_bytes, offset, sha256 and next_offset. Decode base64 to recover exact database bytes. BIGINT/DECIMAL values are bytes, never PHP/JavaScript numbers; NULL and empty strings remain distinct. Serialized PHP values stay opaque and are never unserialized.

For one integer primary key, continue with returned after_id, preserving the table, projection and filters and leaving offset unset. This uses exact DECIMAL comparison beyond PHP/JavaScript integer ranges. Composite/text keys and keyless tables use next_offset with paging=offset_live; concurrent inserts/deletes may skip or repeat records. All pages are live reads, not a snapshot, export or transaction.

The cell action requires table, column and at least one nonempty key or key_hashes map. key uses canonical base64/NULL; key_hashes maps exact columns to 64-character lowercase SHA-256 values. All predicates are combined. The key must match exactly one current record. This may be a full returned primary key or a unique predicate for a keyless table. Duplicate/absent matches fail; a keyless table without any practical unique predicate cannot provide stable cell continuation. Optional length selects a bounded window, offset defaults to zero, and expected_sha256 is mandatory when offset is positive. Reuse the previous cell hash for every continuation and verify the reconstructed bytes against it. A changed value returns database_cell_stale; restart instead of mixing versions. An offset beyond the value is an error.

## Capacity, evidence and diagnostics

Page/projection/window limits and aggregate projected-cell budgets derive from the existing administrator response policy; the server bounds bytes in SQL before PHP receives them. The wrapper checks the projected MCP envelope and explains how to request fewer fields/rows, a shorter window or a different response budget. A bounded response is not a promise that an intermediary accepts the same capacity.

The database hashes the full selected value to detect changes even though PHP receives only its window. This costs database CPU for large values and every continuation revalidates it; it is not a frozen export lease. Integer keysets avoid offset scans; other pagination is explicitly weaker. This contract does not claim snapshot consistency, queue-business lifecycle execution, hooks/cache invalidation, or visual-editor parity.

Sensitive reads use the existing bounded Bridge activity store with timestamp, principal, client fingerprint, action/table fingerprint, unique event reference and outcome; no row values, keys, SQL or credentials are logged. A failure to persist audit metadata prevents returning raw values. This is the existing finite operational history, not an immutable archival audit system. Database query failures preserve actionable privilege/availability guidance while excluding data-bearing SQL/error text. Database/server diagnostics remain the owning source for deeper failures.

Generic registered REST remains a separate high-trust surface. Its excluded Bridge/control-plane routes cannot invoke these database tools; existing provider permission callbacks and protected-lifecycle requirements remain unchanged. Database grants do not enable generic REST and generic REST consent does not directly enable these tools.

## Validation

The deterministic database regression, disposable single-site private-plugin queue fixture and multisite fixture test grants, revocation, schema/rows, exact binary/numeric/NULL/serialized data, integer keysets, filtering, cell continuation/staleness, scope denial and payload/audit behavior. The normal supported CI matrix supplies compatibility evidence. Source/tests, CI, releases, installed-site MCP exposure and effective permissions are separate facts; a source change is not a deployed capability.
