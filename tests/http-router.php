<?php
declare(strict_types=1);
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path === '/soumettre') {
    $_GET['p'] = 'soumettre';
} elseif ($path === '/') {
    $_GET['p'] = 'home';
} else {
    http_response_code(404);
    return;
}
require dirname(__DIR__) . '/index.php';
