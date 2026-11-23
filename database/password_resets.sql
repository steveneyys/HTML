-- Password reset requests. A user asks for a reset, an admin approves it,
-- and the user then signs in with a blank password to choose a new one.
create table if not exists public.portal_password_resets (
  user_id      text primary key,
  username     text not null,
  status       text not null default 'pending'
               check (status in ('pending', 'approved')),
  requested_at timestamptz not null default now(),
  approved_at  timestamptz,
  expires_at   timestamptz
);
alter table public.portal_password_resets disable row level security;
