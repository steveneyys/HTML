EXAM PORTAL PHP BACKEND (SUPABASE POSTGRESQL)

This PHP backend replaces server.js for the existing HTML/JavaScript portal.
The frontend keeps the same /api/app-state, /api/auth/login, /api/health, and
/api/subjects endpoints.

REQUIREMENTS
- PHP 8.1 or newer
- PDO_PGSQL enabled (php -m should list pdo_pgsql)
- Existing Supabase PostgreSQL database and its portal_state_migration.sql tables

IMPORTANT: CREDENTIAL ROTATION
The uploaded project ZIP contained a .env file with database credentials and
Supabase secret keys. Rotate the database password and Supabase secret key in
Supabase before continuing. Do not reuse credentials from that ZIP, publish
them, or put a real .env file in GitHub.

SETUP
1. Back up your current project folder.
2. Keep api.php in the same folder as index.html and data.js.
3. Make sure the Supabase portal_state and portal_credentials tables exist. If
   they do not, run database/portal_state_migration.sql once in Supabase SQL
   Editor. Keep your existing data; do not rerun table resets or delete rows.
4. Set DB_HOST, DB_PORT, DB_NAME, DB_USER, and DB_PASSWORD in the environment.
   Use the new Session pooler host and credentials from Supabase. See
   .env.php.example for variable names. PHP does not automatically read .env.
5. From the project folder run:

   php -S 127.0.0.1:8000 router.php

6. Open http://127.0.0.1:8000/ . Visit /api/health to see whether PHP connects.

PHP BUILT-IN SERVER ROUTER
router.php routes /api/* requests to api.php, then serves the existing static
HTML, CSS, JavaScript, and image files. This is for local class/demo use.

PASSWORDS
The Node version wrote scrypt hashes. PHP's standard hash implementation here
cannot verify scrypt hashes, so existing credentials remain in the database but
will need to be reset/set once through the portal's account management flow
after switching to PHP. New/updated credentials are stored as salted PBKDF2
hashes. The frontend still sends a password field only when a user is created
or their password is changed.

LIMITS
- The compatibility endpoint writes the whole portal snapshot, matching the
  current frontend design. It is intended for local coursework and demo use.
- The uploaded project has no server-side role authorization in this API.
  Do not expose it publicly or use it with real student records without adding
  authentication, authorization, CSRF protection, and audit logging.
- Do not start the old Node server when testing the PHP version.
