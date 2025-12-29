<?php
declare(strict_types=1);

use BoringBot\Utils\Config;

require __DIR__ . '/../src/autoload.php';

$root = dirname(__DIR__);
$cfg = Config::load($root);

$user = (string)(getenv('DASHBOARD_USER') ?: 'admin');
$pass = (string)(getenv('DASHBOARD_PASS') ?: '');

if ($pass === '') {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Dashboard disabled: set DASHBOARD_PASS in config/.env\n";
    exit;
}

$providedUser = $_SERVER['PHP_AUTH_USER'] ?? '';
$providedPass = $_SERVER['PHP_AUTH_PW'] ?? '';

if (!hash_equals($user, $providedUser) || !hash_equals($pass, $providedPass)) {
    header('WWW-Authenticate: Basic realm="boringbot dashboard"');
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Unauthorized\n";
    exit;
}

