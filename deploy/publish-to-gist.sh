#!/usr/bin/env bash
# Публикация содержимого deploy/ в публичный GitHub gist (CNV-32).
# Вызывается из `make publish-deploy-gist` / хвоста `release-workers`.
# Warn+skip (exit 0), если DEPLOY_GIST_ID пуст или нет gh/jq.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DEPLOY="$ROOT/deploy"

if [[ -z "${DEPLOY_GIST_ID:-}" ]]; then
  echo "publish-deploy-gist: DEPLOY_GIST_ID пуст — skip (создайте gist один раз, см. deploy/README.md)"
  exit 0
fi
if ! command -v gh >/dev/null 2>&1; then
  echo "publish-deploy-gist: gh не найден — skip"
  exit 0
fi
if ! command -v jq >/dev/null 2>&1; then
  echo "publish-deploy-gist: jq не найден — skip"
  exit 0
fi

owner="${DEPLOY_GIST_OWNER:-}"
if [[ -z "$owner" ]]; then
  owner="$(gh api user -q .login 2>/dev/null || true)"
fi
if [[ -z "$owner" ]]; then
  echo "publish-deploy-gist: не удалось определить DEPLOY_GIST_OWNER — skip"
  exit 0
fi

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

cp "$DEPLOY/docker-compose.yml" "$DEPLOY/.env.example" "$DEPLOY/README.md" "$DEPLOY/generate-allowlist.py" "$tmp/"
# В публикуемой копии подставляем дефолты GIST_ID/OWNER (env override сохраняется).
sed \
  -e "s/^GIST_ID=\"\${DEPLOY_GIST_ID:-}\"/GIST_ID=\"\${DEPLOY_GIST_ID:-${DEPLOY_GIST_ID}}\"/" \
  -e "s/^GIST_OWNER=\"\${DEPLOY_GIST_OWNER:-}\"/GIST_OWNER=\"\${DEPLOY_GIST_OWNER:-${owner}}\"/" \
  "$DEPLOY/install.sh" >"$tmp/install.sh"
chmod +x "$tmp/install.sh"

echo "publish-deploy-gist: PATCH gist ${DEPLOY_GIST_ID} (owner=${owner})"
jq -n \
  --rawfile compose "$tmp/docker-compose.yml" \
  --rawfile envex "$tmp/.env.example" \
  --rawfile install "$tmp/install.sh" \
  --rawfile allowlistgen "$tmp/generate-allowlist.py" \
  --rawfile readme "$tmp/README.md" \
  '{files:{
    "docker-compose.yml":{content:$compose},
    ".env.example":{content:$envex},
    "install.sh":{content:$install},
    "generate-allowlist.py":{content:$allowlistgen},
    "README.md":{content:$readme}
  }}' | gh api -X PATCH "/gists/${DEPLOY_GIST_ID}" --input - >/dev/null

echo "publish-deploy-gist: OK → https://gist.githubusercontent.com/${owner}/${DEPLOY_GIST_ID}/raw/install.sh"
