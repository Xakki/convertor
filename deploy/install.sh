#!/usr/bin/env bash
# Автономный bootstrap remote-воркеров convertor (публичный путь, без клона репо).
#
# Установка:
#   curl -fsSL <gist-raw-url>/install.sh | bash
#   # или из локального каталога deploy/:
#   bash install.sh
#
# Обновление (идемпотентно, без дублей):
#   curl -fsSL <gist-raw-url>/install.sh | bash -s -- update
#   bash install.sh update
#
# CUDA / worker-ai-cuda в этот путь НЕ входят.

set -euo pipefail

# Заполняется `make publish-deploy-gist` в опубликованной копии; в репо пусто.
# Можно переопределить: DEPLOY_GIST_ID=… DEPLOY_GIST_OWNER=… bash install.sh
GIST_ID="${DEPLOY_GIST_ID:-}"
GIST_OWNER="${DEPLOY_GIST_OWNER:-}"

ALL_PROFILES=(document audio video image data ai)
PROFILE_LABELS=(
  "document  worker-libreoffice (документы)"
  "audio     worker-ffmpeg-audio"
  "video     worker-ffmpeg-video"
  "image     worker-image"
  "data      worker-data"
  "ai        worker-ai-cpu:latest (без CUDA)"
)

DEFAULT_GATEWAY_WS_URL="wss://convertor.xakki.pro/ws/worker/"
DEFAULT_API_BASE_URL="https://convertor.xakki.pro"
DEFAULT_IMAGE_NS="harbor.xakki.ru/convertor"
DEFAULT_INSTALL_DIR="${HOME}/convertor-workers"
DEFAULT_HOST_ROOT_PROBE_DIR="/var/lib/convertor/host-root-probe"

MODE="${1:-install}"
case "$MODE" in
  install|update) ;;
  -h|--help|help)
    sed -n '2,14p' "$0" 2>/dev/null || true
    echo "Usage: $0 [install|update]"
    exit 0
    ;;
  *)
    echo "error: unknown mode '$MODE' (expected install|update)" >&2
    exit 2
    ;;
esac

die() { echo "error: $*" >&2; exit 1; }
info() { echo "→ $*"; }

# Значение для docker-compose .env: двойные кавычки, экранируем \, ", $.
env_dq() {
  local s=$1
  s=${s//\\/\\\\}
  s=${s//\"/\\\"}
  s=${s//\$/\\\$}
  printf '"%s"' "$s"
}

require_cmd() {
  command -v "$1" >/dev/null 2>&1 || die "нужна команда '$1'"
}

# --- resolve work dir + companion files --------------------------------------

resolve_script_dir() {
  local src="${BASH_SOURCE[0]:-}"
  if [[ -n "$src" && "$src" != "bash" && "$src" != "-" && -f "$src" ]]; then
    cd "$(dirname "$src")" && pwd
  else
    echo ""
  fi
}

gist_raw_base() {
  local id="${1:-$GIST_ID}"
  local owner="${2:-$GIST_OWNER}"
  [[ -n "$id" && -n "$owner" ]] || return 1
  # Стабильный raw без SHA: последняя ревизия gist.
  echo "https://gist.githubusercontent.com/${owner}/${id}/raw"
}

fetch_companions() {
  local dest="$1"
  local base file
  base="$(gist_raw_base)" || die "нет локальных docker-compose.yml/.env.example и пусты GIST_ID/GIST_OWNER (DEPLOY_GIST_*) — неоткуда скачать companions"
  mkdir -p "$dest"
  for file in docker-compose.yml .env.example install.sh generate-allowlist.py; do
    info "скачиваю $file ← $base/$file"
    curl -fsSL "$base/$file" -o "$dest/$file"
  done
  chmod +x "$dest/install.sh" 2>/dev/null || true
}

ensure_workdir() {
  local script_dir candidates dir
  script_dir="$(resolve_script_dir)"

  if [[ -n "$script_dir" && -f "$script_dir/docker-compose.yml" ]]; then
    WORK_DIR="$script_dir"
    return
  fi

  # curl|bash: ищем уже установленный каталог или создаём дефолтный.
  candidates=()
  [[ -n "${CONVERTOR_WORKERS_DIR:-}" ]] && candidates+=("$CONVERTOR_WORKERS_DIR")
  candidates+=("$DEFAULT_INSTALL_DIR" "$(pwd)/convertor-workers")

  for dir in "${candidates[@]}"; do
    if [[ -f "$dir/docker-compose.yml" && -f "$dir/.env" ]]; then
      WORK_DIR="$dir"
      return
    fi
  done

  WORK_DIR="${CONVERTOR_WORKERS_DIR:-$DEFAULT_INSTALL_DIR}"
  if [[ ! -f "$WORK_DIR/docker-compose.yml" ]]; then
    fetch_companions "$WORK_DIR"
  fi
}

# --- preflight ---------------------------------------------------------------

preflight() {
  local code gw api
  gw="${GATEWAY_WS_URL:-$DEFAULT_GATEWAY_WS_URL}"
  api="${API_BASE_URL:-$DEFAULT_API_BASE_URL}"
  # Gateway: GET → 426 Upgrade Required = жив (HEAD даёт ложный 502).
  code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 5 "${gw}" || true)"
  if [[ "$code" != "426" && "$code" != "101" && "$code" != "400" ]]; then
    echo "warn: gateway ${gw} → HTTP ${code:-?} (ожидали 426); продолжаем" >&2
  else
    info "gateway OK (${code})"
  fi
  code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 5 "${api}/api/v1/formats" || true)"
  if [[ "$code" != "200" ]]; then
    echo "warn: API ${api}/api/v1/formats → HTTP ${code:-?} (ожидали 200); продолжаем" >&2
  else
    info "API OK (${code})"
  fi
}

# --- interactive prompts -----------------------------------------------------

prompt_token() {
  local token
  if [[ -n "${WORKER_API_TOKEN:-}" ]]; then
    TOKEN="$WORKER_API_TOKEN"
    return
  fi
  if [[ ! -t 0 ]]; then
    die "WORKER_API_TOKEN не задан и stdin не TTY (pipe). Задайте env WORKER_API_TOKEN=… или запустите интерактивно"
  fi
  printf "WORKER_API_TOKEN (скрытый ввод): "
  stty -echo
  IFS= read -r token
  stty echo
  printf '\n'
  [[ -n "$token" ]] || die "WORKER_API_TOKEN обязателен"
  TOKEN="$token"
}

prompt_project_name() {
  local default
  default="convertor-remote"
  if [[ ! -t 0 ]]; then
    PROJECT_NAME="${COMPOSE_PROJECT_NAME:-$default}"
    return
  fi
  printf "COMPOSE_PROJECT_NAME [%s]: " "$default"
  IFS= read -r PROJECT_NAME || true
  PROJECT_NAME="${PROJECT_NAME:-$default}"
}

prompt_profiles() {
  local i choice raw sel
  if [[ -n "${WORKER_PROFILES:-}" ]]; then
    # shellcheck disable=SC2206
    SELECTED_PROFILES=($WORKER_PROFILES)
    return
  fi
  if [[ ! -t 0 ]]; then
    SELECTED_PROFILES=(document audio video image data)
    return
  fi
  echo "Какие воркеры поднять? (CUDA недоступен в публичном пути)"
  for i in "${!ALL_PROFILES[@]}"; do
    printf "  %d) %s\n" "$((i + 1))" "${PROFILE_LABELS[$i]}"
  done
  echo "  a) все (document audio video image data ai)"
  echo "  без ai: 1 2 3 4 5"
  printf "Выбор (номера через пробел / a) [1 2 3 4 5]: "
  IFS= read -r choice || true
  choice="${choice:-1 2 3 4 5}"
  SELECTED_PROFILES=()
  if [[ "$choice" == "a" || "$choice" == "A" || "$choice" == "all" ]]; then
    SELECTED_PROFILES=("${ALL_PROFILES[@]}")
    return
  fi
  for raw in $choice; do
    if [[ "$raw" =~ ^[1-6]$ ]]; then
      SELECTED_PROFILES+=("${ALL_PROFILES[$((raw - 1))]}")
    elif [[ " ${ALL_PROFILES[*]} " == *" $raw "* ]]; then
      SELECTED_PROFILES+=("$raw")
    else
      die "неизвестный выбор: $raw"
    fi
  done
  # unique keep order
  sel=""
  for raw in "${SELECTED_PROFILES[@]}"; do
    case " $sel " in
      *" $raw "*) ;;
      *) sel="${sel:+$sel }$raw" ;;
    esac
  done
  # shellcheck disable=SC2206
  SELECTED_PROFILES=($sel)
  [[ ${#SELECTED_PROFILES[@]} -gt 0 ]] || die "не выбран ни один профиль"
}

write_env() {
  local host profiles_str token_quoted
  host="${HOST_NAME:-}"
  python3 - "$host" <<'PY'
import re, sys
if re.fullmatch(r"(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$", sys.argv[1]) is None:
    raise SystemExit("HOST_NAME must be a nonempty lowercase DNS-label/FQDN")
PY
  profiles_str="${SELECTED_PROFILES[*]}"
  token_quoted="$(env_dq "$TOKEN")"
  cat >"$WORK_DIR/.env" <<EOF
# Сгенерировано deploy/install.sh — не коммитить с реальным токеном.
WORKER_API_TOKEN=${token_quoted}
COMPOSE_PROJECT_NAME=${PROJECT_NAME}
WORKER_PROFILES=${profiles_str}
HOST_NAME=${host}
HOST_ROOT_PROBE_DIR=${HOST_ROOT_PROBE_DIR:-$DEFAULT_HOST_ROOT_PROBE_DIR}
GATEWAY_WS_URL=${GATEWAY_WS_URL:-$DEFAULT_GATEWAY_WS_URL}
API_BASE_URL=${API_BASE_URL:-$DEFAULT_API_BASE_URL}
IMAGE_NS=${IMAGE_NS:-$DEFAULT_IMAGE_NS}
IMAGE_TAG=${IMAGE_TAG:-latest}
WORKER_PULL_POLICY=${WORKER_PULL_POLICY:-always}
AI_PULL_POLICY=${AI_PULL_POLICY:-always}
AI_VARIANT=cpu
APP_ENV=${APP_ENV:-prod}
TZ=${TZ:-UTC}
EOF
  chmod 600 "$WORK_DIR/.env"
  info "записан $WORK_DIR/.env"
}

ensure_host_root_probe() {
  HOST_ROOT_PROBE_DIR="${HOST_ROOT_PROBE_DIR:-$DEFAULT_HOST_ROOT_PROBE_DIR}"
  mkdir -p "$HOST_ROOT_PROBE_DIR"
  local root_device probe_device
  root_device="$(df -P / | awk 'NR==2 {print $1}')"
  probe_device="$(df -P "$HOST_ROOT_PROBE_DIR" | awk 'NR==2 {print $1}')"
  [[ -n "$root_device" && "$root_device" = "$probe_device" ]] || die "HOST_ROOT_PROBE_DIR must be on the host root filesystem"
}

load_env() {
  [[ -f "$WORK_DIR/.env" ]] || die "нет $WORK_DIR/.env — сначала install"
  # shellcheck disable=SC1091
  set -a
  # shellcheck disable=SC1090
  source "$WORK_DIR/.env"
  set +a
  TOKEN="${WORKER_API_TOKEN:-}"
  PROJECT_NAME="${COMPOSE_PROJECT_NAME:-}"
  [[ -n "$TOKEN" ]] || die "WORKER_API_TOKEN пуст в .env"
  [[ -n "$PROJECT_NAME" ]] || die "COMPOSE_PROJECT_NAME пуст в .env"
  # shellcheck disable=SC2206
  SELECTED_PROFILES=(${WORKER_PROFILES:-document audio video image data})
}

profile_args() {
  local p
  PROFILE_ARGS=()
  for p in "${SELECTED_PROFILES[@]}"; do
    PROFILE_ARGS+=(--profile "$p")
  done
}

compose() {
  docker compose -f "$WORK_DIR/docker-compose.yml" --env-file "$WORK_DIR/.env" "$@"
}

activate_allowlist() {
  local profile
  local -a workers=()
  for profile in "${SELECTED_PROFILES[@]}"; do
    case "$profile" in
      document) workers+=(worker-libreoffice) ;;
      audio) workers+=(worker-ffmpeg-audio) ;;
      video) workers+=(worker-ffmpeg-video) ;;
      image) workers+=(worker-image) ;;
      data) workers+=(worker-data) ;;
      ai) workers+=(worker-ai) ;;
    esac
  done
  [[ ${#workers[@]} -gt 0 ]] || die "allowlist cannot be empty"
  if [[ -f "$WORK_DIR/allowlist.json" ]]; then
    cp -f "$WORK_DIR/allowlist.json" "$WORK_DIR/allowlist.json.previous"
  fi
  python3 "$WORK_DIR/generate-allowlist.py" --output "$WORK_DIR/allowlist.json" $(printf -- '--worker %q ' "${workers[@]}")
}

rollback_allowlist() {
  if [[ -f "$WORK_DIR/allowlist.json.previous" ]]; then
    mv -f "$WORK_DIR/allowlist.json.previous" "$WORK_DIR/allowlist.json"
    compose up -d --force-recreate host-telemetry >/dev/null 2>&1 || true
  fi
}

bring_up() {
  profile_args
  info "pull образов (${SELECTED_PROFILES[*]})…"
  compose "${PROFILE_ARGS[@]}" pull
  info "up -d --force-recreate…"
  if ! compose "${PROFILE_ARGS[@]}" up -d --force-recreate --remove-orphans; then
    rollback_allowlist
    return 1
  fi
  info "generate actual cgroup allowlist…"
  if ! activate_allowlist; then
    rollback_allowlist
    return 1
  fi
  info "recreate collector with active allowlist…"
  if ! compose up -d --wait --force-recreate host-telemetry; then
    rollback_allowlist
    return 1
  fi
  compose ps
}

# --- main --------------------------------------------------------------------

require_cmd docker
require_cmd curl
docker compose version >/dev/null 2>&1 || die "нужен Docker Compose v2 (docker compose)"

ensure_workdir
info "каталог: $WORK_DIR"

case "$MODE" in
  install)
    prompt_token
    prompt_project_name
    prompt_profiles
    # preflight использует URL из env/defaults до записи .env
    GATEWAY_WS_URL="${GATEWAY_WS_URL:-$DEFAULT_GATEWAY_WS_URL}"
    API_BASE_URL="${API_BASE_URL:-$DEFAULT_API_BASE_URL}"
    preflight
    ensure_host_root_probe
    write_env
    bring_up
    info "готово. Логи: docker compose -f $WORK_DIR/docker-compose.yml --env-file $WORK_DIR/.env logs -f"
    info "обновление: bash $WORK_DIR/install.sh update"
    ;;
  update)
    load_env
    python3 - "${HOST_NAME:-}" <<'PY'
import re, sys
if re.fullmatch(r"(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$", sys.argv[1]) is None:
    raise SystemExit("HOST_NAME must be a nonempty lowercase DNS-label/FQDN")
PY
    preflight
    ensure_host_root_probe
    # обновить companions из gist, если ID известен (необязательно)
    if [[ -n "${DEPLOY_GIST_ID:-}" ]]; then GIST_ID="$DEPLOY_GIST_ID"; fi
    if [[ -n "${DEPLOY_GIST_OWNER:-}" ]]; then GIST_OWNER="$DEPLOY_GIST_OWNER"; fi
    if [[ -n "$GIST_ID" && -n "$GIST_OWNER" ]]; then
      info "обновляю deploy-артефакты из gist ${GIST_OWNER}/${GIST_ID}…"
      fetch_companions "$WORK_DIR" || echo "warn: не удалось обновить companions, продолжаем со старыми" >&2
      # .env не трогаем — fetch_companions его не перезаписывает
    fi
    activate_allowlist
    bring_up
    info "update завершён"
    ;;
esac
