# Troubleshooting

## The custom App cannot scan tools

Check **WP AI Bridge → Settings** first.

Confirm:

- WordPress 6.9+ is running;
- MCP Adapter is active;
- the reported MCP URL uses HTTPS;
- the WordPress REST API is reachable from the Internet;
- a security/CDN plugin is not blocking `/.well-known/` or the Bridge REST route.

Opening the MCP endpoint without authentication should return an authentication challenge, not a normal HTML page.

## OAuth opens WordPress but authorization does not finish

Start a fresh connection attempt rather than reusing an old consent page. OAuth consent requests are intentionally short-lived and one-time.

Also check browser/network security layers for blocked redirects to ChatGPT and ensure the site/canonical WordPress URLs are correctly configured for HTTPS.

## The App connects but a write is denied

A successful OAuth connection is not write authorization. Check both:

1. the relevant Bridge access group under **WP AI Bridge → Settings**;
2. the connected WordPress user's capability for the target object/action.

For example, publishing normally needs **Builder Write + Live Content** plus the applicable WordPress publish capability.

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

Installation accepts WordPress.org slugs only. The connected user needs the matching Core install/update/activate capability and **Code & Extensions** must be enabled.

If WordPress requires interactive filesystem credentials, the Bridge reports that manual filesystem setup is required rather than collecting those credentials.


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
