#!/usr/bin/env bash
# Package WP-SKOS as a WordPress-installable zip.
#
# Usage: bash utils/package.sh
#
# Output: dist/wp-skos.zip
# Install via: WordPress Admin → Plugins → Add New → Upload Plugin

set -e

PLUGIN_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -W)"
PLUGIN_NAME="wp-skos"
DIST_DIR="${PLUGIN_ROOT}/dist"
ZIP_PATH="${DIST_DIR}/${PLUGIN_NAME}.zip"

echo "Plugin root: $PLUGIN_ROOT"
echo "Output:      $ZIP_PATH"
echo ""

# ---------------------------------------------------------------------------
# Translations: compile .mo from every .po, and (re)generate the block-editor
# JSON (<domain>-<locale>-<handle>.json) so bundled translations stay in sync.
# Requires GNU gettext (msgfmt). No-op if there are no .po files.
# ---------------------------------------------------------------------------

echo "Compiling translations..."
for po in "$PLUGIN_ROOT"/languages/*-*.po; do
    [ -e "$po" ] || continue
    msgfmt "$po" -o "${po%.po}.mo"
    base="$(basename "$po" .po)"        # e.g. wp-skos-ru_RU
    locale="${base#${PLUGIN_NAME}-}"    # e.g. ru_RU
    python "$PLUGIN_ROOT/utils/make-json.py" "$PLUGIN_ROOT" "$PLUGIN_NAME" "$locale"
done
echo ""

mkdir -p "$DIST_DIR"
rm -f "$ZIP_PATH"

echo "Creating zip..."

python -c "
import os, sys, zipfile, fnmatch

def parse_gitignore(path):
    rules = []
    try:
        with open(path, encoding='utf-8') as f:
            for line in f:
                line = line.rstrip('\n').rstrip('\r')
                if not line or line.startswith('#') or line.startswith('!'):
                    continue
                dir_only = line.endswith('/')
                if dir_only:
                    line = line[:-1]
                anchored = '/' in line
                rules.append((line, dir_only, anchored))
    except FileNotFoundError:
        pass
    return rules

def is_ignored(rel_path, is_dir, rules):
    name = rel_path.split('/')[-1]
    for pattern, dir_only, anchored in rules:
        if dir_only and not is_dir:
            continue
        target = rel_path if anchored else name
        if fnmatch.fnmatch(target, pattern):
            return True
    return False

src  = sys.argv[1]
dst  = sys.argv[2]
name = sys.argv[3]

rules = parse_gitignore(os.path.join(src, '.gitignore'))

# Package-only exclusions (committed to git but not for distribution).
rules += [
    ('utils',      True,  False),
    ('.git',       True,  False),
    ('.github',    True,  False),
    ('.distignore', False, False),
    ('.gitignore', False, False),
    ('.wordpress-org', True, False),
]

count = 0
with zipfile.ZipFile(dst, 'w', zipfile.ZIP_DEFLATED) as zf:
    for root, dirs, files in os.walk(src):
        rel_dir = os.path.relpath(root, src).replace(os.sep, '/')
        if rel_dir == '.':
            rel_dir = ''
        dirs[:] = sorted(
            d for d in dirs
            if not is_ignored((rel_dir + '/' + d).lstrip('/'), True, rules)
        )
        for f in sorted(files):
            rel_file = (rel_dir + '/' + f).lstrip('/')
            if is_ignored(rel_file, False, rules):
                continue
            zf.write(os.path.join(root, f), name + '/' + rel_file)
            count += 1

print('%d files packaged.' % count)
" "$PLUGIN_ROOT" "$ZIP_PATH" "$PLUGIN_NAME"

SIZE=$(du -sh "$ZIP_PATH" 2>/dev/null | cut -f1 || echo "?")
echo ""
echo "Done: $ZIP_PATH ($SIZE)"
echo ""
echo "Install: WordPress Admin → Plugins → Add New → Upload Plugin"
