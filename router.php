<?php
declare(strict_types=1);

/*
 * Router for the PHP built-in server:  php -S 0.0.0.0:8000 router.php
 *
 *   /api/...        -> api.php
 *   /               -> index.html (the homepage)
 *   existing files  -> served as-is, except private files (see below)
 *   anything else   -> custom 404 page (404.html) with a real 404 status
 */

$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');

if ($path === '/api' || str_starts_with($path, '/api/')) {
    require __DIR__ . '/api.php';
    return true;
}

/* Send the custom 404 page (or plain text for images, scripts, styles, etc.). */
function not_found(string $path): bool
{
    http_response_code(404);
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ($ext === '' || $ext === 'html' || $ext === 'htm' || $ext === 'php') {
        header('Content-Type: text/html; charset=UTF-8');
        readfile(__DIR__ . '/404.html');
    } else {
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Not found';
    }
    return true;
}

/* Never hand out secrets or server-side files, even though they sit in the project folder. */
function is_private(string $path): bool
{
    foreach (explode('/', $path) as $segment) {
        if ($segment !== '' && ($segment[0] === '.' || $segment === '..')) {
            return true; // .env, .git, .vscode, ...
        }
    }
    $first = explode('/', ltrim($path, '/'))[0];
    if (in_array($first, ['database', 'utils', 'node_modules'], true)) {
        return true;
    }
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return in_array($ext, ['php', 'sql', 'env', 'md', 'txt', 'json', 'crt', 'pem', 'key', 'log', 'ts', 'tsx', 'lock', 'patch'], true);
}

if ($path === '/' || $path === '') {
    header('Content-Type: text/html; charset=UTF-8');
    readfile(__DIR__ . '/index.html');
    return true;
}

if (is_private($path)) {
    return not_found($path);
}

$file = realpath(__DIR__ . $path);
$root = realpath(__DIR__);
if ($file !== false && $root !== false && str_starts_with($file, $root . DIRECTORY_SEPARATOR) && is_file($file)) {
    return false; // let the built-in server send the file
}

return not_found($path);
