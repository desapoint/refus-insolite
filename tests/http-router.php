<?php
declare(strict_types=1);
// Routeur réservé aux tests HTTP locaux ; imite les routes de .htaccess.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path === '/' || $path === '/index.php') {
    $_GET['p'] = 'home';
} elseif (in_array($path, ['/explorer','/soumettre','/merci','/moderation'], true)) {
    $_GET['p'] = ltrim($path, '/');
} elseif (preg_match('~^/refus/([1-9][0-9]*)/?$~D', (string) $path, $matches)) {
    $_GET['p'] = 'refus';
    $_GET['id'] = $matches[1];
} else {
    http_response_code(404);
    return;
}
require dirname(__DIR__) . '/index.php';
