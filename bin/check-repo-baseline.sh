#!/usr/bin/env bash
set -euo pipefail

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$REPO_ROOT"

fail() {
	echo -e "${RED}$*${NC}" >&2
	exit 1
}

info() {
	echo -e "${YELLOW}$*${NC}"
}

success() {
	echo -e "${GREEN}$*${NC}"
}

require_file() {
	local path="$1"

	if [ ! -f "$path" ]; then
		fail "Missing required baseline file: $path"
	fi
}

find_consumer_app_root() {
	local dir="$REPO_ROOT"

	while [ "$dir" != "/" ]; do
		if [ -f "$dir/docker-compose-dev.yml" ] && [ -x "$dir/php-cs-fixer.sh" ]; then
			echo "$dir"
			return 0
		fi

		dir="$(dirname "$dir")"
	done

	return 1
}

run_php_cs_fixer_check_locally() {
	if [ -x "$REPO_ROOT/php-cs-fixer.sh" ]; then
		"$REPO_ROOT/php-cs-fixer.sh" --config=.php-cs-fixer.php --dry-run --diff
		return $?
	fi

	if [ -x "$REPO_ROOT/tools/php-cs-fixer" ]; then
		"$REPO_ROOT/tools/php-cs-fixer" fix --dry-run --diff --config=.php-cs-fixer.php
		return $?
	fi

	if [ -x "$REPO_ROOT/vendor/bin/php-cs-fixer" ]; then
		"$REPO_ROOT/vendor/bin/php-cs-fixer" fix --dry-run --diff --config=.php-cs-fixer.php
		return $?
	fi

	if command -v php-cs-fixer >/dev/null 2>&1; then
		php-cs-fixer fix --dry-run --diff --config=.php-cs-fixer.php
		return $?
	fi

	return 1
}

run_php_cs_fixer_check_in_consumer_app() {
	local app_root="$1"
	local docker_service="${RADAPTOR_DOCKER_PHP_SERVICE:-php}"
	local repo_relative_path="${REPO_ROOT#"$app_root"}"
	local container_repo_root="/app${repo_relative_path}"

	if ! command -v docker >/dev/null 2>&1; then
		return 1
	fi

	if ! docker compose -f "$app_root/docker-compose-dev.yml" ps "$docker_service" 2>/dev/null | grep -q "Up"; then
		return 1
	fi

	docker compose -f "$app_root/docker-compose-dev.yml" exec -T "$docker_service" bash -lc \
		"cd '$container_repo_root' && php-cs-fixer fix --dry-run --diff --config=.php-cs-fixer.php"
}

run_php_cs_fixer_check() {
	local app_root

	info "Running baseline formatting check..."

	if run_php_cs_fixer_check_locally; then
		return 0
	fi

	if app_root="$(find_consumer_app_root)"; then
		info "Using consumer app container at $app_root for formatting check."

		if run_php_cs_fixer_check_in_consumer_app "$app_root"; then
			return 0
		fi
	fi

	fail "Unable to run php-cs-fixer locally or through a running consumer app container."
}

PROFILE_FILE=".repo-baseline-profile"

require_file "$PROFILE_FILE"
require_file ".githooks/install.sh"
require_file ".githooks/pre-commit"
require_file "bin/check-repo-baseline.sh"
require_file ".github/workflows/repo-checks.yml"

if [ ! -d ".git" ] && [ ! -f ".git" ]; then
	fail "Repo baseline check requires a Git worktree at $REPO_ROOT"
fi

if [ -f ".github/workflows/php-cs-fixer.yml" ]; then
	fail "Legacy workflow still present: .github/workflows/php-cs-fixer.yml"
fi

PROFILE="$(tr -d '[:space:]' < "$PROFILE_FILE")"

case "$PROFILE" in
	generic|php-generic|php-consumer-app)
		;;
	*)
		fail "Unsupported baseline profile in $PROFILE_FILE: $PROFILE"
		;;
esac

chmod +x .githooks/install.sh .githooks/pre-commit bin/check-repo-baseline.sh
./.githooks/install.sh >/dev/null

if [ "$(git config --get core.hooksPath || true)" != ".githooks" ]; then
	fail "core.hooksPath is not configured to .githooks"
fi

if [ "$PROFILE" = "generic" ]; then
	success "Repo baseline check passed for profile: $PROFILE"
	exit 0
fi

require_file ".php-cs-fixer.php"
run_php_cs_fixer_check

success "Repo baseline check passed for profile: $PROFILE"
