#!/usr/bin/env bash
set -euo pipefail
# Tests exécutés exclusivement dans un environnement CI jetable.
if [[ "${GITHUB_ACTIONS:-}" != "true" ]]; then echo 'Test CI uniquement.' >&2; exit 1; fi
mkdir -p ../private
cat > ../private/refus-insolite.php <<'PHP'
<?php
return [
  'app' => ['secret_key' => str_repeat('a', 64), 'admin_password_hash' => password_hash('Mot-de-passe-test-uniquement', PASSWORD_DEFAULT)],
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
# Le navigateur doit pouvoir utiliser fetch() sur le même domaine (CSP).
headers="$(mktemp)"
trap 'kill "$pid" 2>/dev/null || true; rm -f "$cookies" "$reply" "$headers" ../private/refus-insolite.php' EXIT
curl -s -D "$headers" -o /dev/null http://127.0.0.1:18083/soumettre
grep -i 'content-security-policy:.*connect-src .self.' "$headers"

# Publier un témoignage de test, puis vérifier les deux réactions indépendantes.
story_id="$(php -r 'require "src/app.php";$c=require "../private/refus-insolite.php";$db=db_open($c);echo $db->query("SELECT id FROM refus WHERE status=\x27pending\x27 ORDER BY id DESC LIMIT 1")->fetchColumn();')"
test -n "$story_id"
php -r 'require "src/app.php";$c=require "../private/refus-insolite.php";$db=db_open($c);$s=$db->prepare("UPDATE refus SET status=\x27approved\x27, published_at=UTC_TIMESTAMP() WHERE id=?");$s->execute([$argv[1]]);' "$story_id"
for kind in relate relate laugh laugh; do
  status="$("${base[@]}" -o "$reply" -w '%{http_code}' -X POST \
    --data-urlencode "csrf=$token" -d "action=react" -d "id=$story_id" -d "kind=$kind" \
    "http://127.0.0.1:18083/refus/$story_id")"
  test "$status" = '200'
  php -r '$j=json_decode(file_get_contents($argv[1]),true);if(!is_array($j)||$j["ok"]!==true||$j["kind"]!==$argv[2]||$j["added"]!==($argv[3]==="true"))exit(1);' \
    "$reply" "$kind" "$([[ "$kind" = "relate" ]] && [[ "$kind" != "${prior:-}" ]] && echo true || ([[ "$kind" = "laugh" ]] && [[ "$kind" != "${prior:-}" ]] && echo true || echo false))"
  prior="$kind"
done
php -r 'require "src/app.php";$c=require "../private/refus-insolite.php";$db=db_open($c);$s=$db->prepare("SELECT likes_count,laughs_count FROM refus WHERE id=?");$s->execute([$argv[1]]);$r=$s->fetch();if((int)$r["likes_count"]!==1||(int)$r["laughs_count"]!==1)exit(1);' "$story_id"

# Les visiteurs restent limités à 3 soumissions par heure.
# La première a été envoyée plus haut : deux succès de plus, puis 429.
post_sample_story() {
  "${base[@]}" -o "$reply" -w '%{http_code}' -X POST \
    --data-urlencode "csrf=$token" -d 'action=submit' \
    --data-urlencode 'title=La photocopieuse a encore gagné' \
    --data-urlencode 'quote_text=Votre enthousiasme est trop puissant pour notre petite équipe administrative actuelle.' \
    --data-urlencode 'context_text=Après plusieurs entretiens sérieux une réponse inattendue est arrivée dans ma boîte.' \
    -d 'category=absurde' -d 'sector=services' -d 'consent=yes' \
    http://127.0.0.1:18083/soumettre
}
for _ in 1 2; do
  status="$(post_sample_story)"
  test "$status" = '201'
done
status="$(post_sample_story)"
test "$status" = '429'
php -r '$j=json_decode(file_get_contents($argv[1]),true);if(!is_array($j)||$j["ok"]!==false)exit(1);' "$reply"

# Suppression strictement réservée à l'administrateur, avec validation explicite.
status="$("${base[@]}" -o "$reply" -w '%{http_code}' -X POST --data-urlencode "csrf=$token" \
  -d 'action=delete_story' -d "id=$story_id" -d 'confirmation=SUPPRIMER' http://127.0.0.1:18083/moderation)"
test "$status" = '403'
status="$(curl -s -b "$cookies" -c "$cookies" -o "$reply" -w '%{http_code}' \
  -X POST --data-urlencode "csrf=$token" -d 'action=login' \
  --data-urlencode 'password=Mot-de-passe-test-uniquement' http://127.0.0.1:18083/moderation)"
test "$status" = '303'
# Sur la même session et la même adresse IP, l'administrateur n'a aucune limite horaire.
for _ in 1 2 3 4 5; do
  status="$(post_sample_story)"
  test "$status" = '201'
  php -r '$j=json_decode(file_get_contents($argv[1]),true);if(!is_array($j)||$j["ok"]!==true)exit(1);' "$reply"
done
curl -s -b "$cookies" "http://127.0.0.1:18083/moderation?statut=approved" > "$reply"
grep -q "Supprimer définitivement" "$reply"
status="$(curl -s -b "$cookies" -c "$cookies" -o "$reply" -w '%{http_code}' \
  -X POST --data-urlencode "csrf=$token" -d 'action=delete_story' -d "id=$story_id" \
  -d 'confirmation=SUPPRIMER' http://127.0.0.1:18083/moderation)"
test "$status" = '303'
php -r 'require "src/app.php";$c=require "../private/refus-insolite.php";$db=db_open($c);$s=$db->prepare("SELECT COUNT(*) FROM refus WHERE id=?");$s->execute([$argv[1]]);if((int)$s->fetchColumn()!==0)exit(1);' "$story_id"
# En quittant la modération, les limites anonymes doivent s'appliquer immédiatement à nouveau.
status="$(curl -s -b "$cookies" -c "$cookies" -o "$reply" -w '%{http_code}' \
  -X POST --data-urlencode "csrf=$token" -d 'action=logout' http://127.0.0.1:18083/moderation)"
test "$status" = '303'
status="$(post_sample_story)"
test "$status" = '429'
echo 'Tests HTTP AJAX, réactions, suppression et limite admin : OK'

