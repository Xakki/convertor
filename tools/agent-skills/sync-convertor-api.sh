#!/usr/bin/env bash
set -euo pipefail

usage() {
  printf '%s\n' 'Usage: sync-convertor-api.sh (--source-repo DIR | --source-url URL) [--target DIR] [--dry-run | --check] [--replace]'
  printf '%s\n' '  --replace  atomically replace an existing symlink or bypass a version collision'
  printf '%s\n' '             (regular files and directories are never replaced)'
}

die() {
  printf 'sync-convertor-api: %s\n' "$1" >&2
  exit 1
}

SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
DEFAULT_REPO=$(cd "$SCRIPT_DIR/../.." && pwd)/app-symfony/public/convertor-api
SOURCE_KIND=
SOURCE=
TARGET=${HOME:?HOME is required}/.claude/skills/convertor-api
MODE=install
REPLACE=0
SKILLS_REF_BIN=${SKILLS_REF_BIN:-skills-ref}
CURRENT_UID=$(id -u)
MAX_DOWNLOAD_BYTES=262144
CONNECT_TIMEOUT_SECONDS=10
MAX_TIME_SECONDS=30

while (($#)); do
  case "$1" in
    --source-repo)
      (($# >= 2)) || die '--source-repo requires a directory'
      [[ -z $SOURCE_KIND ]] || die 'choose exactly one source'
      SOURCE_KIND=repo
      SOURCE=$2
      shift 2
      ;;
    --source-url)
      (($# >= 2)) || die '--source-url requires a URL'
      [[ -z $SOURCE_KIND ]] || die 'choose exactly one source'
      SOURCE_KIND=url
      SOURCE=$2
      shift 2
      ;;
    --target)
      (($# >= 2)) || die '--target requires a directory'
      TARGET=$2
      shift 2
      ;;
    --dry-run) MODE=dry-run; shift ;;
    --check) MODE=check; shift ;;
    --replace) REPLACE=1; shift ;;
    --help|-h) usage; exit 0 ;;
    *) die "unknown option: $1" ;;
  esac
done

[[ -n $SOURCE_KIND ]] || {
  SOURCE_KIND=repo
  SOURCE=$DEFAULT_REPO
}
[[ -x $SKILLS_REF_BIN ]] || command -v "$SKILLS_REF_BIN" >/dev/null 2>&1 || die 'official skills-ref validator is required'

sha_file() {
  sha256sum "$1" | cut -d' ' -f1
}

owned_regular_file() {
  local file=$1
  [[ -f $file && ! -L $file && $(stat -c %u -- "$file") == "$CURRENT_UID" ]]
}

owned_safe_directory() {
  local dir=$1 mode
  [[ -d $dir && ! -L $dir && $(stat -c %u -- "$dir") == "$CURRENT_UID" ]] || return 1
  mode=$(stat -c %a -- "$dir")
  (( (8#$mode & 0022) == 0 ))
}

validate_skill() {
  local dir=$1 file first line found_end=0 skip_first=1
  file=$dir/SKILL.md
  owned_regular_file "$file" || die "missing owned regular non-symlink SKILL.md in $dir"
  IFS= read -r first <"$file" || die 'empty SKILL.md'
  [[ $first == '---' ]] || die 'SKILL.md must start with YAML frontmatter'
  while IFS= read -r line; do
    if ((skip_first == 1)); then
      skip_first=0
      continue
    fi
    [[ $line == '---' ]] && { found_end=1; break; }
  done <"$file"
  ((found_end == 1)) || die 'SKILL.md frontmatter is not closed'
  grep -Eq '^name:[[:space:]]*convertor-api[[:space:]]*$' "$file" || die 'invalid skill name'
  grep -Fq 'https://convertor.xakki.pro/api/doc.json' "$file" || die 'mandatory OpenAPI URL is missing'
  grep -Fq 'Before every use of the API' "$file" || die 'mandatory freshness rule is missing'
  if grep -Eq '\beyJ[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\b' "$file"; then
    die 'SKILL.md contains a JWT-like secret'
  fi
  if grep -Eq -- '-----BEGIN (RSA |EC |OPENSSH )?PRIVATE KEY-----' "$file"; then
    die 'SKILL.md contains a private key'
  fi
  if grep -Eiq 'Authorization:[[:space:]]*Bearer[[:space:]]+[^*$ `{][^ ]{15,}' "$file"; then
    die 'SKILL.md contains a literal bearer credential'
  fi
  "$SKILLS_REF_BIN" validate "$dir" >/dev/null || die 'official skills-ref validation failed'
}

validate_repo_source() {
  local entry count=0
  [[ -d $SOURCE && ! -L $SOURCE ]] || die "repo source must be a regular directory: $SOURCE"
  SOURCE=$(cd "$SOURCE" && pwd -P)
  while IFS= read -r -d '' entry; do
    count=$((count + 1))
    [[ $(basename -- "$entry") == SKILL.md ]] || die "unexpected repository skill file: $(basename -- "$entry")"
  done < <(find "$SOURCE" -mindepth 1 -maxdepth 1 -print0)
  [[ $count == 1 ]] || die 'repository skill source must contain only SKILL.md'
  validate_skill "$SOURCE"
  SOURCE_SHA=$(sha_file "$SOURCE/SKILL.md")
  SOURCE_ID=$SOURCE
}

validate_clean_https_url() {
  [[ $SOURCE =~ ^https://[A-Za-z0-9.-]+(:[0-9]{1,5})?(/[^?#[:space:]]*)?$ ]] || die 'URL source must be clean HTTPS without userinfo, query, or fragment'
  SOURCE_ID=$SOURCE
}

download_source() {
  local download_dir=$1 headers status= content_type= line
  mkdir -m 700 -- "$download_dir"
  headers=$(mktemp "$download_dir/.headers.XXXXXX")
  if ! curl --fail --silent --show-error --location \
    --proto '=https' --proto-redir '=https' \
    --connect-timeout "$CONNECT_TIMEOUT_SECONDS" --max-time "$MAX_TIME_SECONDS" \
    --max-filesize "$MAX_DOWNLOAD_BYTES" \
    --dump-header "$headers" --output "$download_dir/SKILL.md" -- "$SOURCE"; then
    die 'HTTPS skill download failed'
  fi
  while IFS= read -r line; do
    line=${line%$'\r'}
    case "$line" in
      HTTP/*)
        status=${line#* }
        status=${status%% *}
        content_type=
        ;;
      [Cc][Oo][Nn][Tt][Ee][Nn][Tt]-[Tt][Yy][Pp][Ee]:*)
        content_type=${line#*:}
        content_type=${content_type# }
        ;;
    esac
  done <"$headers"
  rm -f -- "$headers"
  [[ $status == 200 ]] || die "URL source returned final HTTP ${status:-unknown}"
  case "$content_type" in
    text/markdown*|text/plain*) ;;
    *) die "unexpected final Content-Type: ${content_type:-missing}" ;;
  esac
  chmod 600 -- "$download_dir/SKILL.md"
  validate_skill "$download_dir"
  SOURCE_SHA=$(sha_file "$download_dir/SKILL.md")
}

HOME_CANONICAL=$(realpath -ms -- "$HOME")
[[ -d $HOME_CANONICAL && ! -L $HOME ]] || die 'HOME must be a regular non-symlink directory'
[[ $(realpath -e -- "$HOME") == "$HOME_CANONICAL" ]] || die 'unsafe HOME path'
TARGET=$(realpath -ms -- "$TARGET")
target_parent=$(dirname -- "$TARGET")
target_name=$(basename -- "$TARGET")
[[ $target_name != . && $target_name != .. && -n $target_name ]] || die 'unsafe target path'
case "$target_name" in
  .convertor-api.*|.stage.*|.source-*|.sha256|.sha256.*)
    die "reserved installer basename is not allowed: $target_name"
    ;;
esac
case "$target_parent" in
  "$HOME_CANONICAL/.claude/skills"|"$HOME_CANONICAL/.hermes/skills") ;;
  "$HOME_CANONICAL/.hermes/profiles/"*/skills)
    profile_name=${target_parent#"$HOME_CANONICAL/.hermes/profiles/"}
    profile_name=${profile_name%/skills}
    [[ -n $profile_name && $profile_name != */* ]] || die 'target is outside approved loader roots'
    ;;
  *) die 'target is outside approved loader roots' ;;
esac

check_existing_ancestors() {
  local path=$HOME_CANONICAL relative component
  [[ $(stat -c %u -- "$path") == "$CURRENT_UID" ]] || die 'HOME is not owned by the current user'
  relative=${target_parent#"$HOME_CANONICAL"/}
  IFS=/ read -r -a components <<<"$relative"
  for component in "${components[@]}"; do
    path=$path/$component
    if [[ -e $path || -L $path ]]; then
      owned_safe_directory "$path" || die "unsafe or symlinked loader ancestor: $path"
    else
      break
    fi
  done
}
check_existing_ancestors

ensure_loader_root() {
  local path=$HOME_CANONICAL relative component
  relative=${target_parent#"$HOME_CANONICAL"/}
  IFS=/ read -r -a components <<<"$relative"
  for component in "${components[@]}"; do
    path=$path/$component
    if [[ -e $path || -L $path ]]; then
      owned_safe_directory "$path" || die "unsafe or symlinked loader ancestor: $path"
    else
      mkdir -m 700 -- "$path" || die "could not create loader ancestor: $path"
      owned_safe_directory "$path" || die "unsafe loader ancestor created concurrently: $path"
    fi
  done
}

TMP_ROOT=
STAGE_DIR=
LINK_TMP=
cleanup() {
  local exit_status=$?
  trap - EXIT HUP INT TERM
  [[ -n ${LINK_TMP:-} ]] && rm -f -- "$LINK_TMP" 2>/dev/null || true
  [[ -n ${STAGE_DIR:-} ]] && rm -rf -- "$STAGE_DIR" 2>/dev/null || true
  [[ -n ${TMP_ROOT:-} ]] && rm -rf -- "$TMP_ROOT" 2>/dev/null || true
  exit "$exit_status"
}
trap cleanup EXIT HUP INT TERM

if [[ $SOURCE_KIND == repo ]]; then
  validate_repo_source
  SOURCE_FILE=$SOURCE/SKILL.md
else
  validate_clean_https_url
  TMP_ROOT=$(mktemp -d "${TMPDIR:-/tmp}/convertor-api-sync.XXXXXX")
  download_source "$TMP_ROOT/download"
  SOURCE_FILE=$TMP_ROOT/download/SKILL.md
fi

validate_metadata_file() {
  local file=$1 expected=$2
  owned_regular_file "$file" && [[ $(<"$file") == "$expected" ]]
}

validate_installed_version() {
  local dir=$1 expected_kind=$2 expected_source=$3 recorded_sha entry count=0
  owned_safe_directory "$dir" || return 1
  while IFS= read -r -d '' entry; do
    count=$((count + 1))
    case $(basename -- "$entry") in
      SKILL.md|.source-kind|.source-url|.source-repo|.sha256) ;;
      *) return 1 ;;
    esac
  done < <(find "$dir" -mindepth 1 -maxdepth 1 -print0)
  [[ $count == 4 ]] || return 1
  validate_metadata_file "$dir/.source-kind" "$expected_kind" || return 1
  if [[ $expected_kind == url ]]; then
    validate_metadata_file "$dir/.source-url" "$expected_source" || return 1
    [[ ! -e $dir/.source-repo && ! -L $dir/.source-repo ]] || return 1
  else
    validate_metadata_file "$dir/.source-repo" "$expected_source" || return 1
    [[ ! -e $dir/.source-url && ! -L $dir/.source-url ]] || return 1
  fi
  owned_regular_file "$dir/.sha256" || return 1
  recorded_sha=$(<"$dir/.sha256")
  [[ $recorded_sha =~ ^[a-f0-9]{64}$ ]] || return 1
  owned_regular_file "$dir/SKILL.md" || return 1
  [[ $(sha_file "$dir/SKILL.md") == "$recorded_sha" ]] || return 1
  (validate_skill "$dir") >/dev/null 2>&1 || return 1
}

managed_target=0
managed_dir=
if [[ -L $TARGET ]]; then
  managed_dir=$(readlink -f -- "$TARGET" 2>/dev/null || true)
  version_root=$target_parent/.convertor-api.versions
  if [[ $(stat -c %u -- "$TARGET") == "$CURRENT_UID" && -n $managed_dir && $managed_dir == "$version_root/"* ]] && validate_installed_version "$managed_dir" "$SOURCE_KIND" "$SOURCE_ID"; then
    managed_target=1
  fi
fi

if [[ -e $TARGET || -L $TARGET ]]; then
  if ((managed_target == 0)); then
    if [[ ! -L $TARGET ]]; then
      die "target is an unknown existing file or directory; move it outside the loader root manually, then retry: $TARGET"
    fi
    ((REPLACE == 1)) || die "target symlink is not a verified managed install; use --replace for an atomic symlink switch: $TARGET"
  fi
fi

if [[ $MODE == check ]]; then
  ((managed_target == 1)) || die "check failed: target is not a verified managed install: $TARGET"
  [[ $(sha_file "$managed_dir/SKILL.md") == "$SOURCE_SHA" ]] || die 'check failed: installed content differs from selected source'
  printf 'ok: verified managed source and target (%s)\n' "$TARGET"
  exit 0
fi

if [[ $MODE == dry-run ]]; then
  printf 'dry-run: source-kind=%s target=%s action=%s\n' \
    "$SOURCE_KIND" "$TARGET" "$([[ -e $TARGET || -L $TARGET ]] && printf update || printf install)"
  exit 0
fi

ensure_loader_root
check_existing_ancestors
owned_safe_directory "$target_parent" || die 'loader root is unsafe'
version_root=$target_parent/.convertor-api.versions
if [[ -e $version_root || -L $version_root ]]; then
  owned_safe_directory "$version_root" || die 'version root is unsafe'
else
  mkdir -m 700 -- "$version_root"
fi

source_identity=$(printf '%s\0%s\0%s' "$SOURCE_KIND" "$SOURCE_ID" "$SOURCE_SHA" | sha256sum | cut -d' ' -f1)
desired_version=$version_root/$target_name-$source_identity
if ((managed_target == 1)) && [[ $(sha_file "$managed_dir/SKILL.md") == "$SOURCE_SHA" ]]; then
  version_dir=$managed_dir
elif [[ -e $desired_version || -L $desired_version ]]; then
  if validate_installed_version "$desired_version" "$SOURCE_KIND" "$SOURCE_ID" && [[ $(sha_file "$desired_version/SKILL.md") == "$SOURCE_SHA" ]]; then
    version_dir=$desired_version
  elif ((REPLACE == 1)); then
    version_dir=
  else
    die 'version collision or tampering detected; use --replace'
  fi
else
  version_dir=
fi

if [[ -z ${version_dir:-} ]]; then
  STAGE_DIR=$(mktemp -d "$version_root/.stage.XXXXXX")
  chmod 700 -- "$STAGE_DIR"
  install -m 600 -- "$SOURCE_FILE" "$STAGE_DIR/SKILL.md"
  printf '%s\n' "$SOURCE_KIND" >"$STAGE_DIR/.source-kind"
  if [[ $SOURCE_KIND == url ]]; then
    printf '%s\n' "$SOURCE_ID" >"$STAGE_DIR/.source-url"
  else
    printf '%s\n' "$SOURCE_ID" >"$STAGE_DIR/.source-repo"
  fi
  printf '%s\n' "$SOURCE_SHA" >"$STAGE_DIR/.sha256"
  chmod 600 -- "$STAGE_DIR/.source-kind" "$STAGE_DIR/.sha256" "$STAGE_DIR/SKILL.md"
  if [[ $SOURCE_KIND == url ]]; then
    chmod 600 -- "$STAGE_DIR/.source-url"
  else
    chmod 600 -- "$STAGE_DIR/.source-repo"
  fi
  validate_installed_version "$STAGE_DIR" "$SOURCE_KIND" "$SOURCE_ID" || die 'staged version validation failed'
  version_dir=$desired_version
  if [[ -e $version_dir || -L $version_dir ]]; then
    version_dir=$desired_version-$(basename -- "$STAGE_DIR")
  fi
  [[ ! -e $version_dir && ! -L $version_dir ]] || die 'version directory collision'
  mv -T -- "$STAGE_DIR" "$version_dir"
  STAGE_DIR=
fi

LINK_TMP=$(mktemp -d "$target_parent/.convertor-api.link.XXXXXX")
rmdir -- "$LINK_TMP"
ln -s -- "$version_dir" "$LINK_TMP"

if ((managed_target == 1)) || [[ -L $TARGET ]]; then
  mv -Tf -- "$LINK_TMP" "$TARGET" || die 'atomic symlink switch failed'
  LINK_TMP=
else
  mv -nT -- "$LINK_TMP" "$TARGET" || die 'fresh install switch failed'
  if [[ ! -L $TARGET || $(readlink -f -- "$TARGET") != "$version_dir" ]]; then
    die 'fresh install collision detected'
  fi
  LINK_TMP=
fi

installed_dir=$(readlink -f -- "$TARGET" 2>/dev/null || true)
[[ $installed_dir == "$version_dir" ]] || die 'post-install target verification failed'
validate_installed_version "$installed_dir" "$SOURCE_KIND" "$SOURCE_ID" || die 'post-install managed validation failed'
[[ $(sha_file "$installed_dir/SKILL.md") == "$SOURCE_SHA" ]] || die 'post-install content verification failed'
printf 'installed: %s\n' "$TARGET"
