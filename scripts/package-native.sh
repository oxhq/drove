#!/usr/bin/env bash

set -euo pipefail

if [[ "$#" -ne 3 ]]; then
    echo "Usage: package-native.sh <target> <library> <output-directory>" >&2
    exit 2
fi

target="$1"
library="$2"
output_directory="$3"

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

mkdir -p "$output_directory"
staging="$(mktemp -d)"
trap 'rm -rf -- "$staging"' EXIT
package_path="native/drover/prebuilt/$target"
mkdir -p "$staging/$package_path"
install -m 0644 "$library" "$staging/$package_path/$filename"

asset="drove-native-$target.tar.gz"
COPYFILE_DISABLE=1 tar -C "$staging" -czf "$output_directory/$asset" native
(
    cd "$output_directory"
    shasum -a 256 "$asset" > "$asset.sha256"
)

echo "$output_directory/$asset"
