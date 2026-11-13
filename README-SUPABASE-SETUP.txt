EXAM PORTAL + SUPABASE LOCAL BACKEND

Important:
- This is a local development backend. It listens only on 127.0.0.1.
- The browser must not receive DATABASE_URL or any Supabase secret/service-role key.
- Existing pages still use localStorage for their exam data. This starter adds a real
  database health check and a read-only subjects endpoint. App-wide migration is a
  separate next step.

1. In Supabase, reset the database password because the previous screenshot showed
   the old connection string. Use the new password when copying the Session pooler
   connection string from Connect. Keep the host, username, port, and database name
   exactly as Supabase provides them. The new password may need URL encoding if it
   includes characters such as @, :, /, ?, #, or spaces.

2. If you chose not to use RLS, open database/disable_rls.sql in Supabase SQL Editor
   and run it once. The policies remain in the database but are inactive while RLS
   is disabled.

3. In this folder, copy .env.example and name the copy .env. Put the new Supabase
   Session pooler connection string after DATABASE_URL=. Do not share or commit .env.

4. Open Terminal in this folder and run:
   npm install express pg dotenv
   npm start

5. Open http://127.0.0.1:3000/api/health. A successful connection returns JSON with
   "ok": true. Then open http://127.0.0.1:3000/api/subjects to read the subjects.
   The subjects list may be empty until you add rows in Supabase Table Editor.

This starter intentionally does not expose create/update/delete endpoints. Do not
host it publicly or use it for real student data until authentication and access
checks are added to the backend.


--- Sections and exam attempts are real tables (PHP backend) ---
api.php stores sections and attempts in their own Supabase tables:
  portal_sections, portal_section_students (one section per student),
  portal_attempts, portal_attempt_answers (one row per answered question).
The tables are created automatically on first use (or run
database/sections_attempts_migration.sql in the SQL Editor), and any sections
or attempts still stored inside portal_state are copied over once.
Faculty: Students tab and Attempts / Grade Reports tabs filter by section;
"View Answers" opens attempt.html.
Note: server.js (Node) still keeps everything in the portal_state snapshot, so
use the PHP backend to get the new tables.

--- Speed ---
Each trip from your computer to Supabase costs a fixed delay (large when the
database is in another continent), so api.php keeps the number of trips low:
loading the app data is 1 database query, saving is 4, and the database
connection is reused between requests. Pages also reuse data they loaded or
saved in the last 10 seconds instead of asking the server again.
