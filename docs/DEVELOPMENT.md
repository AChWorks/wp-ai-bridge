# Development and testing

## Project continuity

Before changing durable architecture or resuming development after a long gap, read:

- [`../MASTER-SPEC.md`](../MASTER-SPEC.md) for canonical project intent and durable constraints;
- [`maintainer/README.md`](./maintainer/README.md) for the recovery/source-of-truth map and preserved design references.

Do not reconstruct active work from old chats or historical reference files. Current source/tests, GitHub Issues/PRs, CI, and Releases own mutable implementation/project state.

## Fast development loop

Development is evidence-driven: reuse still-valid proof and regenerate only the evidence a change can invalidate. A passing test or review does not become stale merely because time passed, a new commit SHA exists, or unrelated documentation changed.

### Evidence reuse and invalidation

Before rerunning a check, identify what changed since the evidence was produced and whether that change can affect the property the check proved.

- Documentation-only changes under `docs/**` or root Markdown files do not invalidate PHP, WordPress, package, compatibility, or historical regression evidence.
- A narrow runtime change should first rerun the smallest test that exercises the changed behavior. Do not rerun unrelated historical suites while implementation is still moving.
- A test/fixture-only change invalidates only the affected test or harness evidence unless it changes a shared test/bootstrap mechanism used by broader assurance.
- Dependency, build/package, CI workflow, shared authorization/core execution, migration, or other cross-cutting changes use the conservative broader/full fallback because their impact is not safely local.
- Target/base SHA drift by itself is not a reason for a broad rerun. Inspect the effective target-to-candidate delta and material assumptions. If the target change is tree-equivalent or documentation-only and cannot affect the tested behavior, reuse the technical evidence and refresh only the identity/review evidence that actually became stale.
- For HIGH_ASSURANCE work, a verdict must still bind to the current exact candidate/target when required, but a fresh reviewer may reuse prior analysis of unchanged surfaces and inspect only the delta plus assumptions that delta can affect.

Do not rerun broad validation solely to make otherwise-current evidence look newer. If repository/platform policy requires a current status check, satisfy that requirement with the narrowest meaningful current check rather than repeating unrelated suites.

### Draft / implementation

Keep an implementation PR in Draft while the candidate is still changing.

- Run the narrowest test that exercises the changed behavior first.
- Run the current workstream's dedicated integration runner only when real WordPress behavior is relevant to the change.
- Run `composer check` before a coherent runtime/build/test checkpoint when it adds useful signal; documentation-only edits do not require it.
- Batch related fixes before pushing instead of creating a remote CI run for every tiny edit.
- Do not repeat the full historical WordPress regression matrix after every formatting, test-fixture, documentation, or narrowly scoped remediation change.
- A new push supersedes older CI for the same PR or branch; stale in-progress runs are cancelled automatically.

Draft and intermediate `synchronize` updates do not run automatic validation jobs. Development uses targeted local checks until the candidate is deliberately marked ready for review. Documentation-only pull requests do not start plugin CI at all.

### Review-ready / final candidate

Mark the PR ready only after implementation, targeted validation, documentation, and self-review are complete enough to freeze a candidate.

The complete supported WordPress assurance set runs when a runtime-relevant PR is explicitly ready for review, or is opened/reopened as a non-draft PR. A later `synchronize` push does not automatically launch the full matrix again.

Full assurance includes:

- the normal WordPress 6.9 and current integration lanes;
- consolidated single-site regressions for previously integrated security/administration slices;
- dedicated multisite source-editing and user-metadata authority suites;
- exact-base identity migration coverage.

If a ready PR needs remediation, return it to Draft, batch related fixes, use targeted validation while the candidate is changing, then mark it Ready once to generate the next full-assurance candidate. Do not request a fresh full run or independent review for every intermediate remediation commit.

Independent HIGH_ASSURANCE review, when required by the active contract, starts only after the required candidate evidence is complete. If the candidate later changes, re-review only the changed surface and any assumptions it can affect; do not discard sound analysis of unchanged code.

A runtime-relevant push to `main` still runs the full assurance set as a conservative fallback while direct pushes are possible. Documentation-only pushes to `main` do not start plugin CI. Rapidly superseded runs for the same branch are cancelled. Removing the remaining post-merge runtime duplicate requires reliable proof that `main` can only receive an already-assured PR result; do not trade away that safety merely for speed.

## Repository-scoped AI development and security review

Routine AI-assisted implementation and security review are repository-scoped, defensive, and non-production by default. Treat source review and isolated validation as engineering work; do not expand them into live-system penetration testing, credential operations, or unrelated third-party activity unless an accepted, separately authorized work item genuinely requires that evidence.

### Evidence ladder

Use the smallest authoritative evidence source that can prove the accepted objective, in this order:

1. current repository source, exact target-to-candidate diff, current Issue/Task Contract, and pull request;
2. authoritative upstream source or documentation pinned to the supported WordPress, MCP Adapter, PHP, or provider version;
3. dependency-free deterministic regression tests;
4. isolated local/container WordPress integration fixtures using synthetic data and credentials;
5. GitHub CI tied to the exact candidate and current target assumptions;
6. a real external or production system only when the objective cannot be established by the earlier layers and explicit authorization permits the exact action, target, and environment.

The Docker integration runners are disposable test environments. Keep their identities, credentials, content, and provider fixtures synthetic. Do not copy real Application Passwords, OAuth secrets, bearer/session material, private keys, production credentials, or unnecessary personal data into prompts, Issues, logs, fixtures, review packets, or CI artifacts.

### Defensive task/review envelope

For a security-sensitive AI implementation or review, record only the decision-relevant boundary:

- **Repository/change:** exact repository plus target/base, candidate or PR, and current contract revision when one exists.
- **Defensive purpose:** the correctness or security property being implemented or verified.
- **Allowed action:** either `READ-ONLY` review or isolated, reversible implementation/test work.
- **Prohibited effects:** no production mutation, credential collection, unrelated third-party targeting, weaponization, persistence, or evasion.
- **Allowed evidence:** only the repository/upstream/test/fixture/CI sources needed for the objective.
- **Secret/data minimization:** synthetic data by default; no raw secret values in prompts or artifacts.
- **Result contract:** evidence-backed findings or implementation/validation results with exact identity and any residual uncertainty.
- **Separate gates:** review, CI, repository access, and technical capability do not authorize integration, production, credential, destructive, or other separately gated actions.

Use neutral defensive language that describes the actual engineering task. Do not label ordinary correctness review as exploitation, credential harvesting, stealth, bypass, or attack execution when those actions are not required.

### HIGH_ASSURANCE review versus operational testing

HIGH_ASSURANCE source review should normally be satisfiable from exact source/diffs, supported upstream contracts, deterministic fixtures, isolated WordPress integration, and exact-candidate CI. A real penetration test, production attack simulation, privileged live credential operation, or third-party target interaction is a different activity and requires a separate work item with explicit target, environment, scope, and authorization. Never silently expand source review into operational testing.

For concurrency, reentrancy, authorization, and provenance invariants, prefer deterministic adversarial fixtures. Relevant patterns include hook ordering, re-entrant metadata writes, nested REST calls, same-request replay, response substitution, stale-state races, same-UUID replacement, and permission-boundary failures. Prove the defensive property in dependency-free tests or the isolated WordPress environment instead of relying on live exploitation.

### Tool or provider limitations

If one AI/tool route cannot inspect a security-sensitive detail:

1. continue every safe repository-scoped or read-only action that remains valid;
2. use an equivalent authoritative source when it preserves the required evidence semantics;
3. report the exact missing evidence and its effect on completeness;
4. never fabricate evidence, weaken the security property, or change tests merely to obtain a pass.

Do not automatically escalate to production access, live credentials, or identity verification merely because one route is unavailable. A stronger authorization or verification boundary is required only when the accepted objective genuinely depends on that capability and the applicable platform or organizational policy requires it.

See [Security](./SECURITY.md#repository-review-and-live-system-boundary) for the corresponding secret, live-system, and authorization boundary.

## Local quality gate

Install development dependencies and run:

```bash
composer install
composer check
```

The quality gate includes:

- strict Composer validation;
- PHP syntax checks;
- dependency-free functional tests;
- WordPress Coding Standards;
- PHPCompatibilityWP;
- Persian translation catalog validation;
- static safety-surface checks;
- release ZIP build and validation.

The generated package is:

```text
build/wp-ai-bridge.zip
```

## Integration tests

Docker-based integration lanes build the release ZIP, install that ZIP into WordPress, install the pinned official MCP Adapter, and exercise the plugin in isolated volumes.

```bash
bash bin/run-integration.sh 6.9-php8.4-apache
bash bin/run-integration.sh php8.4-apache
RUN_OPTIONAL_PROVIDERS=1 bash bin/run-integration.sh php8.4-apache
```

For the consolidated single-site regression layer:

```bash
bash bin/run-single-site-regressions.sh 6.9-php8.4-apache
bash bin/run-single-site-regressions.sh php8.4-apache
```

Coverage includes WordPress 6.9/current, direct OAuth/MCP transport, raw MCP discovery/execution, content/block safety, Persian runtime localization, Workspace concurrency/lifecycle, Astra native Ability reuse, Code Snippets provider generations, and the consolidated regressions for integrated administrator-capability slices.

Gravity Forms automated coverage uses a test-only GFAPI contract fixture; it is not evidence that a commercial Gravity Forms binary was executed in CI.

## Repository layout

```text
src/Abilities/   typed WordPress/provider abilities
src/Admin/       WP AI Bridge admin screens
src/Auth/        direct OAuth and MCP authorization
src/Support/     settings, permissions, environment, mutation log
src/Workspace/   private durable Workspace storage
languages/       bundled WordPress translation catalog
tests/           fast and integration tests
bin/             development/build/test helpers
docs/maintainer/ recovery map and preserved design references
```

## Release process

1. update the plugin version and changelog when a new plugin build is being released;
2. run `composer check`;
3. run the complete supported WordPress assurance set;
4. merge only after exact-head CI is green;
5. build the ZIP from the release commit;
6. create an immutable Git tag and GitHub Release for that commit;
7. attach the validated ZIP and verify its checksum/metadata.

Do not force-move a published version tag. Use a patch release for post-publication plugin-package changes.

Repository-only documentation/maintainer updates do not require a plugin version bump unless they change the shipped plugin package or published product contract.

## Generic term metadata validation

`composer test` includes `tests/issue36-term-meta.php` for bounded schemas/physical reads, target and group gates, shared secret policy, JSON/opaque-value refusal and stale-state failures. The actual WordPress capability/filter and persistence behavior is exercised by `tests/integration/issue36-term-meta-security-smoke.php` in **both** existing integration lanes. The fixture uses Core categories/tags and a private custom taxonomy with a dedicated edit capability, without an external provider plugin.

Keep the Issue #34 regression/integration tests unchanged. The new tests cover explicit/provider/mapped authorization, shared term identity, physical defaults/virtual reads, exact-byte CAS, duplicate contention, original-invocation creation ownership, compensation lifecycle/cache state, SQL NULL/scalar/slashing behavior, sanitizer pass count, authority/target races (including taxonomy or term-taxonomy identity changes before and during all three compensation pre-hooks) and log redaction. `bin/static-safety-check.sh` confines the term store separately; adding another database surface requires explicit architectural review, not an exclusion from the check.

The primary-write regression `tests/integration/issue36-primary-identity-smoke.php` runs in both native lanes. Its isolated `query` observer returns SQL unchanged and moves only a fixture term immediately before the pending physical mutation, after the last PHP guard. It covers taxonomy and term-taxonomy-ID transfers for creation, update and deletion, including NULL/string branches; it checks that every boundary was actually reached, the transferred target is real, the result is an error, and all physical metadata bytes are unchanged. Keep the separate 12 before/during-compensation transfer tests intact. This deterministic timing test is not a separate multi-session database-lock lifetime test.

The term store's identity joins may read only native `terms`/`term_taxonomy` while writing only termmeta. `bin/check-term-meta-confinement.php` pins the complete literal SQL and every bound argument, including table identity and the original authorized taxonomy/term-taxonomy snapshot. Tests mutate these source tokens without executing the malformed samples. Do not regenerate expectations merely to approve an unreviewed query change, or relax the independently shipped post-meta checks.
