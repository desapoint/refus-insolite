<?php
declare(strict_types=1);

const CATEGORIES = [
    'absurde' => 'Motif absurde', 'contradictoire' => 'Contradiction',
    'fantome' => 'Sans réponse', 'salaire' => 'Salaire',
    'entretien' => 'Entretien', 'autre' => 'Autre',
];
const SECTORS = [
    'tech' => 'Tech / numérique', 'services' => 'Services',
    'commerce' => 'Commerce', 'sante' => 'Santé',
    'industrie' => 'Industrie', 'autre' => 'Autre',
];

function e(mixed $value): string { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function link_to(string $page = 'home', array $params = []): string {
    // URL absolues pour les liens et formulaires sous /refus/123.
    $path = match ($page) {
        'home' => '/',
        'explorer' => '/explorer',
        'soumettre' => '/soumettre',
        'merci' => '/merci',
        'moderation' => '/moderation',
        'refus' => isset($params['id']) && filter_var($params['id'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]])
            ? '/refus/' . (int)$params['id'] : '/explorer',
        default => '/',
    };
    unset($params['id']);
    return $path . ($params === [] ? '' : '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986));
}
function redirect_to(string $page, array $params = []): never {
    header('Location: ' . link_to($page, $params), true, 303);
    exit;
}
function submit_async_requested(): bool {
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
        && ($_POST['action'] ?? null) === 'submit'
        && is_string($accept) && str_contains(strtolower($accept), 'application/json');
}
function submit_reply(int $status, bool $ok, string $message): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    echo json_encode(['ok' => $ok, 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}
function fail_public(int $code, string $message): never {
    if (submit_async_requested()) submit_reply($code, false, $message);
    http_response_code($code);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="fr"><meta charset="utf-8"><title>Refus Insolites</title><body style="font:16px system-ui;max-width:640px;margin:12vh auto;padding:20px"><h1>Refus Insolites</h1><p>' . e($message) . '</p></body></html>';
    exit;
}
function session_start_secure(): void {
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    session_name('ri_session');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
    if (!isset($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">'; }
function verify_csrf(): void {
    $token = $_POST['csrf'] ?? '';
    if (!is_string($token) || !hash_equals((string) ($_SESSION['csrf'] ?? ''), $token)) fail_public(403, 'Session expirée. Actualisez la page et réessayez.');
}
function config_load(): array {
    // Configuration dans le private/ du domaine HestiaCP, hors public_html.
    $path = dirname(__DIR__, 2) . '/private/refus-insolite.php';
    if (!is_file($path)) fail_public(503, 'Le site est momentanément indisponible. Réessayez plus tard.');
    $config = require $path;
    if (!is_array($config)) fail_public(503, 'Le site est momentanément indisponible. Réessayez plus tard.');
    $secret = $config['app']['secret_key'] ?? '';
    if (!is_string($secret) || !preg_match('/\A[a-f0-9]{64}\z/iD', $secret)) fail_public(503, 'Le site est momentanément indisponible. Réessayez plus tard.');
    return $config;
}
function db_open(array $cfg): PDO {
    $d = $cfg['database'] ?? [];
    foreach (['host','name','username','password'] as $key) {
        if (!isset($d[$key]) || !is_string($d[$key])) throw new RuntimeException('Configuration de base de données incomplète.');
    }
    // Aucun composant du DSN ne doit pouvoir y injecter un nouveau paramètre.
    foreach (['host','name'] as $key) if (preg_match('/[;\r\n]/', $d[$key])) throw new RuntimeException('DSN invalide.');
    $port = filter_var($d['port'] ?? 3306, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
    if ($port === false) throw new RuntimeException('Port MariaDB invalide.');
    $dsn = 'mysql:host=' . $d['host'] . ';port=' . $port . ';dbname=' . $d['name'] . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $d['username'], $d['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}
function migrate(PDO $pdo, string $dbName): void {
    $lock = 'ri_migrations_' . substr(hash('sha256', $dbName), 0, 24);
    $stmt = $pdo->prepare('SELECT GET_LOCK(?, 10)');
    $stmt->execute([$lock]);
    if ((int) $stmt->fetchColumn() !== 1) throw new RuntimeException('Verrou de migration indisponible.');
    try {
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS schema_migrations (
 version VARCHAR(100) NOT NULL PRIMARY KEY,
 applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $done = array_fill_keys($pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN), true);
        foreach (glob(dirname(__DIR__) . '/migrations/[0-9]*.php') ?: [] as $file) {
            $version = basename($file, '.php');
            if (!preg_match('/\A[0-9]{3}_[a-z0-9_]+\z/D', $version)) continue;
            if (isset($done[$version])) continue;
            $migration = require $file;
            if (!is_callable($migration)) throw new RuntimeException('Migration invalide : ' . $version);
            // DDL MariaDB = commit implicite ; les étapes sont idempotentes pour reprise après interruption.
            $migration($pdo);
            $record = $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (?)');
            $record->execute([$version]);
        }
    } finally {
        $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute([$lock]);
    }
}
function source_hash(array $cfg): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    return hash_hmac('sha256', (string)$ip, $cfg['app']['secret_key']);
}
// Limite par adresse hachée, verrouillage MariaDB pour éviter les courses concurrentes.
function allow_action(PDO $db, array $cfg, string $action, int $max, int $minutes): bool {
    $hash = source_hash($cfg);
    $lock = 'ri_limit_' . substr(hash('sha256', $action . ':' . $hash), 0, 36);
    $s = $db->prepare('SELECT GET_LOCK(?, 4)'); $s->execute([$lock]);
    if ((int) $s->fetchColumn() !== 1) return false;
    try {
        $start = gmdate('Y-m-d H:i:s', time() - $minutes * 60);
        $check = $db->prepare('SELECT COUNT(*) FROM rate_limits WHERE action = ? AND source_hash = ? AND created_at >= ?');
        $check->execute([$action, $hash, $start]);
        if ((int)$check->fetchColumn() >= $max) return false;
        $insert = $db->prepare('INSERT INTO rate_limits(action, source_hash) VALUES (?, ?)');
        $insert->execute([$action, $hash]);
        return true;
    } finally {
        $release = $db->prepare('SELECT RELEASE_LOCK(?)'); $release->execute([$lock]);
    }
}
function flash(string $message, bool $ok = true): void { $_SESSION['flash'] = ['message' => $message, 'ok' => $ok]; }
function take_flash(): ?array { $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f; }
function admin_logged(): bool {
    if (empty($_SESSION['admin_authenticated'])) return false;
    if ((int)($_SESSION['admin_last'] ?? 0) < time() - 7200) {
        unset($_SESSION['admin_authenticated'], $_SESSION['admin_last']);
        return false;
    }
    $_SESSION['admin_last'] = time();
    return true;
}
function admin_ready(array $cfg): bool {
    $hash = $cfg['app']['admin_password_hash'] ?? '';
    return is_string($hash) && $hash !== '' && password_get_info($hash)['algo'] !== null;
}
function query_stories(PDO $db, array $filters, int $page, int $perPage = 12): array {
    $where = ["status = 'approved'"];
    $bindings = [];
    if (isset(CATEGORIES[$filters['category']])) { $where[] = 'category = ?'; $bindings[] = $filters['category']; }
    if (isset(SECTORS[$filters['sector']])) { $where[] = 'sector = ?'; $bindings[] = $filters['sector']; }
    if ($filters['q'] !== '') {
        $escaped = str_replace(['\\','%','_'], ['\\\\','\\%','\\_'], $filters['q']);
        $where[] = "(title LIKE ? OR quote_text LIKE ? OR context_text LIKE ?)";
        array_push($bindings, ...array_fill(0, 3, '%' . $escaped . '%'));
    }
    $filter = implode(' AND ', $where);
    $s = $db->prepare('SELECT COUNT(*) FROM refus WHERE ' . $filter); $s->execute($bindings);
    $count = (int)$s->fetchColumn();
    $page = max(1, min($page, max(1, (int)ceil($count / $perPage))));
    $order = match($filters['sort']) {
        'popular' => 'likes_count DESC, published_at DESC, id DESC',
        'oldest' => 'published_at ASC, id ASC',
        default => 'published_at DESC, id DESC',
    };
    $s = $db->prepare('SELECT * FROM refus WHERE ' . $filter . ' ORDER BY ' . $order . ' LIMIT ? OFFSET ?');
    $i = 1;
    foreach($bindings as $b) $s->bindValue($i++, $b, PDO::PARAM_STR);
    $s->bindValue($i++, $perPage, PDO::PARAM_INT);
    $s->bindValue($i, ($page-1)*$perPage, PDO::PARAM_INT);
    $s->execute();
    return [$s->fetchAll(), $count, $page];
}
function validated_story(array $input): array {
    $fields = ['title','quote_text','context_text','category','sector'];
    $out = [];
    foreach ($fields as $field) $out[$field] = is_string($input[$field] ?? null) ? trim($input[$field]) : '';
    $maxes = ['title'=>160, 'quote_text'=>1500, 'context_text'=>3000];
    foreach ($maxes as $f => $max) {
        if (mb_strlen($out[$f], 'UTF-8') < ($f === 'title' ? 8 : 20) || mb_strlen($out[$f], 'UTF-8') > $max) throw new DomainException('Vérifiez les longueurs du titre, du refus et du contexte.');
    }
    if (!isset(CATEGORIES[$out['category']]) || !isset(SECTORS[$out['sector']])) throw new DomainException('Choisissez une catégorie et un secteur valides.');
    if (($input['consent'] ?? '') !== 'yes') throw new DomainException('Vous devez confirmer l’anonymisation du témoignage.');
    $full = implode(' ', [$out['title'],$out['quote_text'],$out['context_text']]);
    if (preg_match('~(?:https?://|www\.|[\w.+-]+@[\w.-]+\.[a-z]{2,})~iu', $full)) throw new DomainException('Retirez les adresses e-mail et les liens avant de soumettre.');
    return $out;
}

