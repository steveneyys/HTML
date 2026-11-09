<?php
declare(strict_types=1);

/*
 * PHP API for the existing Exam Portal frontend.
 * Requires PHP 8.1+ with PDO_PGSQL.
 * For local development only.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode(
        $body,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    exit;
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = getenv('DB_HOST') ?: '';
    $port = getenv('DB_PORT') ?: '5432';
    $name = getenv('DB_NAME') ?: 'postgres';
    $user = getenv('DB_USER') ?: '';
    $password = getenv('DB_PASSWORD') ?: '';

    if ($host === '' || $user === '' || $password === '') {
        throw new RuntimeException(
            'Database environment variables are missing.'
        );
    }

    $dsn = sprintf(
        'pgsql:host=%s;port=%s;dbname=%s;sslmode=require;connect_timeout=8',
        $host,
        $port,
        $name
    );

    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

function request_json(): array
{
    $value = json_decode(file_get_contents('php://input') ?: '', true);
    return is_array($value) ? $value : [];
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

$base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
if ($base !== '' && str_starts_with($path, $base)) {
    $path = substr($path, strlen($base));
}
if ($path === '') {
    $path = '/';
}

try {
    /*
     * Database connection check
     */
    if ($method === 'GET' && $path === '/api/health') {
        $row = db()
            ->query('select current_database() as database, now() as connected_at')
            ->fetch();

        respond(200, [
            'ok' => true,
            'database' => $row['database'],
            'connectedAt' => $row['connected_at'],
        ]);
    }

    /*
     * Load saved portal data
     */
    if ($method === 'GET' && $path === '/api/app-state') {
        $row = db()
            ->query('select state from public.portal_state where singleton = true')
            ->fetch();

        $state = null;
        if ($row) {
            $state = is_string($row['state'])
                ? json_decode($row['state'], true)
                : $row['state'];
        }

        respond(200, ['state' => $state]);
    }

    /*
     * Save portal data
     */
    if ($method === 'PUT' && $path === '/api/app-state') {
        $input = request_json();
        $state = $input['state'] ?? null;

        if (!is_array($state)) {
            respond(400, ['message' => 'Invalid portal data.']);
        }

        foreach (['users', 'subjects', 'exams', 'attempts', 'retakes'] as $key) {
            if (!isset($state[$key]) || !is_array($state[$key])) {
                respond(400, ['message' => 'Invalid portal data.']);
            }
        }

        $pdo = db();
        $pdo->beginTransaction();

        try {
            $upsertCredential = $pdo->prepare(
                'insert into public.portal_credentials
                    (user_id, username, salt, password_hash)
                 values
                    (:id, :username, :salt, :hash)
                 on conflict (user_id) do update set
                    username = excluded.username,
                    salt = excluded.salt,
                    password_hash = excluded.password_hash,
                    updated_at = now()'
            );

            $updateUsername = $pdo->prepare(
                'update public.portal_credentials
                 set username = :username, updated_at = now()
                 where user_id = :id'
            );

            $findCredential = $pdo->prepare(
                'select 1 from public.portal_credentials where user_id = :id'
            );

            foreach ($state['users'] as &$person) {
                if (
                    !is_array($person) ||
                    !isset($person['id'], $person['username'])
                ) {
                    throw new RuntimeException('Invalid user record.');
                }

                $userId = (string)$person['id'];

                if (
                    isset($person['password']) &&
                    is_string($person['password']) &&
                    $person['password'] !== ''
                ) {
                    $salt = bin2hex(random_bytes(16));
                    $passwordHash = hash_pbkdf2(
                        'sha256',
                        $person['password'],
                        $salt,
                        120000,
                        64
                    );

                    $upsertCredential->execute([
                        ':id' => $userId,
                        ':username' => (string)$person['username'],
                        ':salt' => $salt,
                        ':hash' => $passwordHash,
                    ]);
                } else {
                    $updateUsername->execute([
                        ':username' => (string)$person['username'],
                        ':id' => $userId,
                    ]);

                    $findCredential->execute([':id' => $userId]);

                    if (!$findCredential->fetchColumn()) {
                        throw new RuntimeException(
                            'A user has no saved password. Set a password for that account in the Admin page.'
                        );
                    }
                }

                // Passwords are stored separately as hashes, not in portal_state.
                unset($person['password']);
            }
            unset($person);

            // Save the portal data without deleting other credential rows.
            $saveState = $pdo->prepare(
                'insert into public.portal_state (singleton, state, updated_at)
                 values (true, CAST(:state AS jsonb), now())
                 on conflict (singleton) do update set
                    state = excluded.state,
                    updated_at = now()'
            );

            $saveState->execute([
                ':state' => json_encode(
                    $state,
                    JSON_UNESCAPED_SLASHES |
                    JSON_UNESCAPED_UNICODE |
                    JSON_THROW_ON_ERROR
                ),
            ]);

            $pdo->commit();
            respond(200, ['ok' => true]);
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('Could not save portal state: ' . $error->getMessage());

            respond(500, [
                'message' => 'Could not save portal data. Check the database migration and account passwords.',
            ]);
        }
    }

    /*
     * Login
     */
    if ($method === 'POST' && $path === '/api/auth/login') {
        $input = request_json();

        $username = trim((string)($input['username'] ?? ''));
        $password = (string)($input['password'] ?? '');

        if ($username === '' || $password === '') {
            respond(400, [
                'message' => 'Username and password are required.',
            ]);
        }

        $query = db()->prepare(
            'select user_id, salt, password_hash
             from public.portal_credentials
             where username = :username'
        );
        $query->execute([':username' => $username]);

        $credential = $query->fetch();

        if (!$credential) {
            respond(401, ['message' => 'Invalid username or password.']);
        }

        $storedHash = (string)$credential['password_hash'];
        $salt = (string)$credential['salt'];

        // First check PHP PBKDF2 hashes.
        $providedPbkdf2 = hash_pbkdf2(
            'sha256',
            $password,
            $salt,
            120000,
            64
        );

        $valid = hash_equals($storedHash, $providedPbkdf2);

        // If that does not match, check the scrypt hashes made by server.js.
        if (
            !$valid &&
            preg_match('/^[a-f0-9]{128}$/i', $storedHash)
        ) {
            if (!function_exists('sodium_crypto_pwhash_scryptsalsa208sha256')) {
                error_log(
                    'PHP Sodium extension is unavailable; cannot check the old Node scrypt password.'
                );
            } else {
                try {
                    $providedScrypt =
                        sodium_crypto_pwhash_scryptsalsa208sha256(
                            64,
                            $password,
                            $salt,
                            524288,
                            16777216
                        );

                    $valid = hash_equals(
                        $storedHash,
                        bin2hex($providedScrypt)
                    );
                } catch (Throwable $error) {
                    error_log(
                        'Scrypt verification failed: ' . $error->getMessage()
                    );
                }
            }
        }

        if (!$valid) {
            respond(401, ['message' => 'Invalid username or password.']);
        }

        $query = db()->prepare(
            "select user_item.person as user
             from public.portal_state p,
             lateral jsonb_array_elements(p.state->'users') as user_item(person)
             where p.singleton = true
               and user_item.person->>'id' = :id"
        );
        $query->execute([
            ':id' => (string)$credential['user_id'],
        ]);

        $row = $query->fetch();

        if (!$row) {
            respond(401, [
                'message' => 'Account is missing from portal data.',
            ]);
        }

        $user = is_string($row['user'])
            ? json_decode($row['user'], true)
            : $row['user'];

        respond(200, ['user' => $user]);
    }

    /*
     * Read-only subjects endpoint
     */
    if ($method === 'GET' && $path === '/api/subjects') {
        $rows = db()
            ->query(
                'select id, code, name, description
                 from public.subjects
                 order by code'
            )
            ->fetchAll();

        respond(200, $rows);
    }

    respond(404, ['message' => 'Not found.']);
} catch (Throwable $error) {
    error_log('API error: ' . $error->getMessage());

    respond(503, [
        'message' => 'PHP backend or database is unavailable. Check PHP PDO_PGSQL and database settings.',
    ]);
}