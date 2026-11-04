-- Run once in Supabase SQL Editor. The app snapshot keeps the existing
-- local object model working while the app uses Supabase as shared storage.

create table if not exists public.portal_state (
  singleton boolean primary key default true check (singleton = true),
  state jsonb not null,
  updated_at timestamptz not null default now()
);

create table if not exists public.portal_credentials (
  user_id text primary key,
  username text not null unique,
  salt text not null,
  password_hash text not null,
  updated_at timestamptz not null default now()
);

alter table public.portal_state disable row level security;
alter table public.portal_credentials disable row level security;
