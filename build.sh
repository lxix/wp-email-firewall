#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")"

# WordPress installs the plugin into the folder at the root of the zip. Without one, it names the
# folder after the zip file, so a renamed zip would install the plugin in the wrong folder.
# The folder stays after the build: the release workflow checks it and deploys it to WordPress.org.
build_dir=.out/lxix-email-firewall

# zip updates an existing archive instead of replacing it, so stale files would stay in it.
rm -rf "$build_dir" .out/lxix-email-firewall.zip
mkdir -p "$build_dir"

# languages/ only holds the .po sources to import into translate.wordpress.org: WordPress delivers the
# translations as language packs, and the plugin doesn't load translation files of its own.
tar -c \
  --exclude=./.out --exclude=./languages --exclude=./test-env --exclude=./.wordpress-org --exclude=./CLAUDE.md --exclude=./build.sh \
  --exclude='.git*' --exclude=.claude --exclude=.idea --exclude=node_modules --exclude=.DS_Store \
  . | tar -x -C "$build_dir"

(cd .out && zip -rq lxix-email-firewall.zip lxix-email-firewall)
