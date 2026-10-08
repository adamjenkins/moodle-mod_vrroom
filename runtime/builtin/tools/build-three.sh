#!/usr/bin/env bash
# This file is part of Moodle - http://moodle.org/
#
# Moodle is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 3 of the License, or
# (at your option) any later version.
#
# Moodle is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with Moodle.  If not, see <http://www.gnu.org/licenses/>.
#
# Builds runtime/builtin/amd/src/three.js: three.js core plus the addons GLTFLoader, OrbitControls,
# TransformControls and XRHandModelFactory as one ES module, which Moodle's grunt then turns into an
# AMD module like any other file in amd/src.
#
# XRControllerModelFactory is deliberately NOT bundled: it downloads controller models from a CDN
# by default, and VR room makes no external calls. Controllers are drawn procedurally and hands use
# XRHandModelFactory's 'spheres' profile, which fetches nothing.
#
# Usage: runtime/builtin/tools/build-three.sh
#   The npm work happens in a temporary directory (mktemp -d under ${TMPDIR:-/tmp}) that is
#   removed on exit. Nothing is written into the repository except amd/src/three.js.
#   After running it, update thirdpartylibs.xml if the version changed, then rebuild amd/build.
#
# @copyright  2026 Adam Jenkins <adam@wisecat.net>
# @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later

set -euo pipefail

THREE_VERSION="0.186.1"
ESBUILD_VERSION="0.28.2"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OUT="$(cd "${SCRIPT_DIR}/.." && pwd)/amd/src/three.js"

WORK="$(mktemp -d "${TMPDIR:-/tmp}/vrroom-three-build.XXXXXX")"
cleanup() {
    rm -rf "${WORK}"
}
trap cleanup EXIT

cd "${WORK}"
npm init -y >/dev/null
npm install --prefer-offline --no-audit --no-fund --save-exact "three@${THREE_VERSION}" "esbuild@${ESBUILD_VERSION}" >/dev/null

cat > "${WORK}/entry.js" <<'EOF'
export * from 'three';
export {GLTFLoader} from 'three/examples/jsm/loaders/GLTFLoader.js';
export {OrbitControls} from 'three/examples/jsm/controls/OrbitControls.js';
export {TransformControls} from 'three/examples/jsm/controls/TransformControls.js';
export {XRHandModelFactory} from 'three/examples/jsm/webxr/XRHandModelFactory.js';
EOF

npx --no-install esbuild "${WORK}/entry.js" --bundle --format=esm --target=es2020 \
    --legal-comments=inline --outfile="${WORK}/bundle.js" --log-level=warning

LICENSELINE="$(grep -m1 -i 'copyright' "${WORK}/node_modules/three/LICENSE" || true)"

{
    echo "/* eslint-disable */"
    echo "/**"
    echo " * three.js ${THREE_VERSION} bundled as one ES module: core, GLTFLoader, OrbitControls,"
    echo " * TransformControls and XRHandModelFactory."
    echo " *"
    echo " * GENERATED FILE - do not edit. Rebuild with runtime/builtin/tools/build-three.sh"
    echo " * (esbuild ${ESBUILD_VERSION}, --format=esm --target=es2020)."
    echo " *"
    echo " * The MIT License. ${LICENSELINE}"
    echo " * https://github.com/mrdoob/three.js"
    echo " */"
    cat "${WORK}/bundle.js"
} > "${WORK}/three.js"

mv "${WORK}/three.js" "${OUT}"
echo "Wrote ${OUT}"
echo "Licence line: ${LICENSELINE}"
