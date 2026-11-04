import 'dotenv/config';
import express from 'express';
import pg from 'pg';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const { Pool } = pg;
const app = express();
const port = Number(process.env.PORT || 3000);
const host = '127.0.0.1'; // Keep this development server local only.
const rootDir = path.dirname(fileURLToPath(import.meta.url));

if (!process.env.DATABASE_URL) {
  console.error('DATABASE_URL is missing. Add it to your local .env file.');
  process.exit(1);
}

const pool = new Pool({
  connectionString: process.env.DATABASE_URL,
  ssl: process.env.DB_SSL === 'true' ? true : undefined,
  max: 4,
  connectionTimeoutMillis: 10000
});

app.use(express.json({ limit: '1mb' }));

app.get('/api/health', async (_req, res) => {
  try {
    const result = await pool.query('select current_database() as database, now() as connected_at');
    res.json({ ok: true, database: result.rows[0].database, connectedAt: result.rows[0].connected_at });
  } catch (error) {
    console.error('Database connection failed:', error.message);
    res.status(503).json({ ok: false, message: 'Cannot connect to Supabase. Check DATABASE_URL and DB_SSL in .env.' });
  }
});

// Read-only starter endpoint to confirm the app can fetch data through the backend.
app.get('/api/subjects', async (_req, res) => {
  try {
    const result = await pool.query(
      'select id, code, name, description from public.subjects order by code'
    );
    res.json(result.rows);
  } catch (error) {
    console.error('Could not load subjects:', error.message);
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
