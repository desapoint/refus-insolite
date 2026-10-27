<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/app.php';

function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException('Test des routes : ' . $message);
}
check(link_to() === '/', 'accueil');
check(link_to('explorer', ['q'=>'RH & CDI', 'page'=>3]) === '/explorer?q=RH%20%26%20CDI&page=3', 'catalogue et filtres');
check(link_to('refus', ['id'=>42]) === '/refus/42', 'fiche');
check(link_to('refus', ['id'=>42, 'source'=>'liste']) === '/refus/42?source=liste', 'fiche avec paramètres');
check(link_to('soumettre') === '/soumettre', 'soumission');
check(link_to('moderation') === '/moderation', 'modération');
check(link_to('merci') === '/merci', 'confirmation');
check(link_to('refus', ['id'=>'invalide']) === '/explorer', 'identifiant invalide');
$rules = file_get_contents(dirname(__DIR__) . '/.htaccess');
check(is_string($rules) && str_contains($rules, 'mod_rewrite'), 'Apache protégé');
check(str_contains($rules, 'RewriteRule ^refus/'), 'réécriture des refus');
check(str_contains($rules, 'RewriteRule ^assets/'), 'ressources');
check(str_contains($rules, 'RewriteRule ^ - [R=404,END]'), 'routes inconnues bloquées');
$privateConfig = dirname(__DIR__, 2) . '/private/refus-insolite.php';
check(!str_starts_with($privateConfig, dirname(__DIR__) . '/'), 'config hors public_html');
echo "Tests URL propres / HestiaCP : OK\n";
