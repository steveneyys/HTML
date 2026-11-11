import 'dotenv/config';
import express from 'express';
import pg from 'pg';
import {
  randomBytes,
  scryptSync,
  pbkdf2Sync,
  timingSafeEqual
} from 'node:crypto';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const { Pool } = pg;
const app = express();
const port = Number(process.env.PORT || 3000);
const host = '127.0.0.1'; // Local development only.
const rootDir = path.dirname(fileURLToPath(import.meta.url));

if (!process.env.DATABASE_URL) {
  console.error('DATABASE_URL is missing. Add it to your local .env file.');
  process.exit(1);
}

// SSL options in the connection URL can override the explicit CA settings below.
const connectionUrl = new URL(process.env.DATABASE_URL);
for (const option of ['sslmode', 'sslrootcert', 'sslcert', 'sslkey']) {
  connectionUrl.searchParams.delete(option);
}

let ssl;
if (process.env.DB_SSL === 'true') {
  const certPath = path.resolve(rootDir, process.env.SUPABASE_CA_CERT || 'supabase-prod-ca.crt');
  ssl = {
    ca: readFileSync(certPath, 'utf8'),
    rejectUnauthorized: true
  };
} else {
  ssl = false;
}

const pool = new Pool({
  connectionString: connectionUrl.toString(),
  ssl,
  max: 4,
  connectionTimeoutMillis: 10000
});

app.use(express.json({ limit: '2mb' }));

/* ==========================================================
   Passwords

   New passwords are stored as scrypt hashes (128 hex chars).
   Accounts created while the PHP backend was in use have
   PBKDF2-SHA256 hashes (64 hex chars); both still verify.
   ========================================================== */

function hashPassword(password) {
  const salt = randomBytes(16).toString('hex');
  return { salt, hash: scryptSync(password, salt, 64).toString('hex') };
}

function verifyPassword(password, salt, storedHash) {
  const stored = Buffer.from(storedHash, 'hex');
  const provided = stored.length === 32
    ? pbkdf2Sync(password, salt, 120000, 32, 'sha256') // PHP-created
    : scryptSync(password, salt, 64);                  // Node-created

  return provided.length === stored.length && timingSafeEqual(provided, stored);
}

/* ==========================================================
   Sessions (in memory; everyone is signed out when the server restarts)
   ========================================================== */

const COOKIE = 'portal_sid';
const sessions = new Map();

function readCookie(req, name) {
  for (const part of String(req.headers.cookie || '').split(';')) {
    const [key, ...rest] = part.trim().split('=');
    if (key === name) return decodeURIComponent(rest.join('='));
  }
  return null;
}

function currentUser(req) {
  const sid = readCookie(req, COOKIE);
  return sid ? sessions.get(sid) || null : null;
}

function requireLogin(req, res, next) {
  const user = currentUser(req);
  if (!user) return res.status(401).json({ message: 'Please sign in again.' });
  req.user = user;
  next();
}

function requireAdmin(req, res, next) {
  requireLogin(req, res, () => {
    if (req.user.role !== 'admin') {
      return res.status(403).json({ message: 'Administrator access is required.' });
    }
    next();
  });
}

/* ==========================================================
   Portal snapshot helpers
   ========================================================== */

async function loadState(client, lock = false) {
  const result = await client.query(
    `select state from public.portal_state where singleton = true${lock ? ' for update' : ''}`
  );
  return result.rows[0]?.state ?? null;
}

async function saveState(client, state) {
  await client.query(
    `insert into public.portal_state (singleton, state, updated_at)
     values (true, $1::jsonb, now())
     on conflict (singleton) do update set state = excluded.state, updated_at = now()`,
    [JSON.stringify(state)]
  );
}

/* ==========================================================
   Health
   ========================================================== */

app.get('/api/health', async (_req, res) => {
  try {
    const result = await pool.query('select current_database() as database, now() as connected_at');
    res.json({ ok: true, database: result.rows[0].database, connectedAt: result.rows[0].connected_at });
  } catch (error) {
    console.error('Database connection failed:', error.message);
    res.status(503).json({ ok: false, message: 'Cannot connect to Supabase. Check the server .env and CA certificate.' });
  }
});

/* ==========================================================
   Sign up, sign in, sign out
   ========================================================== */

app.post('/api/auth/register', async (req, res) => {
  const name = String(req.body?.name || '').trim();
  const username = String(req.body?.username || '').trim();
  const password = String(req.body?.password || '');

  if (!name || !username || !password) {
    return res.status(400).json({ message: 'Name, username, and password are required.' });
  }
  if (name.length > 120 || username.length > 80) {
    return res.status(400).json({ message: 'Name or username is too long.' });
  }
  if (password.length < 8) {
    return res.status(400).json({ message: 'Password must be at least 8 characters.' });
  }

  const client = await pool.connect();
  try {
    await client.query('begin');

    const state = await loadState(client, true);
    if (!state || !Array.isArray(state.users)) {
      await client.query('rollback');
      return res.status(500).json({
        message: 'Portal data is not initialized. Ask the administrator to open the portal first.'
      });
    }

    const taken = state.users.some(person =>
      String(person.username || '').toLowerCase() === username.toLowerCase()
    ) || (await client.query(
      'select 1 from public.portal_credentials where lower(username) = lower($1) limit 1',
      [username]
    )).rowCount > 0;

    if (taken) {
      await client.query('rollback');
      return res.status(409).json({ message: 'That username is already taken.' });
    }

    // Next numeric user id, matching the rest of the project.
    const id = state.users.reduce(
      (max, person) => Number.isFinite(Number(person.id)) ? Math.max(max, Number(person.id) + 1) : max,
      1
    );

    // New accounts are pending and have no active role until an admin approves them.
    state.users.push({ id, name, username, role: 'pending', status: 'pending' });
    await saveState(client, state);

    const { salt, hash } = hashPassword(password);
    await client.query(
      `insert into public.portal_credentials (user_id, username, salt, password_hash)
       values ($1, $2, $3, $4)`,
      [String(id), username, salt, hash]
    );

    await client.query('commit');
    res.status(201).json({ ok: true, message: 'Registration submitted. Please wait for admin approval.' });
  } catch (error) {
    await client.query('rollback').catch(() => {});
    console.error('Registration failed:', error.message);
    res.status(500).json({ message: 'Could not submit registration.' });
  } finally {
    client.release();
  }
});

app.post('/api/auth/login', async (req, res) => {
  const username = String(req.body?.username || '').trim();
  const password = String(req.body?.password || '');
  if (!username || !password) return res.status(400).json({ message: 'Username and password are required.' });

  try {
    const credential = await pool.query(
      'select user_id, salt, password_hash from public.portal_credentials where username = $1',
      [username]
    );
    const row = credential.rows[0];

    if (!row || !verifyPassword(password, row.salt, row.password_hash)) {
      return res.status(401).json({ message: 'Invalid username or password.' });
    }

    const state = await loadState(pool);
    const saved = (state?.users || []).find(person => String(person.id) === String(row.user_id));
    if (!saved) return res.status(401).json({ message: 'Account is missing from portal data.' });

    // Accounts without a status field are older, already-approved accounts.
    if ((saved.status ?? 'approved') !== 'approved' || saved.role === 'pending') {
      return res.status(403).json({
        code: 'PENDING_APPROVAL',
        message: 'Your account is waiting for admin approval.'
      });
    }
    if (!['admin', 'faculty', 'student'].includes(saved.role)) {
      return res.status(403).json({ message: 'This account has no valid role. Contact the administrator.' });
    }

    const user = {
      id: saved.id,
      name: saved.name ?? '',
      username: saved.username ?? username,
      role: saved.role
    };

    const sid = randomBytes(32).toString('hex');
    sessions.set(sid, user);
    res.setHeader('Set-Cookie', `${COOKIE}=${sid}; Path=/; HttpOnly; SameSite=Lax`);
    res.json({ user });
  } catch (error) {
    console.error('Login failed:', error.message);
    res.status(503).json({ message: 'Login service is unavailable. Check the database connection.' });
  }
});

app.post('/api/auth/logout', (req, res) => {
  const sid = readCookie(req, COOKIE);
  if (sid) sessions.delete(sid);
  res.setHeader('Set-Cookie', `${COOKIE}=; Path=/; HttpOnly; SameSite=Lax; Max-Age=0`);
  res.json({ ok: true });
});

/* ==========================================================
   Admin: registration approvals
   ========================================================== */

app.get('/api/admin/pending-users', requireAdmin, async (_req, res) => {
  try {
    const state = await loadState(pool);
    const users = (state?.users || [])
      .filter(person => person.status === 'pending')
      .map(person => ({ id: person.id, name: person.name ?? '', username: person.username ?? '' }));
    res.json({ users });
  } catch (error) {
    console.error('Could not list pending users:', error.message);
    res.status(500).json({ message: 'Could not load pending registrations.' });
  }
});

app.post('/api/admin/users/:id/approve', requireAdmin, async (req, res) => {
  const role = String(req.body?.role || '');
  if (!['admin', 'faculty', 'student'].includes(role)) {
    return res.status(400).json({ message: 'Choose admin, faculty, or student.' });
  }

  const client = await pool.connect();
  try {
    await client.query('begin');
    const state = await loadState(client, true);
    if (!state || !Array.isArray(state.users)) throw new Error('Portal user data is unavailable.');

    const person = state.users.find(item => String(item.id) === req.params.id);
    if (!person) {
      await client.query('rollback');
      return res.status(404).json({ message: 'Pending account not found.' });
    }
    if (person.status !== 'pending') {
      await client.query('rollback');
      return res.status(409).json({ message: 'This account is no longer pending.' });
    }

    person.role = role;
    person.status = 'approved';
    await saveState(client, state);
    await client.query('commit');
    res.json({ ok: true, message: 'Account approved.', role });
  } catch (error) {
    await client.query('rollback').catch(() => {});
    console.error('Approval failed:', error.message);
    res.status(500).json({ message: 'Could not approve this account.' });
  } finally {
    client.release();
  }
});

// Decline a pending registration: remove the account and its saved sign-in
// so the username can be registered again.
app.post('/api/admin/users/:id/decline', requireAdmin, async (req, res) => {
  const client = await pool.connect();
  try {
    await client.query('begin');
    const state = await loadState(client, true);
    if (!state || !Array.isArray(state.users)) throw new Error('Portal user data is unavailable.');

    const index = state.users.findIndex(item => String(item.id) === req.params.id);
    if (index === -1) {
      await client.query('rollback');
      return res.status(404).json({ message: 'Pending account not found.' });
    }
    if (state.users[index].status !== 'pending') {
      await client.query('rollback');
      return res.status(409).json({ message: 'This account is no longer pending.' });
    }

    state.users.splice(index, 1);
    await saveState(client, state);
    await client.query('delete from public.portal_credentials where user_id = $1', [req.params.id]);
    await client.query('commit');
    res.json({ ok: true, message: 'Registration declined.' });
  } catch (error) {
    await client.query('rollback').catch(() => {});
    console.error('Decline failed:', error.message);
    res.status(500).json({ message: 'Could not decline this registration.' });
  } finally {
    client.release();
  }
});

/* ==========================================================
   Portal data snapshot
   ========================================================== */

app.get('/api/app-state', requireLogin, async (_req, res) => {
  try {
    res.json({ state: await loadState(pool) });
  } catch (error) {
    console.error('Could not read app state:', error.message);
    res.status(503).json({ message: 'Database state table is missing or unavailable.' });
  }
});

app.put('/api/app-state', requireLogin, async (req, res) => {
  const incoming = req.body?.state;
  if (!incoming || !['subjects', 'exams', 'attempts', 'retakes'].every(key => Array.isArray(incoming[key]))) {
    return res.status(400).json({ message: 'Invalid portal data.' });
  }

  const client = await pool.connect();
  try {
    await client.query('begin');
    const saved = await loadState(client, true);

    if (!saved || !Array.isArray(saved.users)) {
      throw new Error('Saved portal data is unavailable.');
    }

    // The user list is owned by the server (registration and approval),
    // so the browser cannot add users or change roles through this endpoint.
    incoming.users = saved.users;

    await saveState(client, incoming);
    await client.query('commit');
    res.json({ ok: true });
  } catch (error) {
    await client.query('rollback').catch(() => {});
    console.error('Could not save app state:', error.message);
    res.status(500).json({ message: 'Could not save portal data.' });
  } finally {
    client.release();
  }
});

// Read-only endpoint retained for the initial connection check.
app.get('/api/subjects', async (_req, res) => {
  try {
    const result = await pool.query('select id, code, name, description from public.subjects order by code');
    res.json(result.rows);
  } catch (error) {
    console.error('Could not load normalized subjects table:', error.message);
    res.status(500).json({ message: 'Could not load subjects from the database.' });
  }
});

app.use(express.static(rootDir));

app.listen(port, host, () => {
  console.log(`Exam Portal running at http://${host}:${port}`);
});

process.on('SIGINT', async () => {
  await pool.end();
  process.exit(0);
});
