<?php
declare(strict_types=1);

/*
 * Exam Portal PHP API
 * PHP 8.1+ with PDO_PGSQL
 *
 * Uses the existing Supabase tables:
 *   public.portal_state
 *   public.portal_credentials
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/**
 * Send a JSON response and stop execution.
 */
function respond(int $status, array $body): never
{
    http_response_code($status);

    echo json_encode(
        $body,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    exit;
}

/**
 * Create or reuse the Supabase PostgreSQL connection.
 */
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

/**
 * Read a JSON request body.
 */
function request_json(): array
{
    $value = json_decode(file_get_contents('php://input') ?: '', true);

    return is_array($value) ? $value : [];
}

/**
 * Read the existing portal snapshot from Supabase.
 */
function load_state(PDO $pdo, bool $lock = false): ?array
{
    $sql = '
        select state
        from public.portal_state
        where singleton = true
    ';

    if ($lock) {
        $sql .= ' for update';
    }

    $row = $pdo->query($sql)->fetch();

    if (!$row) {
        return null;
    }

    $state = is_string($row['state'])
        ? json_decode($row['state'], true)
        : $row['state'];

    return is_array($state) ? $state : null;
}

/**
 * Save the portal snapshot without changing or removing the table.
 */
function save_state(PDO $pdo, array $state): void
{
    $statement = $pdo->prepare(
        'insert into public.portal_state
            (singleton, state, updated_at)
         values
            (true, CAST(:state AS jsonb), now())
         on conflict (singleton) do update
         set state = excluded.state,
             updated_at = now()'
    );

    $statement->execute([
        ':state' => json_encode(
            $state,
            JSON_UNESCAPED_SLASHES |
            JSON_UNESCAPED_UNICODE |
            JSON_THROW_ON_ERROR
        ),
    ]);
}

/**
 * Return the signed-in user saved in the PHP session.
 */
function current_user(): ?array
{
    return isset($_SESSION['portal_user']) &&
        is_array($_SESSION['portal_user'])
        ? $_SESSION['portal_user']
        : null;
}

/**
 * Require a signed-in user.
 */
function require_login(): array
{
    $user = current_user();

    if (!$user) {
        respond(401, [
            'message' => 'Please sign in again.',
        ]);
    }

    return $user;
}

/**
 * Require an administrator.
 */
function require_admin(): array
{
    $user = require_login();

    if (($user['role'] ?? '') !== 'admin') {
        respond(403, [
            'message' => 'Administrator access is required.',
        ]);
    }

    return $user;
}

/**
 * Verify the current PHP PBKDF2 password hashes.
 * Also attempts to verify older Node scrypt hashes when Sodium supports it.
 */
function verify_password(
    string $password,
    string $salt,
    string $storedHash
): bool {
    $pbkdf2Hash = hash_pbkdf2(
        'sha256',
        $password,
        $salt,
        120000,
        64
    );

    if (hash_equals($storedHash, $pbkdf2Hash)) {
        return true;
    }

    if (
        preg_match('/^[a-f0-9]{128}$/i', $storedHash) &&
        function_exists('sodium_crypto_pwhash_scryptsalsa208sha256')
    ) {
        try {
            $scryptHash =
                sodium_crypto_pwhash_scryptsalsa208sha256(
                    64,
                    $password,
                    $salt,
                    524288,
                    16777216
                );

            return hash_equals(
                $storedHash,
                bin2hex($scryptHash)
            );
        } catch (Throwable $error) {
            error_log(
                'Scrypt verification failed: ' . $error->getMessage()
            );
        }
    }

    return false;
}

/*
 * PHP session cookie configuration.
 * The browser and PHP API must use the same site/origin.
 */
$isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_start();

/*
 * Resolve the request path.
 */
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url(
    $_SERVER['REQUEST_URI'] ?? '/',
    PHP_URL_PATH
) ?: '/';

$base = rtrim(
    dirname($_SERVER['SCRIPT_NAME'] ?? ''),
    '/\\'
);

if ($base !== '' && str_starts_with($path, $base)) {
    $path = substr($path, strlen($base));
}

if ($path === '') {
    $path = '/';
}

try {
    /*
     * Database connection check.
     */
    if ($method === 'GET' && $path === '/api/health') {
        $row = db()
            ->query(
                'select current_database() as database,
                        now() as connected_at'
            )
            ->fetch();

        respond(200, [
            'ok' => true,
            'database' => $row['database'],
            'connectedAt' => $row['connected_at'],
        ]);
    }

    /*
     * Register a new user.
     * The role is assigned later by an admin.
     */
    if ($method === 'POST' && $path === '/api/auth/register') {
        $input = request_json();

        $name = trim((string)($input['name'] ?? ''));
        $username = trim((string)($input['username'] ?? ''));
        $password = (string)($input['password'] ?? '');

        if ($name === '' || $username === '' || $password === '') {
            respond(400, [
                'message' => 'Name, username, and password are required.',
            ]);
        }

        if (strlen($name) > 120 || strlen($username) > 80) {
            respond(400, [
                'message' => 'Name or username is too long.',
            ]);
        }

        if (strlen($password) < 8) {
            respond(400, [
                'message' => 'Password must be at least 8 characters.',
            ]);
        }

        $pdo = db();
        $pdo->beginTransaction();

        try {
            $state = load_state($pdo, true);

            if (
                !$state ||
                !isset($state['users']) ||
                !is_array($state['users'])
            ) {
                throw new RuntimeException(
                    'Portal data is not initialized. Ask the administrator to open the portal first.'
                );
            }

            /*
             * Check usernames already in the portal snapshot.
             */
            foreach ($state['users'] as $existingUser) {
                if (
                    isset($existingUser['username']) &&
                    strcasecmp(
                        (string)$existingUser['username'],
                        $username
                    ) === 0
                ) {
                    $pdo->rollBack();

                    respond(409, [
                        'message' => 'That username is already taken.',
                    ]);
                }
            }

            /*
             * Also check the credentials table.
             */
            $check = $pdo->prepare(
                'select 1
                 from public.portal_credentials
                 where lower(username) = lower(:username)
                 limit 1'
            );

            $check->execute([
                ':username' => $username,
            ]);

            if ($check->fetchColumn()) {
                $pdo->rollBack();

                respond(409, [
                    'message' => 'That username is already taken.',
                ]);
            }

            /*
             * Use the next numeric user ID to match existing project code.
             */
            $id = 1;

            foreach ($state['users'] as $existingUser) {
                if (
                    isset($existingUser['id']) &&
                    is_numeric($existingUser['id'])
                ) {
                    $id = max(
                        $id,
                        (int)$existingUser['id'] + 1
                    );
                }
            }

            $salt = bin2hex(random_bytes(16));

            $passwordHash = hash_pbkdf2(
                'sha256',
                $password,
                $salt,
                120000,
                64
            );

            /*
             * New accounts are pending and have no active role yet.
             */
            $state['users'][] = [
                'id' => $id,
                'name' => $name,
                'username' => $username,
                'role' => 'pending',
                'status' => 'pending',
            ];

            save_state($pdo, $state);

            $saveCredential = $pdo->prepare(
                'insert into public.portal_credentials
                    (user_id, username, salt, password_hash)
                 values
                    (:id, :username, :salt, :hash)'
            );

            $saveCredential->execute([
                ':id' => (string)$id,
                ':username' => $username,
                ':salt' => $salt,
                ':hash' => $passwordHash,
            ]);

            $pdo->commit();

            respond(201, [
                'ok' => true,
                'message' => 'Registration submitted. Please wait for admin approval.',
            ]);
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log(
                'Registration failed: ' . $error->getMessage()
            );

            respond(500, [
                'message' => 'Could not submit registration.',
            ]);
        }
    }

    /*
     * Sign in.
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

        $pdo = db();

        $query = $pdo->prepare(
            'select user_id, salt, password_hash
             from public.portal_credentials
             where username = :username'
        );

        $query->execute([
            ':username' => $username,
        ]);

        $credential = $query->fetch();

        if (
            !$credential ||
            !verify_password(
                $password,
                (string)$credential['salt'],
                (string)$credential['password_hash']
            )
        ) {
            respond(401, [
                'message' => 'Invalid username or password.',
            ]);
        }

        $state = load_state($pdo);
        $user = null;

        foreach (($state['users'] ?? []) as $savedUser) {
            if (
                (string)($savedUser['id'] ?? '') ===
                (string)$credential['user_id']
            ) {
                $user = $savedUser;
                break;
            }
        }

        if (!$user) {
            respond(401, [
                'message' => 'Account is missing from portal data.',
            ]);
        }

        /*
         * Legacy accounts without status are treated as approved.
         * Explicit pending accounts cannot sign in.
         */
        if (
            ($user['status'] ?? 'approved') !== 'approved' ||
            ($user['role'] ?? '') === 'pending'
        ) {
            respond(403, [
                'code' => 'PENDING_APPROVAL',
                'message' => 'Your account is waiting for admin approval.',
            ]);
        }

        if (!in_array(
            $user['role'] ?? '',
            ['admin', 'faculty', 'student'],
            true
        )) {
            respond(403, [
                'message' => 'This account has no valid role. Contact the administrator.',
            ]);
        }

        session_regenerate_id(true);

        $_SESSION['portal_user'] = [
            'id' => $user['id'],
            'name' => $user['name'] ?? '',
            'username' => $user['username'] ?? $username,
            'role' => $user['role'],
        ];

        respond(200, [
            'user' => $_SESSION['portal_user'],
        ]);
    }

    /*
     * Sign out.
     */
    if ($method === 'POST' && $path === '/api/auth/logout') {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();

            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'] ?? '',
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();

        respond(200, ['ok' => true]);
    }

    /*
     * List pending registrations.
     * Only admins can access this endpoint.
     */
    if ($method === 'GET' && $path === '/api/admin/pending-users') {
        require_admin();

        $state = load_state(db());

        $pendingUsers = array_values(array_map(
            static function (array $user): array {
                return [
                    'id' => $user['id'],
                    'name' => $user['name'] ?? '',
                    'username' => $user['username'] ?? '',
                ];
            },
            array_filter(
                $state['users'] ?? [],
                static function (array $user): bool {
                    return ($user['status'] ?? '') === 'pending';
                }
            )
        ));

        respond(200, [
            'users' => $pendingUsers,
        ]);
    }

    /*
     * Approve a pending user and assign a role.
     * JSON body example: {"role":"student"}
     */
    if (
        $method === 'POST' &&
        preg_match(
            '#^/api/admin/users/([^/]+)/approve$#',
            $path,
            $matches
        )
    ) {
        require_admin();

        $input = request_json();
        $role = (string)($input['role'] ?? '');

        if (!in_array(
            $role,
            ['admin', 'faculty', 'student'],
            true
        )) {
            respond(400, [
                'message' => 'Choose admin, faculty, or student.',
            ]);
        }

        $userId = rawurldecode($matches[1]);
        $pdo = db();
        $pdo->beginTransaction();

        try {
            $state = load_state($pdo, true);

            if (
                !$state ||
                !isset($state['users']) ||
                !is_array($state['users'])
            ) {
                throw new RuntimeException(
                    'Portal user data is unavailable.'
                );
            }

            $found = false;

            foreach ($state['users'] as &$person) {
                if ((string)($person['id'] ?? '') === $userId) {
                    if (($person['status'] ?? '') !== 'pending') {
                        $pdo->rollBack();

                        respond(409, [
                            'message' => 'This account is no longer pending.',
                        ]);
                    }

                    $person['role'] = $role;
                    $person['status'] = 'approved';
                    $found = true;
                    break;
                }
            }

            unset($person);

            if (!$found) {
                $pdo->rollBack();

                respond(404, [
                    'message' => 'Pending account not found.',
                ]);
            }

            save_state($pdo, $state);
            $pdo->commit();

            respond(200, [
                'ok' => true,
                'message' => 'Account approved.',
                'role' => $role,
            ]);
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log(
                'Approval failed: ' . $error->getMessage()
            );

            respond(500, [
                'message' => 'Could not approve this account.',
            ]);
        }
    }

    /*
     * Load saved portal data for a signed-in user.
     */
    if ($method === 'GET' && $path === '/api/app-state') {
        require_login();

        respond(200, [
            'state' => load_state(db()),
        ]);
    }

    /*
     * Save portal data.
     * The users array is taken from Supabase so browser data cannot add users
     * or change account roles through this endpoint.
     */
    if ($method === 'PUT' && $path === '/api/app-state') {
        require_login();

        $input = request_json();
        $incomingState = $input['state'] ?? null;

        if (!is_array($incomingState)) {
            respond(400, [
                'message' => 'Invalid portal data.',
            ]);
        }

        foreach (
            ['subjects', 'exams', 'attempts', 'retakes'] as $key
        ) {
            if (
                !isset($incomingState[$key]) ||
                !is_array($incomingState[$key])
            ) {
                respond(400, [
                    'message' => 'Invalid portal data.',
                ]);
            }
        }

        $pdo = db();
        $pdo->beginTransaction();

        try {
            $savedState = load_state($pdo, true);

            if (
                !$savedState ||
                !isset($savedState['users']) ||
                !is_array($savedState['users'])
            ) {
                throw new RuntimeException(
                    'Saved portal data is unavailable.'
                );
            }

            // Keep the database user list, including assigned roles/statuses.
            $incomingState['users'] = $savedState['users'];

            save_state($pdo, $incomingState);
            $pdo->commit();

            respond(200, ['ok' => true]);
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log(
                'Could not save portal state: ' . $error->getMessage()
            );

            respond(500, [
                'message' => 'Could not save portal data.',
            ]);
        }
    }

    /*
     * Read-only subjects endpoint.
     */
    if ($method === 'GET' && $path === '/api/subjects') {
        require_login();

        $rows = db()
            ->query(
                'select id, code, name, description
                 from public.subjects
                 order by code'
            )
            ->fetchAll();

        respond(200, $rows);
    }

    respond(404, [
        'message' => 'Not found.',
    ]);
} catch (Throwable $error) {
    error_log('API error: ' . $error->getMessage());

    respond(503, [
        'message' => 'PHP backend or database is unavailable. Check PHP PDO_PGSQL and database settings.',
    ]);
}