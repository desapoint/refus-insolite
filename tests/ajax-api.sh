#!/usr/bin/env bash
set -euo pipefail
# Tests exécutés exclusivement dans un environnement CI jetable.
if [[ "${GITHUB_ACTIONS:-}" != "true" ]]; then echo 'Test CI uniquement.' >&2; exit 1; fi
mkdir -p ../private
cat > ../private/refus-insolite.php <<'PHP'
<?php
return [
  'app' => ['secret_key' => str_repeat('a', 64), 'admin_password_hash' => ''],
  'database' => [
    'host' => '127.0.0.1', 'port' => 3306, 'name' => 'refus_insolite',
    'username' => 'refus_site', 'password' => 'test_mariadb_password',
  ],
];
PHP
chmod 600 ../private/refus-insolite.php
cookies="$(mktemp)"
reply="$(mktemp)"
php -S 127.0.0.1:18083 -t . tests/http-router.php >/tmp/refus-insolite-http-test.log 2>&1 &
pid=$!
trap 'kill "$pid" 2>/dev/null || true; rm -f "$cookies" "$reply" ../private/refus-insolite.php' EXIT
for _ in $(seq 1 40); do if curl -s --fail http://127.0.0.1:18083/soumettre -c "$cookies" > "$reply"; then break; fi; sleep .25; done
token="$(grep -oE 'name="csrf" value="[a-f0-9]{64}"' "$reply" | head -1 | cut -d '"' -f 4)"
test -n "$token"
base=(curl -s -b "$cookies" -c "$cookies" -H 'Accept: application/json' -H 'X-Requested-With: XMLHttpRequest')
status="$("${base[@]}" -o "$reply" -w '%{http_code}' -X POST -d 'action=submit' http://127.0.0.1:18083/soumettre)"
test "$status" = '403'
php -r '$j=json_decode(file_get_contents($argv[1]),true);if(!is_array($j)||$j["ok"]!==false)exit(1);' "$reply"
status="$("${base[@]}" -o "$reply" -w '%{http_code}' -X POST --data-urlencode "csrf=$token" -d 'action=submit' -d 'title=abc' http://127.0.0.1:18083/soumettre)"
test "$status" = '422'
php -r '$j=json_decode(file_get_contents($argv[1]),true);if(!is_array($j)||$j["ok"]!==false)exit(1);' "$reply"
status="$("${base[@]}" -o "$reply" -w '%{http_code}' -X POST \
  --data-urlencode "csrf=$token" \
  -d 'action=submit' \
  --data-urlencode 'title=Un refus très insolite' \
  --data-urlencode 'quote_text=Nous avons décidé de prendre la photocopieuse à votre place, elle travaille plus vite.' \
  --data-urlencode 'context_text=Après trois entretiens sérieux, cette réponse complètement inattendue est arrivée.' \
  -d 'category=absurde' -d 'sector=services' -d 'consent=yes' \
  http://127.0.0.1:18083/soumettre)"
test "$status" = '201'
php -r '$j=json_decode(file_get_contents($argv[1]),true);if(!is_array($j)||$j["ok"]!==true)exit(1);' "$reply"
php -r 'require "src/app.php";$c=require "../private/refus-insolite.php";$db=db_open($c);$s=$db->query("SELECT COUNT(*) FROM refus WHERE status=\x27pending\x27");if((int)$s->fetchColumn()<1)exit(1);'
echo 'Tests HTTP AJAX : OK'
