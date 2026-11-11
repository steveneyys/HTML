-- Sections and exam attempts as real tables.
--
-- Optional to run by hand: api.php creates these tables automatically the
-- first time it runs, and copies any sections / attempts that are still stored
-- inside public.portal_state into them (safe to repeat, nothing is deleted
-- until it has been copied). Run this in the Supabase SQL Editor only if you
-- want the tables to exist before the app starts.

create table if not exists public.portal_sections (
  id         bigint primary key,
  name       text not null,
  adviser_id bigint,                      -- faculty user id
  created_at timestamptz not null default now()
);

create unique index if not exists portal_sections_name_key
  on public.portal_sections (lower(name));

-- One row per student: a student belongs to at most one section.
create table if not exists public.portal_section_students (
  student_id  bigint primary key,
  section_id  bigint not null
              references public.portal_sections (id) on delete cascade,
  assigned_at timestamptz not null default now()
);

create index if not exists portal_section_students_section_idx
  on public.portal_section_students (section_id);

create table if not exists public.portal_attempts (
  id           bigint primary key,
  student_id   bigint not null,
  exam_id      bigint not null,
  score        integer,
  total        integer,
  percentage   integer,
  status       text,                      -- 'Passed' / 'Failed'
  flagged      integer,
  attempted_at text,                      -- 'YYYY-MM-DD HH:MM' as the app shows it
  extra        jsonb not null default '{}'::jsonb,
  content_hash text,
  created_at   timestamptz not null default now(),
  updated_at   timestamptz not null default now()
);

create index if not exists portal_attempts_student_idx
  on public.portal_attempts (student_id);
create index if not exists portal_attempts_exam_idx
  on public.portal_attempts (exam_id);

-- One row per question answered in an attempt.
create table if not exists public.portal_attempt_answers (
  attempt_id  bigint not null
              references public.portal_attempts (id) on delete cascade,
  position    integer not null,           -- order the student saw it in
  question_id text,
  question    jsonb not null,             -- snapshot of the question as asked
  answer      jsonb,                      -- what the student answered
  is_correct  boolean not null default false,
  primary key (attempt_id, position)
);

alter table public.portal_sections         disable row level security;
alter table public.portal_section_students disable row level security;
alter table public.portal_attempts         disable row level security;
alter table public.portal_attempt_answers  disable row level security;
