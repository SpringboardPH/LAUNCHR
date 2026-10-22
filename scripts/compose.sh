#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

usage() {
  cat <<'EOF'
Usage: scripts/compose.sh [docker compose args]

Loads .env from the repo root. Enables the mysql profile when DB_HOST is
empty. Adds compose.publish.yaml when APP_PORT is set. Adds
compose.proxy.yaml when PROXY_NETWORK is set.

Examples:
  APP_PORT=8080 ./scripts/compose.sh up --build
  ./scripts/compose.sh -f compose.yaml -f compose.prod.yaml up --build
EOF
}

if [[ "${1:-}" == "-h" || "${1:-}" == "--help" ]]; then
  usage
  exit 0
fi

if [[ -f .env ]]; then
  set -a
  # shellcheck disable=SC1091
  source .env
  set +a
fi

has_file_flag=0
for arg in "$@"; do
  case "$arg" in
    -f|--file)
      has_file_flag=1
      ;;
  esac
done

file_args=()
if [[ "$has_file_flag" -eq 0 ]]; then
  file_args+=(-f compose.yaml)
  if [[ "${COMPOSE_MODE:-dev}" == "prod" ]]; then
    file_args+=(-f compose.prod.yaml)
  else
    file_args+=(-f compose.dev.yaml)
  fi
fi

if [[ -n "${APP_PORT:-}" ]]; then
  file_args+=(-f compose.publish.yaml)
fi

if [[ -n "${PROXY_NETWORK:-}" ]]; then
  file_args+=(-f compose.proxy.yaml)
fi

if [[ -z "${DB_HOST:-}" ]]; then
  export DB_HOST=mysql
  export DB_PORT="${DB_PORT:-3306}"
  export DB_DATABASE="${DB_DATABASE:-launchr}"
  export DB_USERNAME="${DB_USERNAME:-launchr}"
  export DB_PASSWORD="${DB_PASSWORD:-launchr}"
  export MYSQL_ROOT_PASSWORD="${MYSQL_ROOT_PASSWORD:-${DB_PASSWORD}}"
  if [[ -n "${COMPOSE_PROFILES:-}" ]]; then
    export COMPOSE_PROFILES="${COMPOSE_PROFILES},mysql"
  else
    export COMPOSE_PROFILES=mysql
  fi
fi

compose_cmd=""
for arg in "$@"; do
  case "$arg" in
    -*)
      ;;
    *)
      compose_cmd="$arg"
      break
      ;;
  esac
done

if [[ "$compose_cmd" == "up" || "$compose_cmd" == "run" ]]; then
  if [[ -z "${APP_KEY:-}" ]]; then
    echo "APP_KEY is empty. Set it in .env (echo base64:\$(openssl rand -base64 32))." >&2
    exit 1
  fi
fi

exec docker compose "${file_args[@]}" "$@"
