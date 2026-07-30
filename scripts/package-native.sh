#!/usr/bin/env bash

set -euo pipefail

if [[ "$#" -ne 4 ]]; then
    echo "Usage: package-native.sh <target> <library> <output-directory> <source-revision>" >&2
    exit 2
fi

target="$1"
library="$2"
output_directory="$3"
source_revision="$4"

case "$target" in
    linux-gnu-x86_64|linux-gnu-aarch64)
        filename="libdrover.so"
        ;;
    macos-x86_64|macos-aarch64)
        filename="libdrover.dylib"
        ;;
    *)
        echo "Unsupported Drover target: $target" >&2
        exit 2
        ;;
esac

if [[ ! -f "$library" ]]; then
    echo "Drover library does not exist: $library" >&2
    exit 2
fi

if [[ ! "$source_revision" =~ ^[0-9a-f]{40}$ ]]; then
    echo "Drover source revision must be a lowercase 40-character Git SHA." >&2
    exit 2
fi

mkdir -p "$output_directory"
output_directory="$(cd "$output_directory" && pwd -P)"
staging="$(mktemp -d)"
trap 'rm -rf -- "$staging"' EXIT
package_path="native/drover/prebuilt/$target"
mkdir -p "$staging/$package_path"
install -m 0644 "$library" "$staging/$package_path/$filename"

asset="drove-native-$target.tar.gz"
tar_path="$output_directory/${asset%.gz}"
archive_path="$output_directory/$asset"
entry="$package_path/$filename"

if tar --version 2>/dev/null | grep -q 'GNU tar'; then
    tar -C "$staging" \
        --format=ustar \
        --owner=0 \
        --group=0 \
        --numeric-owner \
        --mtime='@0' \
        -cf "$tar_path" \
        "$entry"
else
    TZ=UTC touch -t 198001010000 "$staging/$entry"
    COPYFILE_DISABLE=1 tar -C "$staging" \
        --format ustar \
        --uid 0 \
        --gid 0 \
        --uname root \
        --gname root \
        -cf "$tar_path" \
        "$entry"
fi

gzip -9 -n "$tar_path"

archive_sha256="$(shasum -a 256 "$archive_path" | awk '{print $1}')"
library_sha256="$(shasum -a 256 "$library" | awk '{print $1}')"
archive_bytes="$(wc -c < "$archive_path" | tr -d '[:space:]')"
library_bytes="$(wc -c < "$library" | tr -d '[:space:]')"

(
    cd "$output_directory"
    printf '%s  %s\n' "$archive_sha256" "$asset" > "$asset.sha256"
)

manifest="$output_directory/drove-native-$target.provenance.json"
printf '{\n  "schema": 1,\n  "source_revision": "%s",\n  "target": "%s",\n  "archive": {\n    "name": "%s",\n    "sha256": "%s",\n    "bytes": %s\n  },\n  "library": {\n    "name": "%s",\n    "sha256": "%s",\n    "bytes": %s\n  }\n}\n' \
    "$source_revision" \
    "$target" \
    "$asset" \
    "$archive_sha256" \
    "$archive_bytes" \
    "$filename" \
    "$library_sha256" \
    "$library_bytes" \
    > "$manifest"

echo "$archive_path"
