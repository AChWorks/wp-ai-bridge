# Maintainer continuity and recovery

This directory exists so future development can resume from repository/GitHub truth without access to any previous ChatGPT conversation.

It is intentionally separate from the public product README. Do not turn this directory into a running manager diary, chat archive, or duplicate Issue tracker.

## Start here when resuming development

Use the following source-of-truth order:

1. [`../../MASTER-SPEC.md`](../../MASTER-SPEC.md) — durable project purpose, architecture boundaries, non-goals, compatibility rules, and success model.
2. Current product docs under [`docs/`](../) — user-facing installation, behavior, Ability surface, integrations, security, troubleshooting, and development/testing.
3. Source and tests on the current target branch — exact implementation behavior.
4. GitHub Issues and pull requests — active work, unresolved dependencies, decisions, and integration state.
5. GitHub Actions — validation tied to the exact relevant commit.
6. Git tags and GitHub Releases — published release state and artifacts.
7. [`reference/v0.1-pre-release/`](./reference/v0.1-pre-release/) — deeper design/reference material from the original v0.1 development phase when current sources are insufficient.

A future Master should not reconstruct live status from old reference documents. Issue numbers, pre-release gates, and sequencing statements inside the preserved snapshot describe the project at that historical point. Current GitHub state wins for mutable facts.

## Current durable documentation

| Topic | Current source |
| --- | --- |
| Project-level intent and architectural constraints | [`MASTER-SPEC.md`](../../MASTER-SPEC.md) |
| Product overview and normal use | [`README.md`](../../README.md) |
| Installation / ChatGPT connection | [`INSTALLATION.md`](../INSTALLATION.md) |
| User workflows | [`USER-GUIDE.md`](../USER-GUIDE.md) |
| Current Ability families | [`ABILITIES.md`](../ABILITIES.md) |
| Optional providers | [`INTEGRATIONS.md`](../INTEGRATIONS.md) |
| Security model | [`SECURITY.md`](../SECURITY.md) |
| Current architecture overview | [`ARCHITECTURE.md`](../ARCHITECTURE.md) |
| Troubleshooting | [`TROUBLESHOOTING.md`](../TROUBLESHOOTING.md) |
| Development, validation, and release process | [`DEVELOPMENT.md`](../DEVELOPMENT.md) |
| Published change history | [`CHANGELOG.md`](../../CHANGELOG.md) and GitHub Releases |

## Preserved v0.1 reference set

The v0.1.1 public-documentation cleanup replaced or removed several detailed documents. Their exact pre-cleanup contents and original relative layout are preserved under `reference/v0.1-pre-release/` so architectural reasoning, internal links, and detailed contracts are not lost.

Preserved layout:

```text
reference/v0.1-pre-release/
├── MASTER-SPEC.md
└── docs/
    ├── ABILITY-INVENTORY.md
    ├── CORE-ABILITY-SAFETY-BOUNDARIES.md
    ├── INSTALLATION-AND-CONNECTION.md
    ├── OPTIONAL-INTEGRATIONS-AND-ADMIN.md
    ├── PROJECT-WORKSPACE-ARCHITECTURE.md
    ├── RELEASE-CHECKLIST.md
    └── TROUBLESHOOTING.md
```

`MASTER-SPEC.md` is the complete 607-line pre-release Master Specification. The files under the snapshot's `docs/` directory are the exact detailed documents that existed immediately before the public-documentation cleanup.

These snapshots are reference evidence, not current live contracts. When they conflict with current code, current durable docs, active GitHub Issues/PRs, CI, or release state, use the current authoritative source.

## Documentation maintenance rule

When future development changes durable product behavior or architecture:

- update the nearest current authoritative document;
- update `MASTER-SPEC.md` only when project-level intent, supported baseline, durable architecture boundaries, non-goals, or completion/success criteria change;
- keep active task state in GitHub Issues/PRs instead of adding status snapshots here;
- preserve important superseded design material under `reference/` only when it contains future-useful rationale that is no longer represented by a stronger current source;
- keep public README content focused on the plugin and normal usage.

## Branch lifecycle and recovery rule

- `main` is the only long-lived development branch for this repository unless a future accepted workflow explicitly establishes another long-lived branch.
- Feature, fix, release-preparation, and other topic branches are temporary. After their work is merged, the target is verified, and no open PR or independent work still depends on the branch, delete the topic branch.
- Do not infer active work merely from a leftover branch name. Recover active work from open GitHub Issues/PRs, current target refs, and CI first; use branch history only as supporting implementation evidence.
- Preserve history through Git commits, merged PRs, immutable release tags, and GitHub Releases rather than by retaining merged topic branches.
- A replacement Master should be able to start from `main`, this recovery map, current GitHub control-plane state, and the latest relevant release without access to any previous chat.

## Repository boundary

This repository owns the WordPress Bridge plugin. The companion `AChWorks/wp-native-builder` repository is separate. Cross-repository reads may be useful for interface compatibility, but this repository's recovery must remain possible from its own canonical specification, code/docs, and GitHub control plane.
