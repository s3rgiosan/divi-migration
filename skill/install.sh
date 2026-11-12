#!/usr/bin/env bash
# install.sh — installs wp-divi-migration skill into a Claude config dir
#
# Usage:
#   bash install.sh                              # → ~/.claude (default)
#   CLAUDE_CONFIG_DIR=~/.some-other-dir bash install.sh # → custom config dir

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SKILL_NAME="wp-divi-migration"
SKILL_SRC="$SCRIPT_DIR/skills/$SKILL_NAME"
CLAUDE_CONFIG_DIR="${CLAUDE_CONFIG_DIR:-$HOME/.claude}"

echo ""
echo "Installing $SKILL_NAME..."
echo ""

mkdir -p "$CLAUDE_CONFIG_DIR/skills"
rm -rf "$CLAUDE_CONFIG_DIR/skills/$SKILL_NAME"
cp -r "$SKILL_SRC" "$CLAUDE_CONFIG_DIR/skills/$SKILL_NAME"
find "$CLAUDE_CONFIG_DIR/skills/$SKILL_NAME" -name '.DS_Store' -delete
echo "  ✓ Installed → $CLAUDE_CONFIG_DIR/skills/$SKILL_NAME"

echo ""
echo "Done! $SKILL_NAME is ready."
echo ""
echo "Try it:"
echo "  \"Migrate this Divi site to a block theme.\""
echo "  \"Convert these Divi pages to blocks and compare them with the Divi site.\""
echo ""
echo "Requires wp-design-to-block-theme from https://github.com/s3rgiosan/wp-skills :"
echo "  git clone https://github.com/s3rgiosan/wp-skills.git && bash wp-skills/wp-design-to-block-theme/install.sh"
echo ""
