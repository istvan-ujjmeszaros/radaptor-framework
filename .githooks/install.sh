#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
GIT_ENTRY="$PROJECT_ROOT/.git"

if [ ! -f "$SCRIPT_DIR/pre-commit" ]; then
	echo "✗ pre-commit hook not found"
	exit 1
fi

if [ ! -d "$GIT_ENTRY" ] && [ ! -f "$GIT_ENTRY" ]; then
	echo "Skipping git hook installation: .git entry not found in $PROJECT_ROOT."
	exit 0
fi

if ! command -v git >/dev/null 2>&1; then
	echo "Skipping git hook installation: git binary not available."
	exit 0
fi

if ! git -C "$PROJECT_ROOT" rev-parse --show-toplevel >/dev/null 2>&1; then
	echo "Skipping git hook installation: unable to resolve Git worktree."
	exit 0
fi

chmod +x "$SCRIPT_DIR/pre-commit"

if git -C "$PROJECT_ROOT" config core.hooksPath .githooks; then
	echo "✓ core.hooksPath configured -> .githooks"
else
	echo "✗ failed to configure core.hooksPath"
	exit 1
fi
