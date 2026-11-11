<?php
declare(strict_types=1);

/*
 * Reset a portal password from the command line.
 *
 *   php reset-password.php admin1 NewPassword123
 *   php reset-password.php --all password      (every account)
 *
 * Uses the same database settings as api.php: DB_* environment variables,
 * or DATABASE_URL in the .env file beside this script.
 */

if (PHP_SAPI !== 'cli') {
    exit("Run this from a terminal, not a browser.\n");
}

[$script, $who, $newPassword] = array_pad($argv, 3, null);

if (!$who || $newPassword === null) {
    exit("Usage: php reset-password.php <username|--all> <new password>\n");
}

if (strlen($newPassword) < 8) {
    exit("The new password must be at least 8 characters.\n");
}

$host = getenv('DB_HOST') ?: '';
$port = getenv('DB_PORT') ?: '5432';
$name = getenv('DB_NAME') ?: 'postgres';
$user = getenv('DB_USER') ?: '';
$password = getenv('DB_PASSWORD') ?: '';

if ($host === '' || $user === '' || $password === '') {
    $envFile = __DIR__ . DIRECTORY_SEPARATOR . '.env';

    foreach (is_readable($envFile) ? file($envFile, FILE_IGNORE_NEW_LINES) : [] as $line) {
        $line = trim($line);

        if (!str_starts_with($line, 'DATABASE_URL=')) {
            continue;
        }

        $parts = parse_url(trim(substr($line, 13), " \t\"'"));

        if ($parts && !empty($parts['host']) && !empty($parts['user']) && isset($parts['pass'])) {
            $host = $host ?: $parts['host'];
            $port = (string)($parts['port'] ?? $port);
            $name = ltrim($parts['path'] ?? '', '/') ?: $name;
            $user = $user ?: urldecode($parts['user']);
            $password = $password ?: urldecode($parts['pass']);
        }

        break;
    }
}

if ($host === '' || $user === '' || $password === '') {
    exit("Database settings not found. Check DATABASE_URL in your .env file.\n");
}

try {
    $pdo = new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=%s;sslmode=require;connect_timeout=8', $host, $port, $name),
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $accounts = $who === '--all'
        ? $pdo->query('select user_id, username from public.portal_credentials order by user_id')->fetchAll(PDO::FETCH_ASSOC)
        : (function () use ($pdo, $who) {
            $statement = $pdo->prepare(
                'select user_id, username from public.portal_credentials where lower(username) = lower(:u)'
            );
            $statement->execute(['u' => $who]);
            return $statement->fetchAll(PDO::FETCH_ASSOC);
        })();

    if (!$accounts) {
        exit("No account named \"$who\". Run this in Supabase to see the names:\n" .
            "  select username from public.portal_credentials;\n");
    }

    $update = $pdo->prepare(
        'update public.portal_credentials
            set salt = :salt, password_hash = :hash, updated_at = now()
          where user_id = :id'
    );

    foreach ($accounts as $account) {
        $salt = bin2hex(random_bytes(16));

        $update->execute([
            'salt' => $salt,
            'hash' => hash_pbkdf2('sha256', $newPassword, $salt, 120000, 64),
            'id' => $account['user_id'],
        ]);

        echo "Password reset for {$account['username']}\n";
    }
} catch (Throwable $error) {
    exit('Failed: ' . $error->getMessage() . "\n");
}
