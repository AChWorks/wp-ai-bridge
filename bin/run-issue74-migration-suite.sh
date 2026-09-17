#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
wordpress_tag="${1:-6.9-php8.4-apache}"

# Exercise the storage-engine precondition first so F-001 fails quickly and independently.
bash "$root/bin/run-issue74-engine-guard.sh" "$wordpress_tag"

# Then run the exact published v0.3.0 uninstall -> canonical v0.4.0 migration lifecycle.
bash "$root/bin/run-issue74-migration.sh" "$wordpress_tag"
