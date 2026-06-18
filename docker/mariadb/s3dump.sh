#!/bin/bash
# S3 (MinIO) transfer layer for MariaDB dumps. Runs on the HOST.
#
# The mariadb container has no S3 creds/tools, so it only produces the gz
# (backup/dump.sql.gz). This script owns the S3 side: push that gz under a NEW
# timestamped key, pull a chosen key back, or list the newest objects.
#
# Bucket pf-dump is VERSIONED and the credential has Get/List/Put but NO Delete,
# so every push is a NEW key — we never overwrite/delete remote objects.
#
# Subcommands:
#   push                 upload backup/dump.sql.gz -> ${APP_ENV}/matrix-<UTC ts>.sql.gz, echo key
#   pull <key>           download <prefix>/<key> -> backup/dump.sql.gz
#   list [env]           list newest 10 object KEYS under <env>/ (default env = APP_ENV)
#
# Env (exported by the Makefile from .env / .env.local):
#   S3_ENDPOINT S3_KEY S3_SECRET S3_DUMP_BUCKET APP_ENV
#   S3_PULL_ENV  optional override of the prefix for list/pull (e.g. dev pulling production/)
set -o nounset
set -o errexit
set -o pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MC="$SCRIPT_DIR/mc"
BACKUP_FILE="$SCRIPT_DIR/backup/dump.sql.gz"
ALIAS="pfdump"

# Keep the credential out of the shared host's ~/.mc — ephemeral per run.
export MC_CONFIG_DIR="$(mktemp -d)"
trap 'rm -rf "$MC_CONFIG_DIR"' EXIT

err() { echo "Error: $*" >&2; exit 1; }

requireEnv() {
    [[ -n "${S3_ENDPOINT:-}" ]]    || err "S3_ENDPOINT is empty (run via make, or check .env.local)"
    [[ -n "${S3_KEY:-}" ]]         || err "S3_KEY is empty (run via make, or check .env.local)"
    [[ -n "${S3_SECRET:-}" ]]      || err "S3_SECRET is empty (run via make, or check .env.local)"
    [[ -n "${S3_DUMP_BUCKET:-}" ]] || err "S3_DUMP_BUCKET is empty"
    [[ -n "${APP_ENV:-}" ]]        || err "APP_ENV is empty"
}

# /usr/bin/mc on this host is GNU Midnight Commander, NOT the MinIO client —
# never resolve via PATH. Always use the project-local binary, download if absent.
ensureMc() {
    if [[ ! -x "$MC" ]]; then
        echo "MinIO client not found, downloading -> $MC ..." >&2
        wget -nv --cache=off "https://dl.min.io/client/mc/release/linux-amd64/mc" -O "$MC"
        chmod +x "$MC"
    fi
}

ensureAlias() {
    ensureMc
    requireEnv
    # path-style is mc's default; no extra flag needed for MinIO path-style.
    "$MC" alias set "$ALIAS" "$S3_ENDPOINT" "$S3_KEY" "$S3_SECRET" >/dev/null
}

# Prefix to read from: explicit env override > APP_ENV.
pullEnv() { echo "${S3_PULL_ENV:-$APP_ENV}"; }

cmdPush() {
    ensureAlias
    [[ -f "$BACKUP_FILE" ]] || err "no dump to push: $BACKUP_FILE missing (run create_dump.sh first)"
    local key="${APP_ENV}/matrix-$(date -u +%Y%m%d-%H%M%S).sql.gz"
    "$MC" cp "$BACKUP_FILE" "${ALIAS}/${S3_DUMP_BUCKET}/${key}" >&2
    echo "$key"
}

# Newest 10 dump keys under <env>/. Only matrix-*.sql.gz (ignore foreign objects).
# Keys are matrix-YYYYMMDD-HHMMSS so lexical == chronological. Capture before
# slicing: head/tail closing the pipe early + pipefail would abort otherwise.
cmdList() {
    ensureAlias
    local env="${1:-$(pullEnv)}"
    local all
    all="$("$MC" ls --json "${ALIAS}/${S3_DUMP_BUCKET}/${env}/" \
        | jq -r '.key // empty' | grep -E '^matrix-.*\.sql\.gz$' | sort || true)"
    [[ -n "$all" ]] || return 0
    echo "$all" | tail -n 10
}

cmdPull() {
    ensureAlias
    local key="${1:-}"
    [[ -n "$key" ]] || err "pull needs a key"
    local env
    env="$(pullEnv)"
    mkdir -p "$(dirname "$BACKUP_FILE")"
    "$MC" cp "${ALIAS}/${S3_DUMP_BUCKET}/${env}/${key}" "$BACKUP_FILE" >&2
    echo "$BACKUP_FILE"
}

case "${1:-}" in
    push) shift; cmdPush "$@" ;;
    pull) shift; cmdPull "$@" ;;
    list) shift; cmdList "$@" ;;
    *) err "usage: s3dump.sh {push|pull <key>|list [env]}" ;;
esac
