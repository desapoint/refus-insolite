#!/usr/bin/env bash
# Usage: bash tests/check-deployment.sh https://exemple.fr
set -euo pipefail
base="${1:?Indiquer l'URL HTTPS du site}"
base="${base%/}"

expect() {
  local path="$1" kind="$2" status
  status="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 10 -- "$base$path")"
  if [[ "$kind" == deny ]]; then
    if [[ "$status" != 403 && "$status" != 404 ]]; then
      echo "ERREUR : $path est accessible ou incorrect (HTTP $status)" >&2
      exit 1
    fi
  elif [[ "$status" != 200 ]]; then
    echo "ERREUR : $path doit répondre 200, HTTP $status" >&2
    exit 1
  fi
  printf '%s : HTTP %s\n' "$path" "$status"
}

expect / ok
expect /explorer ok
expect /soumettre ok
expect /assets/site.css ok
expect /assets/site.js ok
expect /config/config.example.php deny
expect /src/app.php deny
expect /migrations/001_initial.php deny
expect /tests/smoke.php deny
expect /public/index.php deny
expect /README.md deny
expect /.git/config deny
printf 'Protection HTTP : OK\n'
