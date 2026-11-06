#!/usr/bin/env bash
# Build the release zip for a tag, adding the read-only update token (never committed).
# Usage: bin/build-release.sh v1.2.3
# Token: ADMAPS_UPDATE_TOKEN env var, or ~/.config/admaps/update-token
set -euo pipefail

tag="${1:?Usage: bin/build-release.sh v1.2.3}"
root="$(git rev-parse --show-toplevel)"
version="${tag#v}"

token="${ADMAPS_UPDATE_TOKEN:-$(cat "$HOME/.config/admaps/update-token" 2>/dev/null || true)}"
token="$(printf '%s' "$token" | tr -d '[:space:]')"
if [ -z "$token" ]; then
	echo "No update token. Set ADMAPS_UPDATE_TOKEN or save it in ~/.config/admaps/update-token" >&2
	exit 1
fi
if ! [[ "$token" =~ ^[A-Za-z0-9_]+$ ]]; then
	echo "The update token has unexpected characters." >&2
	exit 1
fi

header="$(git -C "$root" show "$tag:admaps.php" | sed -n 's/^ \* Version: *//p')"
if [ "$header" != "$version" ]; then
	echo "admaps.php at $tag says version $header, not $version." >&2
	exit 1
fi

out="$root/admaps-$version.zip"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

git -C "$root" archive --format=tar --prefix=admaps/ "$tag" | tar -x -C "$tmp"
printf "<?php\n// Read-only GitHub token for update checks, added when the release was built.\ndefined( 'ABSPATH' ) || exit;\nreturn '%s';\n" "$token" > "$tmp/admaps/update-token.php"

rm -f "$out"
( cd "$tmp" && zip -qrX "$out" admaps )
echo "$out"
