#!/usr/bin/env bash
# Build immutable Cashfree WHMCS zip + .sha256 + .manifest for overlay pin.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

VERSION="$(php -r "require 'modules/gateways/cashfree/lib/CashfreeHelpers.php'; echo CASHFREE_PLUGIN_VERSION;")"
GIT_SHA="$(git rev-parse HEAD 2>/dev/null || echo unknown)"
GIT_SHA_SHORT="$(git rev-parse --short HEAD 2>/dev/null || echo unknown)"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"

DIST="$ROOT/dist"
mkdir -p "$DIST"

NAME="cashfree-whmcs-${VERSION}"
ZIP="$DIST/${NAME}.zip"
SHA_FILE="$DIST/${NAME}.zip.sha256"
MANIFEST="$DIST/${NAME}.manifest"

rm -f "$ZIP" "$SHA_FILE" "$MANIFEST"

STAGE="$(mktemp -d)"
cleanup() { rm -rf "$STAGE"; }
trap cleanup EXIT

mkdir -p "$STAGE/modules/gateways" "$STAGE/modules/addons"
cp -a "$ROOT/modules/gateways/cashfree.php" "$STAGE/modules/gateways/"
cp -a "$ROOT/modules/gateways/cashfree" "$STAGE/modules/gateways/"
cp -a "$ROOT/modules/addons/cashfree_ledger" "$STAGE/modules/addons/"
cp -a "$ROOT/README.md" "$STAGE/" 2>/dev/null || true
cp -a "$ROOT/LICENSE" "$STAGE/" 2>/dev/null || true
cp -a "$ROOT/AGENTS.md" "$STAGE/" 2>/dev/null || true

(
  cd "$STAGE"
  # Deterministic-ish zip: exclude junk
  find . -name '.DS_Store' -delete
  zip -qr "$ZIP" .
)

if command -v sha256sum >/dev/null 2>&1; then
  sha256sum "$ZIP" | awk '{print $1}' | tee "$SHA_FILE" >/dev/null
  HASH="$(cat "$SHA_FILE")"
elif command -v shasum >/dev/null 2>&1; then
  shasum -a 256 "$ZIP" | awk '{print $1}' | tee "$SHA_FILE" >/dev/null
  HASH="$(cat "$SHA_FILE")"
else
  HASH="$(php -r "echo hash_file('sha256', '$ZIP');")"
  printf '%s\n' "$HASH" > "$SHA_FILE"
fi

{
  echo "name=cashfree-whmcs"
  echo "version=${VERSION}"
  echo "git_sha=${GIT_SHA}"
  echo "git_sha_short=${GIT_SHA_SHORT}"
  echo "built_at_utc=${STAMP}"
  echo "artifact=$(basename "$ZIP")"
  echo "sha256=${HASH}"
  echo "contents=modules/gateways/cashfree.php,modules/gateways/cashfree/**,modules/addons/cashfree_ledger/**"
  echo "overlay_pin=whmcs-prod-new overlays/cashfree → version ${VERSION} digest sha256:${HASH}"
} > "$MANIFEST"

echo "Built $ZIP"
echo "SHA256 $HASH"
echo "Manifest $MANIFEST"
