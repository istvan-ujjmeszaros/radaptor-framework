#!/bin/bash
set -euo pipefail

SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
PROJECT_ROOT="$( cd "$SCRIPT_DIR/.." && pwd )"

if [ ! -f "$SCRIPT_DIR/pre-commit" ]; then
	echo "✗ pre-commit hook not found"
	exit 1
fi

if ! command -v git >/dev/null 2>&1; then
	echo "Skipping git hook installation: git binary not available."
	exit 0
fi

if ! git -C "$PROJECT_ROOT" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
	echo "Skipping git hook installation: not inside a Git worktree."
	exit 0
fi

chmod +x "$SCRIPT_DIR/pre-commit"
git -C "$PROJECT_ROOT" config core.hooksPath .githooks

echo "✓ core.hooksPath configured -> .githooks"
