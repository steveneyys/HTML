SUPABASE STORAGE MIGRATION FOR THE EXISTING EXAM PORTAL

This patch migrates the app's shared users, subjects, exams, question banks,
submissions/attempts, and retake grants from browser localStorage to Supabase.
It uses two tables: portal_state (JSONB snapshot of the existing app model) and
portal_credentials (salted password hashes). Existing normalized tables created
earlier remain in place but are not used by this first compatibility migration.

IMPORTANT LIMITS
- This server binds to 127.0.0.1 and is for local development only.
- Existing pages use synchronous data access, so the compatibility bridge uses
  synchronous local HTTP requests. Do not deploy this pattern for production.
- The API has no role-based authorization. Do not expose it to the internet or
  use it with real student records.
- The first browser that opens the app copies its saved localStorage data to
  Supabase. Existing rows in the database are not overwritten by that import.

SETUP
1. Keep a backup of your current project folder.
2. Copy data.js, index.html, and server.js from this patch into your current
   HTML folder, replacing those three files. Keep your own .env and CA certificate.
3. In Supabase SQL Editor, run database/portal_state_migration.sql once.
4. Keep DB_SSL=true. Put the Supabase CA certificate file beside server.js and
   name it supabase-prod-ca.crt, or set SUPABASE_CA_CERT to its filename in .env.
5. In .env, set DATABASE_URL to the new Session pooler URI from Supabase Connect.
   Never share or commit .env.
6. In the VS Code terminal run npm start, then open http://127.0.0.1:3000.
   Opening the sign-in page initializes the database from this browser's cached
   portal data, or from the starter data if no cache exists.
7. Sign in with the existing account. The password is stored as a salted hash.

The app's state is stored as a single JSONB snapshot for compatibility with the
current pages. The old nine normalized tables are intentionally left untouched.
