#!/usr/bin/env bash
# One queue:listen at a time. Do not write jobs.

set -u

cd "$(dirname "$0")/.." || exit 1

if [ ! -f artisan ]; then
  echo "queue-dev: artisan not found" >&2
  exit 1
fi

pid=

on_stop() {
  if [ -n "${pid}" ]; then
    kill -TERM "${pid}" 2>/dev/null || true
    wait "${pid}" 2>/dev/null || true
  fi
  exit 0
}

trap on_stop INT TERM

while true; do
  php artisan queue:listen --tries=1 --timeout=0 &
  pid=$!
  wait "${pid}"
  status=$?
  pid=
  echo "queue-dev: queue:listen exited ${status}; restarting in 1s" >&2
  sleep 1
done
