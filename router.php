<?php
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (str_starts_with($path, '/api/')) {
    require __DIR__ . '/api.php';
    return true;
}
if ($path === '/api') {
    require __DIR__ . '/api.php';
    return true;
}

$file = realpath(__DIR__ . $path);
$root = realpath(__DIR__);
if ($file !== false && $root !== false && str_starts_with($file, $root . DIRECTORY_SEPARATOR) && is_file($file)) {
    return false;
}
if ($path === '/' || $path === '') {
    readfile(__DIR__ . '/index.html');
    return true;
}
http_response_code(404);
echo 'Not found';
return true;
