#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")"

msgfmt languages/wp-email-firewall-hu_HU.po -o languages/wp-email-firewall-hu_HU.mo
msgfmt languages/wp-email-firewall-en_US.po -o languages/wp-email-firewall-en_US.mo

# WordPress installs the plugin into the folder at the root of the zip. Without one, it names the
# folder after the zip file, so a renamed zip would install the plugin in the wrong folder.
staging=.out/wp-email-firewall

# zip updates an existing archive instead of replacing it, so stale files would stay in it.
rm -rf "$staging" .out/wp-email-firewall.zip
mkdir -p "$staging"

tar -c \
  --exclude=./.out --exclude=./test-env --exclude=./CLAUDE.md --exclude=./build.sh \
  --exclude='.git*' --exclude=.claude --exclude=.idea --exclude=node_modules --exclude=.DS_Store \
  . | tar -x -C "$staging"

(cd .out && zip -rq wp-email-firewall.zip wp-email-firewall)
rm -rf "$staging"
