import 'dotenv/config';
import express from 'express';
import pg from 'pg';
import { randomBytes, scryptSync, timingSafeEqual } from 'node:crypto';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const { Pool } = pg;
const app = express();
const port = Number(process.env.PORT || 3000);
const host = '127.0.0.1'; // Local development only; API has no user authorization yet.
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

app.get('/api/health', async (_req, res) => {
  try {
    const result = await pool.query('select current_database() as database, now() as connected_at');
    res.json({ ok: true, database: result.rows[0].database, connectedAt: result.rows[0].connected_at });
  } catch (error) {
    console.error('Database connection failed:', error.message);
    res.status(503).json({ ok: false, message: 'Cannot connect to Supabase. Check the server .env and CA certificate.' });
  }
});

app.get('/api/app-state', async (_req, res) => {
  try {
    const result = await pool.query(
      'select state from public.portal_state where singleton = true'
    );
    res.json({ state: result.rows[0]?.state ?? null });
  } catch (error) {
    console.error('Could not read app state:', error.message);
    res.status(503).json({ message: 'Database state table is missing or unavailable.' });
  }
});

app.put('/api/app-state', async (req, res) => {
  const state = req.body?.state;
  if (!state || !Array.isArray(state.users) || !Array.isArray(state.subjects) ||
      !Array.isArray(state.exams) || !Array.isArray(state.attempts) || !Array.isArray(state.retakes)) {
    return res.status(400).json({ message: 'Invalid portal data.' });
  }

  const client = await pool.connect();
  try {
    await client.query('begin');

    // Passwords never go into the JSON state. They are salted and hashed separately.
    for (const user of state.users) {
      const userId = String(user.id);
      if (typeof user.password === 'string' && user.password.length > 0) {
        const salt = randomBytes(16).toString('hex');
        const passwordHash = scryptSync(user.password, salt, 64).toString('hex');
        await client.query(
          `insert into public.portal_credentials (user_id, username, salt, password_hash)
           values ($1, $2, $3, $4)
           on conflict (user_id) do update set
             username = excluded.username,
             salt = excluded.salt,
             password_hash = excluded.password_hash,
             updated_at = now()`,
          [userId, user.username, salt, passwordHash]
        );
      } else {
        const updated = await client.query(
          'update public.portal_credentials set username = $1, updated_at = now() where user_id = $2',
          [user.username, userId]
        );
        if (updated.rowCount === 0) {
          throw new Error(`No saved password exists for user ${userId}. Set a password for that account in the Admin page.`);
        }
      }
    }

    const userIds = state.users.map(user => String(user.id));
    await client.query(
      'delete from public.portal_credentials where not (user_id = any($1::text[]))',
      [userIds]
    );

    const safeState = structuredClone(state);
    safeState.users.forEach(user => delete user.password);
    await client.query(
      `insert into public.portal_state (singleton, state, updated_at)
       values (true, $1::jsonb, now())
       on conflict (singleton) do update set state = excluded.state, updated_at = now()`,
      [JSON.stringify(safeState)]
    );

    await client.query('commit');
    res.json({ ok: true });
  } catch (error) {
    await client.query('rollback');
    console.error('Could not save app state:', error.message);
    res.status(500).json({ message: 'Could not save portal data. Check the database migration and account passwords.' });
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
    if (!row) return res.status(401).json({ message: 'Invalid username or password.' });

    const provided = scryptSync(password, row.salt, 64);
    const stored = Buffer.from(row.password_hash, 'hex');
    if (provided.length !== stored.length || !timingSafeEqual(provided, stored)) {
      return res.status(401).json({ message: 'Invalid username or password.' });
    }

    const result = await pool.query(
      `select user_item.person as user
       from public.portal_state p,
       lateral jsonb_array_elements(p.state->'users') as user_item(person)
       where p.singleton = true and user_item.person->>'id' = $1`,
      [row.user_id]
    );
    if (!result.rows[0]) return res.status(401).json({ message: 'Account is missing from portal data.' });
    res.json({ user: result.rows[0].user });
  } catch (error) {
    console.error('Login failed:', error.message);
    res.status(503).json({ message: 'Login service is unavailable. Check the database connection.' });
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
