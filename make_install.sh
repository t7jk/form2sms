#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_SLUG="$(basename "$SCRIPT_DIR")"

if [[ ! -f "$SCRIPT_DIR/form2sms.php" ]]; then
  echo "Brak pliku $SCRIPT_DIR/form2sms.php" >&2
  exit 1
fi

# Wyciągamy wersję z nagłówka wtyczki (stała FORM2SMS_VERSION).
VERSION="$(awk -F"'" '/FORM2SMS_VERSION/ {print $(NF-1); exit}' "$SCRIPT_DIR/form2sms.php")"
if [[ -z "$VERSION" ]]; then
  echo "Nie udało się odczytać wersji z form2sms.php" >&2
  exit 1
fi

# Użycie:
#   ./make_install.sh [output_dir] [zip_name]
#   output_dir domyślnie: dist/
#   zip_name domyślnie: <slug>-<version>-install.zip
OUTPUT_DIR="${1:-dist}"
ZIP_BASENAME="${2:-${PLUGIN_SLUG}-${VERSION}-install.zip}"

if [[ "$OUTPUT_DIR" = /* ]]; then
  OUT_DIR_ABS="$OUTPUT_DIR"
else
  OUT_DIR_ABS="$SCRIPT_DIR/$OUTPUT_DIR"
fi
mkdir -p "$OUT_DIR_ABS"

ZIP_PATH="$OUT_DIR_ABS/$ZIP_BASENAME"

if command -v zip >/dev/null 2>&1; then
  :
else
  echo "Brak polecenia 'zip' w systemie." >&2
  exit 1
fi

if [[ ! -d "$SCRIPT_DIR/includes" ]] || [[ ! -d "$SCRIPT_DIR/languages" ]]; then
  echo "Oczekiwane katalogi nie istnieją (includes/languages)." >&2
  exit 1
fi

TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

# Budujemy katalog instalacyjny, w którym kopiujemy TYLKO runtime pliki:
# - form2sms.php
# - includes/
# - languages/
# Nie pakujemy m.in. tests/, phpunit.xml.dist, infekcji i plików dev.
BUILD_DIR="$TMP_DIR/$PLUGIN_SLUG"
mkdir -p "$BUILD_DIR"

cp "$SCRIPT_DIR/form2sms.php" "$BUILD_DIR/"
cp -R "$SCRIPT_DIR/includes" "$BUILD_DIR/includes"
cp -R "$SCRIPT_DIR/languages" "$BUILD_DIR/languages"

# Jeśli kiedyś pojawią się readme'y, można je dołączyć.
for f in readme.txt readme.md; do
  if [[ -f "$SCRIPT_DIR/$f" ]]; then
    cp "$SCRIPT_DIR/$f" "$BUILD_DIR/"
  fi
done

# -X: mniej metadanych w ZIP (bardziej "czysty" paczkowany archiwum)
( cd "$TMP_DIR" && rm -f "$ZIP_PATH" && zip -qrX "$ZIP_PATH" "$PLUGIN_SLUG" )

echo "Utworzono paczkę instalacyjną: $ZIP_PATH"

