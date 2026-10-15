# External package installation and verified replacement

`External Packages` is a separate administrator-controlled access group for installing plugin or theme ZIP packages from an explicit public HTTPS URL. It is disabled by default on fresh installs and upgrades.

This boundary is deliberately narrower than a generic package downloader. It does not expose a general HTTP client, arbitrary request headers or cookies, URL credentials, local filesystem paths, authenticated package repositories, or automatic activation.

## Authorization

An external package install or explicit existing-extension replacement through `wp-ai-bridge/extension-lifecycle` requires all of the following at the same time:

1. **Code & Extensions** is enabled;
2. **External Packages** is enabled;
3. the connected WordPress user has the native `install_plugins` or `install_themes` capability for the selected kind; **replacement also requires** `update_plugins` or `update_themes` respectively.

The existing WordPress.org slug install path remains available under **Code & Extensions** alone. Enabling External Packages does not grant a WordPress capability and does not enable Source Editing.

The Bridge rechecks the effective delegation/native authority after temporary-file allocation, after the HTTP response is complete, and immediately before handing the package to WordPress Core. A mid-request revocation therefore fails closed before Core installation begins.

## Network boundary

The caller supplies exactly one `package_url` for an install operation. The URL must be public HTTPS and must not contain userinfo credentials. `http:`, `file:`, FTP, local paths, private/link-local/reserved destinations, control characters, and other unsafe destinations are rejected before transport.

WordPress safe-HTTP validation remains active. The Bridge also resolves A/AAAA addresses and refuses non-global/private/reserved results because Core's generic safe-URL checks do not cover every reserved network range. Every redirect in the owned streaming request is rechecked against the same current permission and destination rules.

The download uses bounded WordPress HTTP transport:

- TLS verification remains enabled;
- timeout is 30 seconds;
- at most 5 redirects are permitted;
- the response is streamed directly to an invocation-owned WordPress temporary file;
- decompression is disabled and `Accept-Encoding: identity` is fixed;
- no caller cookies or arbitrary request headers are forwarded;
- the byte limit is the smaller of the native current **WordPress upload limit** and the independently configurable **per-site external package MiB policy** (default **100 MiB**), plus one overflow-sentinel byte for truncation detection. A WordPress administrator can change this policy under **WP AI Bridge → Settings → External HTTPS extension package capacity** without editing plugin PHP or enabling any permissions; the requested policy is strictly positive, finite, machine-integer-safe, and separately bounded by current WordPress/hosting limits.

Resolver validation is a preflight/redirect safety layer, not transport DNS pinning. Hosting/network egress policy remains an additional independent defense.

## Core package installation

After a complete bounded download and one final authorization check, the Bridge passes only the local temporary package path to WordPress Core `Plugin_Upgrader` or `Theme_Upgrader`. Core remains authoritative for ZIP/package structure, filesystem policy, extension validation, destination handling, and installed extension identity.

The Bridge does not automatically activate the installed plugin or theme. Activation stays a separate explicit lifecycle operation with its existing WordPress capability checks.

Installing an executable plugin/theme package is **administrator-level code trust, not a sandbox**. Once activated, package code has the normal authority available to WordPress runtime code. External Packages should therefore be enabled only when an administrator intends to delegate that trust.

## Replacing an existing installed extension from a public ZIP

The ordinary `action=update` with no `package_url` still uses native WordPress update offers. To intentionally select a vendor/GitHub ZIP instead, obtain the installed extension's exact `target` and version with `extensions-read`, verify the trusted public release URL, expected *new* version and SHA-256, and explicitly confirm the operation:

```json
{
  "kind": "plugin",
  "action": "update",
  "target": "wp-ai-bridge/wp-ai-bridge.php",
  "package_url": "https://github.com/AChWorks/wp-ai-bridge/releases/download/v0.6.5/wp-ai-bridge.zip",
  "expected_installed_version": "0.6.4",
  "expected_package_version": "0.6.5",
  "expected_sha256": "<the exact verified 64-character ZIP SHA-256>",
  "confirm_replace": true
}
```

The URL/version above illustrate the **release-verification contract**, not a claim that a candidate ZIP has already been published. The live URL and hash must be verified before the call. For themes use `kind:"theme"` and its exact installed stylesheet as `target`. Version rollback/same-version replacement is refused by default; `allow_non_upgrade:true` is a separately explicit high-trust override, not a default.

Only installed **directory-based plugins with an exact main PHP filename** and installed themes with a matching top-level `style.css` are admitted by the strict ZIP identity check. For unsupported single-file plugin or unusual archive layouts, use the native WordPress administrator workflow; never guess an alternate archive path. The replacement ZIP must contain only the expected canonical directory, have a matching embedded version header and pass finite archive-entry/decompression/symlink/path safety checks.

After bounded HTTPS streaming and SHA-256 validation, the current installed version, delegation, native install/update capabilities and the shared filesystem lock are rechecked. Only then is Core's `Plugin_Upgrader::install(..., ['overwrite_package'=>true])` or equivalent Theme Upgrader called; **ordinary installs never overwrite** an existing extension. The Bridge confirms the exact installed target/version and preserves active/network activation or current-theme identity. A mismatched post-state or ambiguous Core outcome requires an administrator to inspect/recover; never automatically replay it. Replacing the active Bridge itself can terminate the in-flight connection on some hosting stacks, so verify the next authenticated request and have a verified backup/host recovery path.

## Cleanup and recovery

The streamed package file is owned by the current Bridge invocation and is deleted after the operation. Success is returned only after the Bridge verifies that the known temporary package no longer exists.

Once Core installation starts, an error or exception may occur after some filesystem work has already happened. The Bridge does not guess that the extension is absent. Such cases, or any uncertainty while retiring the temporary package, return the fixed `external_package_recovery_required` result so an administrator can inspect installed extensions and temporary storage before retrying.

This bounded recovery result may include only the selected extension kind, a coarse install state, a verified installed target identity when Core already provided one, and whether known temporary cleanup completed. It does not include the source URL, query string, package bytes, temporary path, or downstream diagnostic text.

## Secret and diagnostic handling

External package URLs can contain signed query values, and Core/HTTP errors can contain URLs, filesystem paths, response data, or package-derived text. Those downstream diagnostics are not relayed across the Bridge boundary.

Mutation logs record only the fixed Bridge ability/kind/success or fixed error code. The package URL, query string, temporary path, arbitrary provider/Core error message, and package bytes are excluded from MCP output and mutation-log payloads.

## Non-goals

This feature does not add:

- a generic HTTP proxy;
- custom authorization headers, cookies, bearer tokens, or arbitrary request options;
- local package path installation through MCP;
- a package repository or provider/domain allowlist;
- automatic activation;
- arbitrary filesystem access;
- Source Editing permission.

For the broader authorization model, see [Security](./SECURITY.md). For the Ability inventory, see [Ability reference](./ABILITIES.md).
