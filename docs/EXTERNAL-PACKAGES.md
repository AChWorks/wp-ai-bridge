# External package installation

`External Packages` is a separate administrator-controlled access group for installing plugin or theme ZIP packages from an explicit public HTTPS URL. It is disabled by default on fresh installs and upgrades.

This boundary is deliberately narrower than a generic package downloader. It does not expose a general HTTP client, arbitrary request headers or cookies, URL credentials, local filesystem paths, authenticated package repositories, or automatic activation.

## Authorization

An external package install through `wp-ai-bridge/extension-lifecycle` requires all of the following at the same time:

1. **Code & Extensions** is enabled;
2. **External Packages** is enabled;
3. the connected WordPress user has the native `install_plugins` or `install_themes` capability for the selected kind.

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
- the byte limit is the smaller of the current WordPress upload limit and **100 MiB**, plus one overflow-sentinel byte for truncation detection.

Resolver validation is a preflight/redirect safety layer, not transport DNS pinning. Hosting/network egress policy remains an additional independent defense.

## Core package installation

After a complete bounded download and one final authorization check, the Bridge passes only the local temporary package path to WordPress Core `Plugin_Upgrader` or `Theme_Upgrader`. Core remains authoritative for ZIP/package structure, filesystem policy, extension validation, destination handling, and installed extension identity.

The Bridge does not automatically activate the installed plugin or theme. Activation stays a separate explicit lifecycle operation with its existing WordPress capability checks.

Installing an executable plugin/theme package is **administrator-level code trust, not a sandbox**. Once activated, package code has the normal authority available to WordPress runtime code. External Packages should therefore be enabled only when an administrator intends to delegate that trust.

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
