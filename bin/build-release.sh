#!/usr/bin/env bash
# Build the release zip for a tag.
# Usage: bin/build-release.sh v1.2.3
set -euo pipefail

tag="${1:?Usage: bin/build-release.sh v1.2.3}"
root="$(git rev-parse --show-toplevel)"
version="${tag#v}"

header="$(git -C "$root" show "$tag:admaps.php" | sed -n 's/^ \* Version: *//p')"
if [ "$header" != "$version" ]; then
	echo "admaps.php at $tag says version $header, not $version." >&2
	exit 1
fi

out="$root/admaps-$version.zip"
rm -f "$out"
git -C "$root" archive --format=zip --prefix=admaps/ -o "$out" "$tag"
echo "$out"
