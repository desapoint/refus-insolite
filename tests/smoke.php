<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/app.php';

function ensure(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException('Échec du test : ' . $message);
}
$cfg = [
    'app' => ['secret_key' => str_repeat('a', 64)],
    'database' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => 3306,
        'name' => getenv('DB_NAME') ?: 'refus_insolite',
        'username' => getenv('DB_USER') ?: 'refus_site',
        'password' => getenv('DB_PASS') ?: 'test_mariadb_password',
    ],
];
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$db = db_open($cfg);
migrate($db, $cfg['database']['name']);
migrate($db, $cfg['database']['name']);
ensure((int)$db->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn() === 2, 'deux migrations appliquées une seule fois');
foreach (['refus', 'reactions', 'laugh_reactions', 'rate_limits', 'reports'] as $table) {
    ensure((int)$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = " . $db->quote($table))->fetchColumn() === 1, 'table ' . $table);
}
$story = validated_story([
    'title'=>'Candidature incroyable',
    'quote_text'=>'Votre enthousiasme est vraiment beaucoup trop grand pour notre équipe.',
    'context_text'=>'Une journée banale après un long entretien avec plusieurs étapes.',
    'category'=>'absurde', 'sector'=>'tech', 'consent'=>'yes',
]);
ensure($story['category'] === 'absurde', 'validation formulaire');
try {
    validated_story(['title'=>'court']);
    throw new RuntimeException('Le formulaire invalide a été accepté.');
} catch (DomainException $expected) {
    // Attendu : titre/texte trop court.
}
ensure(allow_action($db, $cfg, 'test', 2, 60), 'première tentative autorisée');
ensure(allow_action($db, $cfg, 'test', 2, 60), 'deuxième tentative autorisée');
ensure(!allow_action($db, $cfg, 'test', 2, 60), 'limite atteinte');
$insert = $db->prepare('INSERT INTO refus (title, quote_text, context_text, category, sector) VALUES (?,?,?,?,?)');
$insert->execute(array_values($story));
$id = (int)$db->lastInsertId();
[$list, $total] = query_stories($db, ['category'=>'all', 'sector'=>'all', 'sort'=>'recent', 'q'=>''], 1);
ensure($total === 0, 'les témoignages en attente sont privés');
$approve = $db->prepare("UPDATE refus SET status='approved', reviewed_at=UTC_TIMESTAMP(), published_at=UTC_TIMESTAMP() WHERE id=?");
$approve->execute([$id]);
[$list, $total] = query_stories($db, ['category'=>'absurde', 'sector'=>'tech', 'sort'=>'popular', 'q'=>'incroyable'], 1);
ensure($total === 1 && (int)$list[0]['id'] === $id, 'filtrage des publications approuvées');
$relate = record_reaction($db, $id, str_repeat('b', 64), 'relate');
ensure($relate['added'] && $relate['likes_count'] === 1 && $relate['laughs_count'] === 0, 'réaction moi aussi');
$duplicate = record_reaction($db, $id, str_repeat('b', 64), 'relate');
ensure(!$duplicate['added'] && $duplicate['likes_count'] === 1, 'pas de vote doublon');
$laugh = record_reaction($db, $id, str_repeat('b', 64), 'laugh');
ensure($laugh['added'] && $laugh['likes_count'] === 1 && $laugh['laughs_count'] === 1, 'rire distinct pour le même visiteur');
$doubleLaugh = record_reaction($db, $id, str_repeat('b', 64), 'laugh');
ensure(!$doubleLaugh['added'] && $doubleLaugh['laughs_count'] === 1, 'rire unique');

$db->prepare('INSERT INTO reports (refus_id, reason) VALUES (?,?)')->execute([$id, 'privacy']);
ensure((int)$db->query('SELECT COUNT(*) FROM reports')->fetchColumn() === 1, 'signalement');
$db->prepare('DELETE FROM refus WHERE id=?')->execute([$id]);
ensure((int)$db->query('SELECT COUNT(*) FROM reports')->fetchColumn() === 0, 'cascade signalements');
ensure((int)$db->query('SELECT COUNT(*) FROM reactions')->fetchColumn() === 0, 'cascade réactions');
ensure((int)$db->query('SELECT COUNT(*) FROM laugh_reactions')->fetchColumn() === 0, 'cascade rires');
echo "Tests PDO / MariaDB : OK\n";
