#!/usr/bin/env bash
set -euo pipefail

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)
SYNC="$ROOT/tools/agent-skills/sync-convertor-api.sh"
CANONICAL="$ROOT/app-symfony/public/convertor-api"
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
TESTS=0

fail() {
  printf 'not ok - %s\n' "$1" >&2
  exit 1
}

assert_success() {
  local name=$1
  shift
  "$@" >"$TMP/stdout" 2>"$TMP/stderr" || {
    sed 's/^/# /' "$TMP/stderr" >&2
    fail "$name"
  }
  TESTS=$((TESTS + 1))
  printf 'ok %d - %s\n' "$TESTS" "$name"
}

assert_failure() {
  local name=$1
  shift
  if "$@" >"$TMP/stdout" 2>"$TMP/stderr"; then
    fail "$name"
  fi
  TESTS=$((TESTS + 1))
  printf 'ok %d - %s\n' "$TESTS" "$name"
}

assert_failure_before_timeout() {
  local name=$1
  shift
  local status
  set +e
  timeout 2 "$@" >"$TMP/stdout" 2>"$TMP/stderr"
  status=$?
  set -e
  [[ $status -ne 0 && $status -ne 124 ]] || fail "$name"
  TESTS=$((TESTS + 1))
  printf 'ok %d - %s\n' "$TESTS" "$name"
}

FAKE_BIN="$TMP/bin"
mkdir -p "$FAKE_BIN"
cat >"$FAKE_BIN/skills-ref" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
[[ ${SYNC_TEST_VALIDATOR_FAIL:-0} != 1 ]]
[[ $1 == validate && -f $2/SKILL.md && ! -L $2/SKILL.md ]]
grep -q '^name: convertor-api$' "$2/SKILL.md"
SH
chmod +x "$FAKE_BIN/skills-ref"

cat >"$FAKE_BIN/curl" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
headers=
output=
max_filesize=
args_file=${SYNC_TEST_CURL_ARGS:?}
printf '%s\n' "$@" >"$args_file"
while (($#)); do
  case "$1" in
    --dump-header|--output|--connect-timeout|--max-time|--max-filesize|--proto|--proto-redir)
      key=$1
      value=$2
      case "$key" in
        --dump-header) headers=$value ;;
        --output) output=$value ;;
        --max-filesize) max_filesize=$value ;;
      esac
      shift 2
      ;;
    --fail|--silent|--show-error|--location) shift ;;
    *) shift ;;
  esac
done
case ${SYNC_TEST_CURL_SCENARIO:-ok} in
  ok)
    printf 'HTTP/1.1 200 OK\r\nContent-Type: text/markdown; charset=utf-8\r\n\r\n' >"$headers"
    cp "$SYNC_TEST_PAYLOAD" "$output"
    ;;
  status)
    printf 'HTTP/1.1 404 Not Found\r\nContent-Type: text/markdown\r\n\r\n' >"$headers"
    cp "$SYNC_TEST_PAYLOAD" "$output"
    ;;
  content_type)
    printf 'HTTP/1.1 200 OK\r\nContent-Type: text/html\r\n\r\n' >"$headers"
    cp "$SYNC_TEST_PAYLOAD" "$output"
    ;;
  inherited_header)
    printf 'HTTP/1.1 302 Found\r\nContent-Type: text/markdown\r\nLocation: https://example.test/final\r\n\r\nHTTP/1.1 200 OK\r\n\r\n' >"$headers"
    cp "$SYNC_TEST_PAYLOAD" "$output"
    ;;
  downgrade)
    grep -Fxq '=https' "$args_file" || {
      printf 'HTTP/1.1 200 OK\r\nContent-Type: text/markdown\r\n\r\n' >"$headers"
      cp "$SYNC_TEST_PAYLOAD" "$output"
      exit 0
    }
    exit 47
    ;;
  oversize)
    [[ -n $max_filesize ]] && exit 63
    printf 'HTTP/1.1 200 OK\r\nContent-Type: text/markdown\r\n\r\n' >"$headers"
    cp "$SYNC_TEST_PAYLOAD" "$output"
    ;;
esac
SH
chmod +x "$FAKE_BIN/curl"

export PATH="$FAKE_BIN:$PATH"
export SYNC_TEST_PAYLOAD="$CANONICAL/SKILL.md"
export SYNC_TEST_CURL_ARGS="$TMP/curl-args"

HOME="$TMP/home"
export HOME
mkdir -p "$HOME/.claude/skills"
TARGET="$HOME/.claude/skills/convertor-api"

assert_failure 'validator is mandatory' env SKILLS_REF_BIN="$TMP/missing-skills-ref" "$SYNC" --source-repo "$CANONICAL" --target "$TARGET" --dry-run
assert_success 'repo dry-run does not mutate' "$SYNC" --source-repo "$CANONICAL" --target "$TARGET" --dry-run
[[ ! -e $TARGET && ! -L $TARGET ]] || fail 'repo dry-run created target'

AUX_REPO="$TMP/aux-repo"
mkdir "$AUX_REPO"
cp "$CANONICAL/SKILL.md" "$AUX_REPO/SKILL.md"
printf 'unexpected\n' >"$AUX_REPO/reference.md"
assert_failure 'repo source rejects auxiliary files' "$SYNC" --source-repo "$AUX_REPO" --target "$TARGET"
rm "$AUX_REPO/reference.md"
rm "$AUX_REPO/SKILL.md"
ln -s "$CANONICAL/SKILL.md" "$AUX_REPO/SKILL.md"
assert_failure 'repo source rejects symlinked SKILL.md' "$SYNC" --source-repo "$AUX_REPO" --target "$TARGET"

assert_success 'repo source installs versioned symlink' "$SYNC" --source-repo "$CANONICAL" --target "$TARGET"
[[ -L $TARGET ]] || fail 'repo target is not a symlink'
VERSION_ONE=$(readlink -f "$TARGET")
[[ $VERSION_ONE == "$HOME/.claude/skills/.convertor-api.versions/"* ]] || fail 'repo target is not versioned under loader root'
[[ -f $VERSION_ONE/SKILL.md && ! -L $VERSION_ONE/SKILL.md ]] || fail 'versioned SKILL.md is unsafe'
assert_success 'repo check passes' "$SYNC" --source-repo "$CANONICAL" --target "$TARGET" --check
assert_success 'repo install is idempotent' "$SYNC" --source-repo "$CANONICAL" --target "$TARGET"
[[ $(readlink -f "$TARGET") == "$VERSION_ONE" ]] || fail 'idempotent repo install switched version'

UNKNOWN="$HOME/.claude/skills/unknown"
mkdir "$UNKNOWN"
printf 'keep\n' >"$UNKNOWN/keep"
assert_failure 'unknown directory is rejected' "$SYNC" --source-repo "$CANONICAL" --target "$UNKNOWN"
[[ -f $UNKNOWN/keep ]] || fail 'unknown target was modified'
assert_failure 'replace never replaces unknown directory' "$SYNC" --source-repo "$CANONICAL" --target "$UNKNOWN" --replace
[[ -d $UNKNOWN && -f $UNKNOWN/keep && ! -L $UNKNOWN ]] || fail 'replace modified unknown directory'
grep -Fq 'move it outside the loader root manually, then retry' "$TMP/stderr" || fail 'unknown-target guidance is not fail-closed'

UNKNOWN_FILE="$HOME/.claude/skills/unknown-file"
printf 'keep\n' >"$UNKNOWN_FILE"
assert_failure 'replace never replaces unknown file' "$SYNC" --source-repo "$CANONICAL" --target "$UNKNOWN_FILE" --replace
[[ -f $UNKNOWN_FILE && ! -L $UNKNOWN_FILE && $(<"$UNKNOWN_FILE") == keep ]] || fail 'replace modified unknown file'

PLAIN_LINK="$HOME/.claude/skills/plain-link"
ln -s "$UNKNOWN" "$PLAIN_LINK"
assert_failure 'unknown symlink requires replace' "$SYNC" --source-repo "$CANONICAL" --target "$PLAIN_LINK"
assert_success 'replace atomically switches a regular symlink' "$SYNC" --source-repo "$CANONICAL" --target "$PLAIN_LINK" --replace
PLAIN_LINK_VERSION=$(readlink -f "$PLAIN_LINK")
[[ -L $PLAIN_LINK && $PLAIN_LINK_VERSION == "$HOME/.claude/skills/.convertor-api.versions/plain-link-"* ]] || fail 'regular symlink replacement did not activate managed version'
[[ -d $UNKNOWN && -f $UNKNOWN/keep ]] || fail 'regular symlink replacement touched its previous destination'

KILL_TARGET="$HOME/.claude/skills/kill-unknown"
mkdir "$KILL_TARGET"
printf 'keep\n' >"$KILL_TARGET/keep"
KILL_MV_BIN="$TMP/kill-mv-bin"
mkdir "$KILL_MV_BIN"
cat >"$KILL_MV_BIN/mv" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
if [[ " $* " == *" $SYNC_KILL_TARGET "* ]]; then
  "$REAL_MV" "$@"
  kill -KILL "$PPID"
  exit 137
fi
exec "$REAL_MV" "$@"
SH
chmod +x "$KILL_MV_BIN/mv"
assert_failure 'SIGKILL cannot expose an unknown-target replacement gap' env REAL_MV="$(command -v mv)" SYNC_KILL_TARGET="$KILL_TARGET" PATH="$KILL_MV_BIN:$PATH" "$SYNC" --source-repo "$CANONICAL" --target "$KILL_TARGET" --replace
[[ -d $KILL_TARGET && -f $KILL_TARGET/keep && ! -L $KILL_TARGET ]] || fail 'SIGKILL path modified unknown target'

RESERVED_HOME="$TMP/reserved-home"
RESERVED_TARGET="$RESERVED_HOME/.claude/skills/.convertor-api.versions"
mkdir "$RESERVED_HOME"
assert_failure_before_timeout 'reserved version-root basename fails without self-reference' env HOME="$RESERVED_HOME" PATH="$PATH" "$SYNC" --source-repo "$CANONICAL" --target "$RESERVED_TARGET"
[[ ! -e $RESERVED_HOME/.claude && ! -L $RESERVED_HOME/.claude ]] || fail 'reserved target created loader directories'
grep -Fq 'reserved installer basename' "$TMP/stderr" || fail 'reserved-target error is unclear'

for reserved_name in .convertor-api.link.fixture .convertor-api.migration.fixture .stage.fixture .source-kind .source-url .source-repo .sha256; do
  assert_failure "reserved installer target is rejected: $reserved_name" "$SYNC" --source-repo "$CANONICAL" --target "$HOME/.claude/skills/$reserved_name"
done

OUTSIDE="$TMP/outside/convertor-api"
mkdir -p "$(dirname "$OUTSIDE")"
assert_failure 'out-of-root target is rejected' "$SYNC" --source-repo "$CANONICAL" --target "$OUTSIDE" --replace

SYMLINK_HOME="$TMP/symlink-home"
mkdir -p "$TMP/redirected/.claude/skills"
ln -s "$TMP/redirected" "$SYMLINK_HOME"
assert_failure 'symlinked HOME ancestor is rejected' env HOME="$SYMLINK_HOME" PATH="$PATH" "$SYNC" --source-repo "$CANONICAL" --target "$SYMLINK_HOME/.claude/skills/convertor-api" --replace

mkdir -p "$HOME/.claude/real-skills"
rm -rf "$HOME/.claude/skills"
ln -s "$HOME/.claude/real-skills" "$HOME/.claude/skills"
assert_failure 'symlinked loader-root ancestor is rejected' "$SYNC" --source-repo "$CANONICAL" --target "$HOME/.claude/skills/convertor-api" --replace
rm "$HOME/.claude/skills"
mv "$HOME/.claude/real-skills" "$HOME/.claude/skills"
TARGET="$HOME/.claude/skills/convertor-api"
chmod 777 "$HOME/.claude/skills"
assert_failure 'group-writable loader root is rejected' "$SYNC" --source-repo "$CANONICAL" --target "$TARGET" --replace
chmod 755 "$HOME/.claude/skills"

assert_failure 'URL userinfo is rejected' "$SYNC" --source-url 'https://user@example.test/SKILL.md' --target "$TARGET" --dry-run
assert_failure 'URL query is rejected' "$SYNC" --source-url 'https://example.test/SKILL.md?token=value' --target "$TARGET" --dry-run
assert_failure 'URL fragment is rejected' "$SYNC" --source-url 'https://example.test/SKILL.md#fragment' --target "$TARGET" --dry-run
assert_failure 'non-HTTPS URL is rejected' "$SYNC" --source-url 'http://example.test/SKILL.md' --target "$TARGET" --dry-run

URL_TARGET="$HOME/.claude/skills/url-api"
assert_success 'URL source installs validated versioned copy' "$SYNC" --source-url 'https://example.test/SKILL.md' --target "$URL_TARGET"
[[ -L $URL_TARGET ]] || fail 'URL target is not a symlink'
URL_VERSION_ONE=$(readlink -f "$URL_TARGET")
[[ $URL_VERSION_ONE == "$HOME/.claude/skills/.convertor-api.versions/"* ]] || fail 'URL target is not versioned'
for required in --proto --proto-redir --connect-timeout --max-time --max-filesize; do
  grep -Fxq -- "$required" "$SYNC_TEST_CURL_ARGS" || fail "curl missing $required"
done
[[ $(grep -Fxc '=https' "$SYNC_TEST_CURL_ARGS") -eq 2 ]] || fail 'curl HTTPS protocol constraints differ'
assert_success 'URL check passes' "$SYNC" --source-url 'https://example.test/SKILL.md' --target "$URL_TARGET" --check
assert_success 'URL reinstall is idempotent' "$SYNC" --source-url 'https://example.test/SKILL.md' --target "$URL_TARGET"
[[ $(readlink -f "$URL_TARGET") == "$URL_VERSION_ONE" ]] || fail 'URL reinstall switched identical version'
printf '%s\n' 'https://other.example.test/SKILL.md' >"$URL_VERSION_ONE/.source-url"
assert_failure 'tampered source URL marker requires replace' "$SYNC" --source-url 'https://example.test/SKILL.md' --target "$URL_TARGET"
printf '%s\n' 'https://example.test/SKILL.md' >"$URL_VERSION_ONE/.source-url"
rm "$URL_VERSION_ONE/.source-url"
ln -s "$URL_VERSION_ONE/.source-kind" "$URL_VERSION_ONE/.source-url"
assert_failure 'symlinked source URL marker requires replace' "$SYNC" --source-url 'https://example.test/SKILL.md' --target "$URL_TARGET"
rm "$URL_VERSION_ONE/.source-url"
printf '%s\n' 'https://example.test/SKILL.md' >"$URL_VERSION_ONE/.source-url"
chmod 600 "$URL_VERSION_ONE/.source-url"

SYNC_TEST_CURL_SCENARIO=status assert_failure 'final non-200 status is rejected' "$SYNC" --source-url 'https://example.test/status.md' --target "$HOME/.claude/skills/status"
SYNC_TEST_CURL_SCENARIO=content_type assert_failure 'invalid final Content-Type is rejected' "$SYNC" --source-url 'https://example.test/content.md' --target "$HOME/.claude/skills/content"
SYNC_TEST_CURL_SCENARIO=inherited_header assert_failure 'redirect Content-Type is not inherited' "$SYNC" --source-url 'https://example.test/inherited.md' --target "$HOME/.claude/skills/inherited"
SYNC_TEST_CURL_SCENARIO=downgrade assert_failure 'HTTPS redirect downgrade fails closed' "$SYNC" --source-url 'https://example.test/downgrade.md' --target "$HOME/.claude/skills/downgrade"
SYNC_TEST_CURL_SCENARIO=oversize assert_failure 'oversize download fails closed' "$SYNC" --source-url 'https://example.test/oversize.md' --target "$HOME/.claude/skills/oversize"

MALFORMED="$TMP/malformed.md"
printf '%s\n' 'not a skill' >"$MALFORMED"
SYNC_TEST_PAYLOAD="$MALFORMED" assert_failure 'malformed URL skill is rejected' "$SYNC" --source-url 'https://example.test/malformed.md' --target "$HOME/.claude/skills/malformed"

SECRET="$TMP/secret.md"
cp "$CANONICAL/SKILL.md" "$SECRET"
secret_head="eyJ$(printf '%s%s' 'syntheticHeader' 'Part1234567890')"
secret_body=$(printf '%s%s' 'syntheticPayload' 'Part1234567890')
secret_tail=$(printf '%s%s' 'syntheticSignature' 'Part1234567890')
printf 'token: %s.%s.%s\n' "$secret_head" "$secret_body" "$secret_tail" >>"$SECRET"
SYNC_TEST_PAYLOAD="$SECRET" assert_failure 'secret-bearing URL skill is rejected' "$SYNC" --source-url 'https://example.test/secret.md' --target "$HOME/.claude/skills/secret"

printf 'tampered\n' >>"$URL_VERSION_ONE/SKILL.md"
assert_failure 'tampered managed install requires replace' "$SYNC" --source-url 'https://example.test/SKILL.md' --target "$URL_TARGET"
assert_success 'explicit replace recovers tampered managed install' "$SYNC" --source-url 'https://example.test/SKILL.md' --target "$URL_TARGET" --replace
URL_VERSION_TWO=$(readlink -f "$URL_TARGET")
[[ $URL_VERSION_TWO != "$URL_VERSION_ONE" ]] || fail 'tampered version was reused'

SPOOF="$HOME/.claude/skills/spoof"
mkdir "$SPOOF"
cp "$CANONICAL/SKILL.md" "$SPOOF/SKILL.md"
printf '%s\n' 'https://example.test/SKILL.md' >"$SPOOF/.source-url"
sha256sum "$SPOOF/SKILL.md" | cut -d' ' -f1 >"$SPOOF/.sha256"
assert_failure 'spoofed marker directory is rejected as unknown' "$SYNC" --source-url 'https://example.test/SKILL.md' --target "$SPOOF"

ACTIVE_BEFORE=$(readlink "$URL_TARGET")
FAIL_MV_BIN="$TMP/fail-mv-bin"
mkdir "$FAIL_MV_BIN"
cat >"$FAIL_MV_BIN/mv" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
if [[ ${1:-} == -Tf && ${3:-} == *'.convertor-api.link.'* ]]; then
  exit 1
fi
exec "$REAL_MV" "$@"
SH
chmod +x "$FAIL_MV_BIN/mv"
CHANGED="$TMP/changed.md"
cp "$CANONICAL/SKILL.md" "$CHANGED"
printf '\n<!-- changed fixture -->\n' >>"$CHANGED"
assert_failure 'failed managed atomic switch preserves active target' env REAL_MV="$(command -v mv)" PATH="$FAIL_MV_BIN:$PATH" SYNC_TEST_PAYLOAD="$CHANGED" "$SYNC" --source-url 'https://example.test/SKILL.md' --target "$URL_TARGET"
[[ -L $URL_TARGET && $(readlink "$URL_TARGET") == "$ACTIVE_BEFORE" ]] || fail 'failed managed switch changed active target'

COLLISION_LINK="$HOME/.claude/skills/.convertor-api.link.collision"
ln -s "$URL_VERSION_TWO" "$COLLISION_LINK"
assert_success 'unrelated temp-link collision is not reused' "$SYNC" --source-url 'https://example.test/SKILL.md' --target "$URL_TARGET"
[[ -L $COLLISION_LINK ]] || fail 'pre-existing collision was touched'

CREDENTIAL="$HOME/.config/convertor-api/token"
mkdir -p "$(dirname "$CREDENTIAL")"
printf 'credential-must-remain-untouched\n' >"$CREDENTIAL"
before=$(sha256sum "$CREDENTIAL" | cut -d' ' -f1)
assert_success 'install leaves credential overlay untouched' "$SYNC" --source-url 'https://example.test/SKILL.md' --target "$URL_TARGET" --replace
after=$(sha256sum "$CREDENTIAL" | cut -d' ' -f1)
[[ $before == "$after" ]] || fail 'credential overlay changed'

printf '1..%d\n' "$TESTS"
