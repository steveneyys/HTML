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
 * Read DATABASE_URL from the .env file beside api.php, for example
 * DATABASE_URL=postgresql://user:password@host:5432/postgres
 * A password containing @ : / ? # or spaces must be URL-encoded there.
 */
function database_settings_from_env_file(): ?array
{
    $file = __DIR__ . DIRECTORY_SEPARATOR . '.env';

    if (!is_readable($file)) {
        return null;
    }

    foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);

        if (!str_starts_with($line, 'DATABASE_URL=')) {
            continue;
        }

        $url = trim(substr($line, strlen('DATABASE_URL=')), " \t\"'");
        $parts = parse_url($url);

        if (
            !$parts ||
            empty($parts['host']) ||
            empty($parts['user']) ||
            !isset($parts['pass'])
        ) {
            return null;
        }

        return [
            'host' => $parts['host'],
            'port' => (string)($parts['port'] ?? '5432'),
            'name' => ltrim($parts['path'] ?? '', '/') ?: 'postgres',
            'user' => urldecode($parts['user']),
            'password' => urldecode($parts['pass']),
        ];
    }

    return null;
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

    // PHP does not read .env by itself. If the DB_* variables are not set,
    // fall back to the DATABASE_URL line in the project's .env file.
    if ($host === '' || $user === '' || $password === '') {
        $fromEnvFile = database_settings_from_env_file();

        if ($fromEnvFile) {
            $host = $host ?: $fromEnvFile['host'];
            $port = getenv('DB_PORT') ?: $fromEnvFile['port'];
            $name = getenv('DB_NAME') ?: $fromEnvFile['name'];
            $user = $user ?: $fromEnvFile['user'];
            $password = $password ?: $fromEnvFile['password'];
        }
    }

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
 * Create the sections / attempts tables when they do not exist yet.
 * The SQL lives in database/sections_attempts_migration.sql.
 */
function ensure_schema(PDO $pdo): void
{
    static $done = false;

    if ($done) {
        return;
    }

    $ready = $pdo->query(
        "select to_regclass('public.portal_attempt_answers') is not null
            and to_regclass('public.portal_section_students') is not null"
    )->fetchColumn();

    if (!in_array($ready, [true, 't', '1', 1], true)) {
        $sql = file_get_contents(
            __DIR__ . '/database/sections_attempts_migration.sql'
        );

        if ($sql === false) {
            throw new RuntimeException('Migration SQL file is missing.');
        }

        $pdo->exec($sql);
    }

    $done = true;
}

function json_text($value): string
{
    return json_encode(
        $value,
        JSON_UNESCAPED_SLASHES |
        JSON_UNESCAPED_UNICODE |
        JSON_THROW_ON_ERROR
    );
}

function id_or_null($value): ?int
{
    return is_numeric($value) ? (int)$value : null;
}

/**
 * Write sections and their students. Sections missing from the list are
 * removed (their students become unassigned).
 */
function save_sections(PDO $pdo, array $sections): void
{
    $keep = [];
    $upsert = $pdo->prepare(
        'insert into public.portal_sections (id, name, adviser_id)
         values (:id, :name, :adviser)
         on conflict (id) do update
         set name = excluded.name, adviser_id = excluded.adviser_id'
    );

    foreach ($sections as $section) {
        $id = id_or_null($section['id'] ?? null);

        if ($id === null) {
            continue;
        }

        $keep[$id] = true;
        $upsert->execute([
            ':id' => $id,
            ':name' => (string)($section['name'] ?? ''),
            ':adviser' => id_or_null($section['adviserId'] ?? null),
        ]);
    }

    $existing = $pdo->query('select id from public.portal_sections')
        ->fetchAll(PDO::FETCH_COLUMN);
    $drop = $pdo->prepare('delete from public.portal_sections where id = :id');

    foreach ($existing as $id) {
        if (!isset($keep[(int)$id])) {
            $drop->execute([':id' => (int)$id]);
        }
    }

    $pdo->exec('delete from public.portal_section_students');
    $insert = $pdo->prepare(
        'insert into public.portal_section_students (student_id, section_id)
         values (:student, :section)
         on conflict (student_id) do nothing'
    );

    foreach ($sections as $section) {
        $sectionId = id_or_null($section['id'] ?? null);

        if ($sectionId === null) {
            continue;
        }

        foreach (($section['studentIds'] ?? []) as $studentId) {
            $studentId = id_or_null($studentId);

            if ($studentId !== null) {
                $insert->execute([
                    ':student' => $studentId,
                    ':section' => $sectionId,
                ]);
            }
        }
    }
}

function load_sections(PDO $pdo): array
{
    $members = [];

    foreach ($pdo->query(
        'select section_id, student_id
         from public.portal_section_students
         order by assigned_at, student_id'
    ) as $row) {
        $members[(int)$row['section_id']][] = (int)$row['student_id'];
    }

    $sections = [];

    foreach ($pdo->query(
        'select id, name, adviser_id from public.portal_sections order by id'
    ) as $row) {
        $sections[] = [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'adviserId' => $row['adviser_id'] === null
                ? null
                : (int)$row['adviser_id'],
            'studentIds' => $members[(int)$row['id']] ?? [],
        ];
    }

    return $sections;
}

/**
 * Write attempts and their answers. Only new or changed attempts are written.
 * An attempt is deleted only when its exam no longer exists, so a browser
 * holding an old copy of the data cannot erase other students' results.
 */
function save_attempts(PDO $pdo, array $attempts, array $examIds): void
{
    $hashes = [];

    foreach ($pdo->query(
        'select id, content_hash from public.portal_attempts'
    ) as $row) {
        $hashes[(int)$row['id']] = $row['content_hash'];
    }

    $known = ['id', 'studentId', 'examId', 'score', 'total', 'percentage',
              'status', 'flagged', 'date', 'detail'];

    $upsert = $pdo->prepare(
        'insert into public.portal_attempts
            (id, student_id, exam_id, score, total, percentage, status,
             flagged, attempted_at, extra, content_hash, updated_at)
         values
            (:id, :student, :exam, :score, :total, :pct, :status,
             :flagged, :date, CAST(:extra AS jsonb), :hash, now())
         on conflict (id) do update set
            student_id = excluded.student_id, exam_id = excluded.exam_id,
            score = excluded.score, total = excluded.total,
            percentage = excluded.percentage, status = excluded.status,
            flagged = excluded.flagged, attempted_at = excluded.attempted_at,
            extra = excluded.extra, content_hash = excluded.content_hash,
            updated_at = now()'
    );
    $clear = $pdo->prepare(
        'delete from public.portal_attempt_answers where attempt_id = :id'
    );
    $answer = $pdo->prepare(
        'insert into public.portal_attempt_answers
            (attempt_id, position, question_id, question, answer, is_correct)
         values
            (:attempt, :pos, :qid, CAST(:question AS jsonb),
             CAST(:answer AS jsonb), :ok)'
    );

    $incoming = [];

    foreach ($attempts as $attempt) {
        $id = id_or_null($attempt['id'] ?? null);
        $student = id_or_null($attempt['studentId'] ?? null);
        $exam = id_or_null($attempt['examId'] ?? null);

        if ($id === null || $student === null || $exam === null) {
            continue;
        }

        $incoming[$id] = true;
        $hash = md5(json_text($attempt));

        if (($hashes[$id] ?? null) === $hash) {
            continue;
        }

        $extra = array_diff_key($attempt, array_flip($known));

        $upsert->execute([
            ':id' => $id,
            ':student' => $student,
            ':exam' => $exam,
            ':score' => id_or_null($attempt['score'] ?? null),
            ':total' => id_or_null($attempt['total'] ?? null),
            ':pct' => id_or_null($attempt['percentage'] ?? null),
            ':status' => isset($attempt['status'])
                ? (string)$attempt['status'] : null,
            ':flagged' => id_or_null($attempt['flagged'] ?? null),
            ':date' => isset($attempt['date'])
                ? (string)$attempt['date'] : null,
            ':extra' => $extra ? json_text($extra) : '{}',
            ':hash' => $hash,
        ]);

        $clear->execute([':id' => $id]);

        foreach (array_values($attempt['detail'] ?? []) as $pos => $item) {
            $question = $item['q'] ?? [];
            $answer->execute([
                ':attempt' => $id,
                ':pos' => $pos,
                ':qid' => isset($question['id'])
                    ? (string)$question['id'] : null,
                ':question' => json_text($question),
                ':answer' => json_text($item['answer'] ?? null),
                ':ok' => !empty($item['correct']) ? 'true' : 'false',
            ]);
        }
    }

    $examIds = array_flip(array_map('intval', $examIds));
    $drop = $pdo->prepare(
        'delete from public.portal_attempts where id = :id'
    );

    foreach ($pdo->query(
        'select id, exam_id from public.portal_attempts'
    ) as $row) {
        if (
            !isset($incoming[(int)$row['id']]) &&
            !isset($examIds[(int)$row['exam_id']])
        ) {
            $drop->execute([':id' => (int)$row['id']]);
        }
    }
}

function load_attempts(PDO $pdo): array
{
    $details = [];

    foreach ($pdo->query(
        'select attempt_id, question, answer, is_correct
         from public.portal_attempt_answers
         order by attempt_id, position'
    ) as $row) {
        $details[(int)$row['attempt_id']][] = [
            'q' => json_decode($row['question'], true),
            'answer' => json_decode($row['answer'] ?? 'null', true),
            'correct' => in_array($row['is_correct'], [true, 't', '1', 1], true),
        ];
    }

    $attempts = [];

    foreach ($pdo->query(
        'select id, student_id, exam_id, score, total, percentage, status,
                flagged, attempted_at, extra
         from public.portal_attempts order by id'
    ) as $row) {
        $id = (int)$row['id'];
        $extra = json_decode($row['extra'] ?? '{}', true) ?: [];

        $attempt = [
            'id' => $id,
            'studentId' => (int)$row['student_id'],
            'examId' => (int)$row['exam_id'],
            'score' => $row['score'] === null ? null : (int)$row['score'],
            'total' => $row['total'] === null ? null : (int)$row['total'],
            'percentage' => $row['percentage'] === null
                ? null : (int)$row['percentage'],
            'status' => $row['status'],
        ];

        if ($row['flagged'] !== null) {
            $attempt['flagged'] = (int)$row['flagged'];
        }

        $attempt['date'] = $row['attempted_at'];

        if (isset($details[$id])) {
            $attempt['detail'] = $details[$id];
        }

        $attempts[] = $attempt + $extra;
    }

    return $attempts;
}

/**
 * Copy sections / attempts still stored inside the portal_state JSON into
 * their tables, then remove them from the JSON. Does nothing once done.
 * Call inside a transaction.
 */
function migrate_legacy_state(PDO $pdo, array &$state): void
{
    $hasSections = !empty($state['sections']) && is_array($state['sections']);
    $hasAttempts = !empty($state['attempts']) && is_array($state['attempts']);

    if (!$hasSections && !$hasAttempts) {
        unset($state['sections'], $state['attempts']);
        return;
    }

    if ($hasSections && !$pdo->query(
        'select 1 from public.portal_sections limit 1'
    )->fetchColumn()) {
        save_sections($pdo, $state['sections']);
    }

    if ($hasAttempts) {
        $examIds = array_column($state['exams'] ?? [], 'id');
        $existing = [];

        foreach ($pdo->query('select id from public.portal_attempts')
            as $row) {
            $existing[(int)$row['id']] = true;
        }

        $new = array_values(array_filter(
            $state['attempts'],
            static fn ($a) => !isset($existing[(int)($a['id'] ?? 0)])
        ));

        // Rows already in the table are kept untouched.
        save_attempts($pdo, $new, array_merge(
            $examIds,
            array_column($pdo->query(
                'select distinct exam_id from public.portal_attempts'
            )->fetchAll(), 'exam_id')
        ));
    }

    unset($state['sections'], $state['attempts']);
    save_state($pdo, $state);
}

/**
 * The full portal data the browser expects: the JSON snapshot plus the
 * sections and attempts read from their tables.
 */
function load_full_state(PDO $pdo): ?array
{
    $pdo->beginTransaction();

    try {
        ensure_schema($pdo);
        $state = load_state($pdo, true);

        if ($state !== null) {
            migrate_legacy_state($pdo, $state);
            $state['sections'] = load_sections($pdo);
            $state['attempts'] = load_attempts($pdo);
        }

        $pdo->commit();

        return $state;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $error;
    }
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
     * Decline a pending registration. The account and its saved sign-in are
     * removed so the username can be registered again.
     */
    if (
        $method === 'POST' &&
        preg_match(
            '#^/api/admin/users/([^/]+)/decline$#',
            $path,
            $matches
        )
    ) {
        require_admin();

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

            $index = null;

            foreach ($state['users'] as $position => $person) {
                if ((string)($person['id'] ?? '') === $userId) {
                    $index = $position;
                    break;
                }
            }

            if ($index === null) {
                $pdo->rollBack();

                respond(404, [
                    'message' => 'Pending account not found.',
                ]);
            }

            if (($state['users'][$index]['status'] ?? '') !== 'pending') {
                $pdo->rollBack();

                respond(409, [
                    'message' => 'This account is no longer pending.',
                ]);
            }

            array_splice($state['users'], $index, 1);
            save_state($pdo, $state);

            $statement = $pdo->prepare(
                'delete from public.portal_credentials where user_id = :id'
            );
            $statement->execute(['id' => $userId]);

            $pdo->commit();

            respond(200, [
                'ok' => true,
                'message' => 'Registration declined.',
            ]);
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log(
                'Decline failed: ' . $error->getMessage()
            );

            respond(500, [
                'message' => 'Could not decline this registration.',
            ]);
        }
    }

    /*
     * Load saved portal data for a signed-in user.
     */
    if ($method === 'GET' && $path === '/api/app-state') {
        require_login();

        respond(200, [
            'state' => load_full_state(db()),
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

            ensure_schema($pdo);
            migrate_legacy_state($pdo, $savedState);

            // Keep the database user list, including assigned roles/statuses.
            $incomingState['users'] = $savedState['users'];

            // Sections and attempts live in their own tables.
            if (isset($incomingState['sections'])) {
                if (!is_array($incomingState['sections'])) {
                    throw new RuntimeException('Invalid sections.');
                }

                save_sections($pdo, $incomingState['sections']);
            }

            save_attempts(
                $pdo,
                $incomingState['attempts'],
                array_column($incomingState['exams'], 'id')
            );

            unset($incomingState['sections'], $incomingState['attempts']);
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