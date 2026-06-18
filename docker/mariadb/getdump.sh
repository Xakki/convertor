#!/bin/bash
# Pull a MariaDB dump from S3 (MinIO bucket pf-dump) into backup/dump.sql.gz.
# Runs on the HOST. The S3 mechanics live in s3dump.sh (DRY); this is the UX:
# list newest objects, let the user choose (default = newest), download.
#
# Pull a DIFFERENT env's prefix (dev can read all prefixes of pf-dump):
#   S3_PULL_ENV=production ./getdump.sh
#   ./getdump.sh production            # 1st arg also sets the prefix
#
# Non-interactive (no TTY, e.g. CI): silently takes the newest object.
set -o nounset
set -o pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
S3="$SCRIPT_DIR/s3dump.sh"
BACKUP_FILE="$SCRIPT_DIR/backup/dump.sql.gz"

# Allow overriding the source prefix via 1st arg (feeds s3dump.sh via S3_PULL_ENV).
if [[ -n "${1:-}" ]]; then
    export S3_PULL_ENV="$1"
fi

# bashlibs.sh provides myAskVal; auto-download like the original (it is gitignored).
srcLib="https://raw.githubusercontent.com/Xakki/kvm.scripts/master/src/bashlibs.sh"
if ! [[ -f "$SCRIPT_DIR/bashlibs.sh" ]]; then
    wget -nv --cache=off "$srcLib" -O "$SCRIPT_DIR/bashlibs.sh"
    chmod 0744 "$SCRIPT_DIR/bashlibs.sh"
fi
. "$SCRIPT_DIR/bashlibs.sh"

echo
echo "Fetching dump list from S3 (${S3_PULL_ENV:-$APP_ENV}/) ..."
# Newest 10 keys, oldest-first; reverse so the menu shows newest at the top.
mapfile -t keys < <("$S3" list | tac)
if [[ ${#keys[@]} -eq 0 ]]; then
    echo "Error: no dumps found under ${S3_PULL_ENV:-$APP_ENV}/ in bucket pf-dump" >&2
    exit 1
fi

newest="${keys[0]}"
choice="$newest"

if [[ -t 0 ]]; then
    echo
    echo "Available dumps (newest first):"
    for i in "${!keys[@]}"; do
        printf "  %2d) %s\n" "$((i + 1))" "${keys[$i]}"
    done
    echo
    pick=1
    myAskVal "Choose number (default = 1, newest)" pick
    if [[ "$pick" =~ ^[0-9]+$ ]] && (( pick >= 1 && pick <= ${#keys[@]} )); then
        choice="${keys[$((pick - 1))]}"
    else
        echo "Invalid choice, using newest."
        choice="$newest"
    fi
else
    echo "No TTY — using newest: $newest"
fi

echo
# Drop a stale local dump only now that we have a remote to fetch — a failed
# list above must never destroy an existing backup/dump.sql.gz.
rm -f "$BACKUP_FILE" "${BACKUP_FILE%.gz}"
echo "Downloading: $choice"
date
"$S3" pull "$choice" >/dev/null
date

if [[ ! -s "$BACKUP_FILE" ]]; then
    echo "Error: download failed or empty: $BACKUP_FILE" >&2
    exit 1
fi
echo "Done -> $BACKUP_FILE"
echo
