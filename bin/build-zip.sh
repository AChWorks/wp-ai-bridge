#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
build_dir="$root/build"
stage_root="$build_dir/stage"
# Keep the installed plugin directory/entrypoint stable so a WP AI Bridge package
# upgrades the existing plugin in place instead of creating a duplicate plugin.
plugin_dir="$stage_root/wp-native-builder-bridge"
zip_file="$build_dir/wp-ai-bridge.zip"

rm -rf "$stage_root"
mkdir -p "$plugin_dir"

cp "$root/wp-native-builder-bridge.php" "$plugin_dir/"
cp "$root/uninstall.php" "$plugin_dir/"
cp "$root/README.md" "$plugin_dir/"
cp "$root/CHANGELOG.md" "$plugin_dir/"
cp "$root/LICENSE" "$plugin_dir/"
cp -R "$root/src" "$plugin_dir/src"
cp -R "$root/languages" "$plugin_dir/languages"
rm -f "$zip_file"

php -r '
$source = $argv[1];
$zipPath = $argv[2];
$zip = new ZipArchive();
if (true !== $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
    fwrite(STDERR, "ERROR: could not create release ZIP.\n");
    exit(1);
}
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY
);
foreach ($iterator as $file) {
    if (!$file->isFile()) {
        continue;
    }
    $absolute = $file->getPathname();
    $relative = substr($absolute, strlen($source) + 1);
    if (!$zip->addFile($absolute, $relative)) {
        fwrite(STDERR, "ERROR: could not add {$relative} to release ZIP.\n");
        $zip->close();
        exit(1);
    }
}
$zip->close();
' "$stage_root" "$zip_file"

mapfile -t entries < <(unzip -Z1 "$zip_file")
required=(
    "wp-native-builder-bridge/wp-native-builder-bridge.php"
    "wp-native-builder-bridge/uninstall.php"
    "wp-native-builder-bridge/LICENSE"
    "wp-native-builder-bridge/CHANGELOG.md"
    "wp-native-builder-bridge/languages/wp-native-builder-bridge-fa_IR.l10n.php"
    "wp-native-builder-bridge/src/class-plugin.php"
)
for path in "${required[@]}"; do
    found=0
    for entry in "${entries[@]}"; do
        if [[ "$entry" == "$path" ]]; then
            found=1
            break
        fi
    done
    if [[ "$found" != "1" ]]; then
        echo "ERROR: release ZIP is missing $path" >&2
        exit 1
    fi
done

# A renamed public artifact must not rename the installed plugin directory.
if printf '%s\n' "${entries[@]}" | grep -q '^wp-ai-bridge/'; then
    echo "ERROR: release ZIP would install a duplicate wp-ai-bridge plugin directory." >&2
    exit 1
fi

for entry in "${entries[@]}"; do
    if [[ "$entry" =~ (^|/)(\.git|\.github|tests|vendor|node_modules|build|composer\.(json|lock))(/|$) ]]; then
        echo "ERROR: release ZIP contains development-only files." >&2
        exit 1
    fi
done

extract_dir="$build_dir/verify"
rm -rf "$extract_dir"
mkdir -p "$extract_dir"
unzip -q "$zip_file" -d "$extract_dir"
while IFS= read -r -d '' file; do
    php -l "$file" >/dev/null
done < <(find "$extract_dir/wp-native-builder-bridge" -type f -name '*.php' -print0)
rm -rf "$stage_root" "$extract_dir"

echo "PASS: installable WP AI Bridge ZIP validated at build/wp-ai-bridge.zip"
