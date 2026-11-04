-- Only run if you have chosen to manage all database access through your
-- private, local Node.js backend. Do not expose your database connection string
-- or a service role / secret key in browser code.

ALTER TABLE public.profiles DISABLE ROW LEVEL SECURITY;
ALTER TABLE public.subjects DISABLE ROW LEVEL SECURITY;
ALTER TABLE public.subject_faculty DISABLE ROW LEVEL SECURITY;
ALTER TABLE public.enrollments DISABLE ROW LEVEL SECURITY;
ALTER TABLE public.exams DISABLE ROW LEVEL SECURITY;
ALTER TABLE public.questions DISABLE ROW LEVEL SECURITY;
ALTER TABLE public.submissions DISABLE ROW LEVEL SECURITY;
ALTER TABLE public.submission_answers DISABLE ROW LEVEL SECURITY;
ALTER TABLE public.retake_grants DISABLE ROW LEVEL SECURITY;
