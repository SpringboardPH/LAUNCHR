#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

usage() {
  cat <<'EOF'
Usage: HOST_STORAGE=/path/to/backend/storage COMPOSE_MODE=prod ./scripts/import-host-storage.sh

Copies the host Laravel storage tree into the Compose php_storage volume.
Optional HOST_PUBLIC copies system_logo_* and payroll_template_* into
storage/app/public so BrandingAsset can find them after a prod image build.

The php_storage volume must already exist. Start the prod stack first:
  COMPOSE_MODE=prod ./scripts/compose.sh up --build -d
EOF
}

if [[ "${1:-}" == "-h" || "${1:-}" == "--help" ]]; then
  usage
  exit 0
fi

if [[ -z "${HOST_STORAGE:-}" ]]; then
  echo "Set HOST_STORAGE to the existing backend/storage directory." >&2
  usage >&2
  exit 1
fi

if [[ ! -d "$HOST_STORAGE" ]]; then
  echo "HOST_STORAGE is not a directory: $HOST_STORAGE" >&2
  exit 1
fi

HOST_STORAGE="$(cd "$HOST_STORAGE" && pwd)"

if [[ -n "${HOST_PUBLIC:-}" ]]; then
  if [[ ! -d "$HOST_PUBLIC" ]]; then
    echo "HOST_PUBLIC is not a directory: $HOST_PUBLIC" >&2
    exit 1
  fi
  HOST_PUBLIC="$(cd "$HOST_PUBLIC" && pwd)"
fi

export COMPOSE_MODE="${COMPOSE_MODE:-prod}"

volume_name="$(
  "$ROOT/scripts/compose.sh" config --format json \
    | python3 -c 'import json,sys; print(json.load(sys.stdin)["volumes"]["php_storage"]["name"])'
)"

if ! docker volume inspect "$volume_name" >/dev/null 2>&1; then
  echo "Volume $volume_name does not exist. Start the prod stack first." >&2
  exit 1
fi

docker run --rm \
  -v "$HOST_STORAGE":/from:ro \
  -v "$volume_name":/to \
  alpine:3.20 \
  sh -c 'cp -a /from/. /to/'

if [[ -n "${HOST_PUBLIC:-}" ]]; then
  docker run --rm \
    -v "$HOST_PUBLIC":/from-public:ro \
    -v "$volume_name":/to \
    alpine:3.20 \
    sh -c 'mkdir -p /to/app/public; cp -a /from-public/system_logo_* /from-public/payroll_template_* /to/app/public/ 2>/dev/null || true'
fi

echo "Imported $HOST_STORAGE into $volume_name"
if [[ -n "${HOST_PUBLIC:-}" ]]; then
  echo "Copied branding files from $HOST_PUBLIC into $volume_name/app/public"
fi
