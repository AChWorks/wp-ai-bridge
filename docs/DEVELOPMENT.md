# Development and testing

## Where to find reliable project information

- **Product intent and lasting constraints:** [MASTER-SPEC](../MASTER-SPEC.md). It is not a release history or active task tracker.
- **Architecture and actual operation contracts:** [Architecture](./ARCHITECTURE.md), [Ability reference](./ABILITIES.md) and the relevant narrowly scoped `docs/` guide.
- **Security, identity and recovery:** [Security](./SECURITY.md), [approved OAuth clients](./OAUTH-CLIENTS.md) and the executable regression tests.
- **Current work:** GitHub Issues and PRs. **Validation:** the relevant exact-head CI. **Published behavior:** GitHub release assets, tags and changelog.
- **User workflows:** root README and [installation](./INSTALLATION.md) / [user guide](./USER-GUIDE.md). Do not duplicate developer checkpoints in them.

Use Git history for **occasional historical investigation**, not as an active documentation tree. The original v0.1 design snapshot remains recoverable at [this immutable historical commit](https://github.com/AChWorks/wp-ai-bridge/tree/fe2516435d82bb7957d7e66dae039cad410ff022/docs/maintainer/reference/v0.1-pre-release). The dated v0.5.1 administrator coverage matrix remains recoverable at [this exact Git revision](https://github.com/AChWorks/wp-ai-bridge/blob/76759d71dddd26d390a4fd748377512326fc76d8/docs/POST-PARITY-AUDIT.md), alongside the accepted outcome in [closed Issue #99](https://github.com/AChWorks/wp-ai-bridge/issues/99). Neither is a current implementation/roadmap.

Do not introduce parallel handoff journals, old-release snapshots or long-lived topic branches as substitutes for GitHub truth. After a merged topic has no dependent work, its branch can be removed without losing Git or PR history.

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
- When a parent/program Issue intentionally owns several coherent child outcomes or remediations, keep the completion PR Draft through that meaningful program slice. Do not manufacture a separate final-assurance/review cycle for every absorbed child or reviewer observation unless a real dependency, rollback, or authority boundary requires it.

Draft and intermediate `synchronize` updates do not run automatic validation jobs. Development uses targeted local checks until the candidate is deliberately marked ready for review. Documentation-only pull requests do not start plugin CI at all.

Security regression coverage belongs to implementation and CI. Add or adjust deterministic repository tests when a concrete invariant needs coverage, but do not turn every security observation into a separate remote probing session or full-assurance cycle while the implementation is still moving.

### Review-ready / final candidate

Mark the PR ready only after implementation, targeted validation, documentation, and self-review are complete enough to freeze a candidate. For a parent/program completion PR, that means the accepted parent cutline is complete, not merely that one absorbed child or remediation commit is ready.

The current `.github/workflows/ci.yml` runs the complete supported WordPress assurance set for **non-draft** pull requests on `opened`, `reopened`, `ready_for_review`, and **every subsequent `synchronize` push** that changes non-ignored paths. Draft PR jobs are skipped by each job's `if` guard. A ready/non-draft code PR currently runs **22 workflow jobs**: **21 assurance jobs** (Quality, **six** WordPress × MCP Adapter integration combinations, **fourteen** specialized suites) plus **one** OCI image-supply job. Job counts are descriptive; the workflow file defines the actual required checks. Keep active remediation in Draft and batch local/targeted checks before making a final ready candidate. Path filtering applies to the **cumulative changed-file set of the PR, not just the latest commit**: a docs-only push on a mixed code+docs PR can trigger full CI, while an entirely docs-only PR is ignored. Reuse unaffected technical evidence when legitimate, but still diagnose new failures and meet enforced branch/PR checks.

Full assurance includes:

- the normal WordPress 6.9 and current integration lanes;
- one separate-process, localhost-only authenticated HTTPS/OAuth/MCP **Media** client proof: PNG, opaque ZIP and a >1 MiB real UTF-8 text document ingress through the WordPress private-transfer protocol, explicit native Media publication and one-way claim, complete original-byte egress by private snapshot and inline Media windows, and strict SHA-256 checks (Issue #134). The existing separate-process native WXR producer/importer fixture is also a dedicated specialized lane, verifying authenticated export → chunked original-byte retrieval → staged WXR import and preflight/plan → explicitly consented native Importer execution with durable non-replay receipt (Issues #134/#139). These are disposable WordPress tests, **not** ChatGPT host-native downloadable attachment acceptance;
- consolidated single-site regressions for previously integrated security/administration slices;
- dedicated multisite source-editing and user-metadata authority suites;
- canonical identity/activation coverage on both supported WordPress lanes.

If a ready PR needs remediation, return it to Draft, batch all related fixes that are reasonably discoverable from the finding, use targeted validation while the candidate is changing, then mark it Ready once to generate the next meaningful full-assurance candidate. Do not request a fresh full run or independent review for every intermediate remediation commit.

Independent HIGH_ASSURANCE review, when required by the active contract, starts only after the required candidate evidence is complete. The reviewer is expected to inspect the exact source/diff, contracts, existing tests and CI evidence. Do not task the independent reviewer with inventing or executing new exploit-style, synthetic attack, credential, live-system, or operational probe matrices. If a reviewer identifies an invariant that needs new executable coverage, return that finding to implementation; the implementation/CI path owns the regression fixture and validation. If the candidate later changes, re-review only the changed surface and any assumptions it can affect; do not discard sound analysis of unchanged code.

`main` is protected and PR-only, including for administrators; direct pushes, force pushes, and branch deletion are disabled. CI is therefore PR-only and does not rerun the same assurance suite after merge. Immediately before integration, refresh the candidate/target identity and reuse existing evidence unless target drift can affect the property or assumption it proved; tree-equivalent or documentation-only drift does not justify repeating broad technical validation.

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

### HIGH_ASSURANCE review versus implementation validation

HIGH_ASSURANCE source review should normally be satisfiable from exact source/diffs, supported upstream contracts, existing deterministic fixtures, isolated WordPress integration results, and exact-candidate CI. A real penetration test, production attack simulation, privileged live credential operation, or third-party target interaction is a different activity and requires a separate work item with explicit target, environment, scope, and authorization. Never silently expand source review into operational testing.

Implementation and CI may maintain deterministic defensive regressions for concurrency, reentrancy, authorization, provenance, stale-state and similar invariants. Those fixtures can model difficult ordering/state conditions inside disposable repository-controlled environments. The independent reviewer inspects the fixture design, source changes and recorded results; the review handoff should not ask another AI to generate payload recipes, recreate attack sequences, or execute new security probes. A missing regression is a review finding for implementation to address, not an instruction for the reviewer to become an active test operator.

Keep independent-review packets concise: exact repository/change identity, accepted defensive invariants, authoritative source/docs to inspect, existing test/CI evidence, and the required findings/result contract. Avoid long catalogs of hypothetical probe payloads when source/diff/evidence review can answer the decision.

### Tool or provider limitations

If one AI/tool route cannot inspect a security-sensitive detail or returns a platform restriction such as content not being shown:

1. do not rephrase, fragment, or route the request merely to evade that restriction;
2. continue every safe repository-scoped/read-only action that remains valid;
3. reduce the handoff to source/diff/contracts/existing-test/CI evidence when that still proves the objective;
4. use an equivalent authoritative source when it preserves the required evidence semantics;
5. report the exact missing evidence and its effect on completeness;
6. never fabricate evidence, weaken the security property, or change tests merely to obtain a pass.

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

For the rotating-refresh lost-response recovery and real-database concurrency layer:

```bash
bash bin/run-issue80-refresh-recovery.sh 6.9-php8.4-apache
bash bin/run-issue80-refresh-recovery.sh php8.4-apache
```

For the native canonical identity/activation layer:

```bash
bash bin/run-canonical-identity.sh 6.9-php8.4-apache
bash bin/run-canonical-identity.sh php8.4-apache
```

Coverage includes WordPress 6.9/current, direct OAuth/MCP transport, rotating-refresh ambiguous-response recovery with real MariaDB concurrency, raw MCP discovery/execution, content/block safety, Persian runtime localization, Workspace concurrency/lifecycle, Astra native Ability reuse, Code Snippets provider generations, consolidated regressions for integrated administrator-capability slices, and clean canonical activation without retired migration code.

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
docs/           architecture, security, operation contracts and developer guides
```

### Pinned published-predecessor ZIP upgrade regression

The currently checked-in fixture exercises the real **published v0.6.10 → v0.6.11** replacement path. It starts from the
**published v0.6.10 release ZIP, pinned by SHA-256** (the runner checks byte identity; GitHub does not enforce asset immutability) to the current
candidate, using WordPress Core's normal replacement operation:

```bash
bash bin/run-published-upgrade.sh 6.9-php8.4-apache
bash bin/run-published-upgrade.sh php8.4-apache
```

The runner verifies the original release ZIP checksum, seeds private WordPress
Workspace data, canonical settings and signed-client OAuth/PKCE and refresh
credentials, then installs the candidate in place. It verifies preserved
record hashes and OAuth identity, exercises the original bearer through the
official MCP Adapter and rotates the original refresh token. The candidate
must register `workspace-delete`, `diagnostics-read`, and the guarded exact package-replacement contract while retaining
default-off Users & Destructive authority; registration is not authorization.
Fixture credentials remain confined to the disposable test environment.

Both supported CI upgrade lanes use this runner. Its pinned **published v0.6.10** predecessor is correct for the **v0.6.11** candidate, not automatically the latest predecessor for a future release. Before validating the next version, advance the pinned predecessor ZIP URL, checksum, expected version and compatible fixture to the actual latest published predecessor. Never label a historical predecessor run as newest-published-to-candidate proof.

## Canonical GitHub distribution and conditional WordPress.org directory eligibility

Open acceptance and release-policy work is tracked in [WordPress.org directory readiness #171](https://github.com/AChWorks/wp-ai-bridge/issues/171); this section owns enduring maintainer guidance, not live submission status.

**Distribution policy:** GitHub Releases is the maintained public distribution and update channel for the **full-feature plugin**. Do not remove, hide, or separate administrator-approved External Packages, installed plugin/theme Source Editing, or other legitimate features to satisfy Plugin Directory policy. The GitHub release ZIP keeps the canonical `wp-ai-bridge/wp-ai-bridge.php` identity. Starting with published **v0.6.8**, which contains `Update URI: https://github.com/AChWorks/wp-ai-bridge`, Core's native `update_plugins_github.com` path discovers official published stable release assets; actual downloads/replacements still use WordPress Core. Sites still running v0.6.7 or older need one manual ZIP replacement to bootstrap this capability; active v0.6.8+ installations can discover future stable releases natively. Never claim an unreleased candidate is installed or published.

Official WordPress.org listing is a **separate, conditional eligibility/reviewer/submission outcome**: attempt only if the **same full-feature product** is acceptable. No code- or metadata-disguised directory-only variant and no reviewer contact without Owner approval. A Plugin Check `plugin_updater_detected` result is evidence about directory eligibility, not permission to remove the GitHub update channel.

Before submitting a frozen and independently reviewed candidate (resolve the policy eligibility **first**, before substantial directory-specific packaging):

1. **Resolve an evidenced Plugin Directory policy conflict before submission.** [Detailed Plugin Guidelines §8](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/) explicitly lists installing/updating plugins, themes or add-ons from non-WordPress.org servers as disallowed externally loaded executable code, while allowing certain management services that push software through their own domain. The existing administrator-authorized `External Packages` route uses WordPress Core Upgrader but still selects public third-party ZIP sources, so security tests/Core API use **do not establish** directory eligibility. Seek written classification/acceptable architecture from the Plugin Review Team **only with Owner-approved external outreach**, then take an explicit owner-level decision on a single maintainable product/distribution policy. Do not silently remove the existing feature, conceal it from review, create divergent undocumented builds, or claim an automatic exception.
2. Declare only verified minimum WordPress/PHP versions in the **main plugin header**. Evaluate native `Requires Plugins: mcp-adapter` only after checking the installed/dependency-inactive upgrade and activation behavior in supported WordPress versions; the current plugin remains deliberately usable for its local admin/Workspace while the Adapter is absent, with a clear administrator notice. Do not silently change this behavior or guess a PHP minimum from the single PHP 8.4 CI lane.
3. Prepare and validate the required WordPress.org-format **`readme.txt`** (distinct from user/developer `README.md`): truthful features, installation, dependencies, supported/tested versions, privacy/third-party data flows, privilege risks and known limitations, supported license and `Stable tag`. Obtain the actual WordPress.org contributor account/approved slug before presenting either as registered.
4. Check GPL-compatible sources/assets/translations, privacy/consent and outward HTTP calls, stable upgrade/uninstall/multisite behavior, static asset delivery and absence of development/test/vendor artifacts in the distribution. Treat any required SVN trunk/tag publication and user-facing availability as a separately approved release operation, not a normal GitHub push.
5. Run official **Plugin Check** (repository/security/performance checks, plus runtime checks where supported) against the **actual installable ZIP**, investigate every finding, and retain explicit evidence for any false positive. The existing repository PHPCS/security script and successful WordPress integration tests do not substitute for the Plugin Directory review.

**Interpreting official Plugin Check and package-update safety.** The checker can emit `ERROR`/`WARNING` findings while its WP-CLI process still exits successfully, especially in JSON mode: examine and classify the **actual report**, do not equate exit code 0 with zero findings. Separate actionable defects from context-dependent sniffs with code-level evidence and, where necessary, an official reviewer decision. In this codebase, exact inode checks, fsync, file locks, native atomic rename/link/CAS and direct prepared SQL can have security/correctness properties that generic `WP_Filesystem` and caching alternatives do not reproduce; **never mass-rewrite them merely to silence a scan**. Check compiled Persian PHP translation entrypoints, correct directory `readme.txt`, `Requires PHP`/`Requires Plugins`, and any WordPress.org policy on the existing `WP AI Bridge` name/`wp-ai-bridge` slug. For GitHub-distributed builds also assess the WordPress `Update URI` collision-protection behavior and the **future single-slug upgrade path** before changing the header. Release decisions use the frozen candidate, installed package, actual Plugin Check version/output and a proportionate security review. Current unresolved directory evidence and approvals live in [#171](https://github.com/AChWorks/wp-ai-bridge/issues/171); related installed Media/package/native temp-path safety is owned by [#173](https://github.com/AChWorks/wp-ai-bridge/issues/173), not as rolling counts or a second storage subsystem in this document.

Official references: [plugin headers](https://developer.wordpress.org/plugins/plugin-basics/header-requirements/), [readme.txt](https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/), [common review issues](https://developer.wordpress.org/plugins/wordpress-org/common-issues/), [SVN release workflow](https://developer.wordpress.org/plugins/wordpress-org/how-to-use-subversion/), and [Plugin Check](https://github.com/WordPress/plugin-check).

## Release process

1. update the plugin version, `readme.txt` Stable tag (must equal the plugin header), and changelog when a new plugin build is being released;
2. run `composer check`;
3. run the complete supported WordPress assurance set, including the native GitHub stable-release update/disposable Core Upgrader fixture;
4. merge only after exact-head CI is green;
5. build the ZIP from the release commit;
6. create an immutable Git tag and GitHub Release for that commit;
7. attach the validated `wp-ai-bridge.zip` asset and verify its checksum/metadata and `vX.Y.Z` stable release identity; Core must never offer automatic GitHub source archives.

Do not force-move a published version tag. Use a patch release for post-publication plugin-package changes.

Repository-only documentation/maintainer updates do not require a plugin version bump unless they change the shipped plugin package or published product contract.

Term-meta security and race regressions live with their executable sources: `tests/issue36-term-meta.php`, `tests/integration/issue36-term-meta-security-smoke.php`, `tests/integration/issue36-primary-identity-smoke.php` and `bin/check-term-meta-confinement.php`. Change those proofs only when the corresponding runtime contract changes; do not duplicate each assertion as a maintainer narrative.
