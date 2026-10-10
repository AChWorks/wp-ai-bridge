# Troubleshooting

## The custom App cannot scan tools

Check **WP AI Bridge → Settings** first.

Confirm:

- WordPress 6.9+ is running;
- MCP Adapter is active;
- the reported MCP URL uses HTTPS;
- the WordPress REST API is reachable from the Internet;
- a security/CDN plugin is not blocking `/.well-known/` or the Bridge REST route.

The Dashboard and Settings screens report only local dependency state and the **configured MCP URL scheme**. A configured HTTPS URL does not prove Internet reachability, remote TLS/proxy behavior, OAuth metadata retrieval, or successful authenticated connection. **External client connection: Not verified here** is intentional until the client confirms its authenticated route.

Where available, use **Tools → Site Health** as an authorized administrator for native HTTPS, REST, loopback and Authorization-header checks. These are not equivalent to a verified external client. If remote discovery fails, inspect CDN/proxy/firewall routing and the OAuth well-known metadata paths, and whether the client uses direct Bridge or separate Gateway. Rescan tools after changing access. OAuth success is distinct from the exact WordPress principal capability and Bridge group.

Opening the MCP endpoint without authentication should return an authentication challenge, not a normal HTML page.

## OAuth opens WordPress but authorization does not finish

Start a fresh connection attempt rather than reusing an old consent page. OAuth consent requests are intentionally short-lived and one-time.

Also check browser/network security layers for blocked redirects to ChatGPT and ensure the site/canonical WordPress URLs are correctly configured for HTTPS.

## The App connects but a write is denied

A successful OAuth connection is not write authorization. Check both:

1. the relevant Bridge access group under **WP AI Bridge → Settings**;
2. the connected WordPress user's capability for the target object/action.

For example, publishing normally needs **Builder Write + Live Content** plus the applicable WordPress publish capability.

### Comment administration is denied

Comment inspection/replies/moderation require the separate default-off **Comments** group plus the current WordPress principal's native Core permission for the exact route/target. Permanent comment deletion additionally requires **Users & Destructive**. A non-force delete of an already-trashed comment intentionally remains a no-op/trashed result rather than escalating to permanent deletion.

## A registered provider Ability is visible but execution is denied

Discovery does not authorize execution. For a non-Bridge Core/provider Ability invoked through WP AI Bridge, check both:

1. **Native Abilities** is enabled under **WP AI Bridge → Settings**;
2. the connected WordPress user satisfies the target's own WordPress/provider permission callback.

Native Abilities is broad registered-operation trust, not a sandbox or safety classifier. Enabling it cannot override a provider/Core denial, and the default disabled result is expected even for a WordPress administrator until that group is explicitly enabled. Bridge-owned Abilities continue to use their own more specific access groups.

This group governs execution through the exact WP AI Bridge MCP routes. It does not change ordinary direct `WP_Ability::execute()`, the MCP Adapter default server, or WP-CLI behavior.

## Large WordPress content returns `response_too_large`

Check the **installed Bridge and Gateway versions first**; repository `main` may be ahead of both. For posts/revisions use [byte-window content reads](./LARGE-PAYLOADS.md); for Gutenberg use `blocks-find` and targeted `blocks-read(path)`. On Bridge builds that support it, use `site-context(section=current_user)` or bounded `section`/paging instead of an entire plugin/provider inventory. A Gateway transport-size error is not proof that an operation is missing, and increasing a universal limit is not a substitute for source-owned paging. The exact error layer on Alumni remains under [#110](https://github.com/AChWorks/wp-ai-bridge/issues/110); Gateway-only parity discrepancies are tracked in [mcp-gateway#130](https://github.com/AChWorks/mcp-gateway/issues/130).

## `stale` or conflict errors

The object changed after it was inspected. Read it again, use the new `modified_gmt`/`state_hash` or Workspace `version`/`state_hash`, then decide whether the intended update is still correct.

Do not retry a stale update with the old identity.

## Astra is active but Astra tools are missing

Enable Astra's **Abilities** option. The Bridge reuses Astra's native Ability surface; a separate Astra MCP server is not required for this setup.

Reconnect/rescan the ChatGPT App if its tool catalogue was built before Astra Abilities were enabled.

## Code Snippets is active but integration is unavailable

The Bridge requires a compatible Code Snippets programmatic lifecycle in the current request. Current release testing covers both 3.9.x and 3.10.x provider model generations.

If status remains unavailable, verify the installed version, plugin activation, and whether another plugin is altering Code Snippets loading/capabilities. Rescan the App after changing provider state.

## Media upload fails

Check:

- **Builder Write** is enabled;
- the connected WordPress user has `upload_files`;
- WordPress accepts the MIME type;
- file size does not exceed either the WordPress upload limit or the Bridge 20 MiB cap.

The Bridge does not accept server filesystem paths.

## Plugin/theme installation fails

`wp-ai-bridge/extension-lifecycle` installs a plugin/theme by **WordPress.org slug** or, with separate **External Packages** consent, from an explicitly supplied safe **public HTTPS ZIP URL** (`package_url`). Both paths require **Code & Extensions**, the action-specific native WordPress install/activate/update capability, and filesystem/deployment policy that permits that operation; installation does not automatically activate the extension. A private/local ZIP is **not yet transferable through a general AI-client binary-upload path**. Do not substitute a manual WordPress dashboard ZIP staging workflow for the requested client-to-WordPress transfer; see [#134](https://github.com/AChWorks/wp-ai-bridge/issues/134). Do not disguise an inability to upload a private ZIP as a disabled existing public-HTTPS install.

For a public HTTPS ZIP install, confirm **External Packages** and the source's HTTPS accessibility/safe redirect rules in addition to the native install capability; see [External packages](./EXTERNAL-PACKAGES.md). If the installed Bridge build includes the newer read-only `extension-authorization` Ability, query the exact `kind`/`action` (and `install_source: public_https` where applicable) before executing: it reports required Bridge/native grants **without proving target/filesystem/upgrader success**. Older deployed versions, including v0.4.2, may not have this preflight; check the actual installed version, not only repository `main`. If WordPress requires interactive filesystem credentials, the Bridge reports that manual filesystem setup is required rather than collecting those credentials.


## Installed plugin/theme source editing is denied or requires recovery

Source editing requires **Code & Extensions** and the separate **Source Editing** group. The connected WordPress user must also currently have `edit_plugins` or `edit_themes`, and WordPress file-modification policy must allow the operation. `DISALLOW_FILE_EDIT`, `DISALLOW_FILE_MODS`, multisite/Super Admin rules, or a non-writable target can therefore deny it even when both Bridge groups are enabled.

The Bridge does not collect FTP/SSH filesystem credentials. If the exact installed target is not directly writable by the WordPress PHP process, fix deployment ownership/permissions through the host's normal administration path and retry. Guarded source replacement also needs hard-link no-replace publication on the exact source filesystem and preservation of the existing file's mode/owner/group. `source_atomic_replace_unavailable` means that stronger no-overwrite guarantee cannot be established in the current hosting/filesystem environment, so Bridge fails closed instead of falling back to an unsafe in-place write. Do not broaden permissions to arbitrary server paths.

If apply returns a stale/conflict result, read and preview the exact file again before deciding whether to retry. `source_concurrent_write_detected` specifically means another writer won the live pathname at the guarded replacement boundary; its bytes were preserved. If apply returns `recovery_required`, a recovery-artifact error, or an uncertain-partial-state result, do not start another source write: inspect the exact target and use `source-file-recover` only with the pending candidate hash. Recovery and shutdown compensation use the same no-overwrite path boundary and refuse to replace bytes owned by a newer writer. If the live pathname is absent while a private hold remains, Bridge intentionally does not recreate it automatically because it cannot prove whether the absence is an interrupted pre-publication quarantine or a legitimate post-publication delete/rename. Private replacement-artifact cleanup is also verified; failed cleanup keeps recovery ownership. Uninstall preserves genuinely pending source-recovery ownership so reinstall/reconciliation remains possible.

Source replacement is atomic at the installed pathname. An external editor/process that opened the old inode before replacement must reopen the pathname after Bridge commits the new generation; continuing to write through that stale descriptor cannot change the installed live file and is outside the current-path CAS guarantee. `flock` is only cooperative and is not used to claim otherwise.

A PHP runtime validation failure can restore the previous file bytes, but it cannot undo arbitrary side effects that candidate code may already have performed before failing. Source Editing is administrator-level code trust, not a sandbox.

## Workspace data is not visible through content tools

That is intentional. Workspace documents/tasks are private internal objects and are accessible only through `workspace-resume`, `workspace-document`, `workspace-task`, and the WP AI Bridge admin screens.

## Reconnecting after an update

Replacing the plugin ZIP normally preserves settings and OAuth state. If the App tool list does not reflect new abilities after an update, use ChatGPT's App rescan/reconnect flow.


## Check WordPress Core updates without installing them

The core-update-status read-only Ability projects **cached** native Core update offers under the default-off **Site Configuration** grant and native manage_options (or multisite manage_network_options). A status of unknown_or_stale means the last native cache check is missing, malformed, for another installed Core version, or over 24 hours old; it must not be represented as up to date. The native_update_core_allowed, file_modifications_allowed, and automatic_updater_disabled flags are distinct observations, not installation permission. Bridge does not run Core update checks, downloads, or upgrades. Use WordPress or the host's approved update tooling outside Bridge for actual upgrades.


## Site Health via the AI Bridge

In builds including Site Health, the Site Configuration group (default off) and current native view_site_health_checks are required. action=list only discovers and describes tests. action=run executes one supported Core test with native REST/controller checks. Provider PHP callbacks are not automatically executable, and an HTTP Authorization-header check that needs real inbound network behavior is left to Tools > Site Health. Native health tests cannot prove a direct OAuth or Gateway client session.

## Large or multi-project Workspace and uncertain create

Use exact project_ref in resume/list when several unrelated projects share one WordPress site; a mixed-site resume intentionally provides no global current project. If workspace_pagination_required is returned, list view=summary with limit and before_id, then read one ID. For long document content or task notes, use version/hash-protected UTF-8 windows with next_offset and complete. An invalid/corrupt or over-quota record is reported as incomplete/overflow, not silently skipped or exported as complete.

After create_claim_outcome_unknown, workspace_key_outcome_unknown, create_claim_partial or create_claim_expired, do NOT invent a new operation_id and blindly retry. Inspect current WordPress object and authority, or request administrative reconciliation if a claim is stranded. create_claim_conflict means one ID was reused for different payloads. Recovery is limited to 30 days; expired and unknown keys remain reserved, and a site has a 2,048-claim safety limit. Legacy requests without operation_id are not duplicate-safe. Workspace Clear does not erase separate operation receipts/canonical-key claims.
