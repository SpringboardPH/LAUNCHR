# LAUNCHR Demo Deploy Guide

Last updated: 2026-10-26

## Before you start

This guide covers one thing: putting the latest `BIRsystems-ollama-cloud` code on the **demo copy** of LAUNCHR on Sir Jason's Raspberry Pi. You type every command yourself.

**What you need**

- Tailscale installed and signed in, so your laptop can see the server.
- SSH access from Sir Jason. Connect with `ssh jsonf@jsonf`.
- Your changes already pushed to the team branch on GitHub.

**Golden rules**

1. Only work inside `~/DEMO_LAUNCHR/LAUNCHR`.
2. Never touch `~/HRIS` or `~/SBHRIS`, or their tmux sessions. Those are other copies. Ask Sir Jason first if you think you need to.
3. Back up the database before any migration.
4. Never paste passwords, `.env` values or demo logins into chat, commits or this doc.
5. Not sure? Stop and ask in the group chat before you press Enter.

## How the server is set up

The server has three copies of LAUNCHR. Each one runs in its own tmux sessions, which keep running after you disconnect. **Only the DEMO row is ours.**

| Copy | Folder | Backend port | tmux sessions | Ours? |
| --- | --- | --- | --- | --- |
| DEMO | `~/DEMO_LAUNCHR/LAUNCHR` | 8002 | `demo_hris` (frontend), `demo_hris_backend`, `demo_hris_queue` | Yes |
| HRIS | `~/HRIS` | 8000 | `st_hris…` | No, don't touch |
| SBHRIS | `~/SBHRIS/LAUNCHR` | 8001 | `sbph_hris…` | No, don't touch |

The `frp` session publishes the apps to the internet. Leave it alone too.

**The demo copy at a glance**

- Database: `demo_launchr` (MariaDB).
- Frontend: a built copy served by `vite preview` in `demo_hris`.
- Emails: written to `backend/storage/logs/laravel.log`, not sent for real.
- Queue: `queue:listen` in `demo_hris_queue`. It picks up new code by itself.

## Deploy steps, every time

Every deploy is the same five steps. Step 4 tells you which extra steps from the next section you need.

1. Connect to the server:

   ```bash
   ssh jsonf@jsonf
   ```

2. Go to the demo folder and check you are on the team branch:

   ```bash
   cd ~/DEMO_LAUNCHR/LAUNCHR
   git status
   ```

   It should say `On branch BIRsystems-ollama-cloud` and `nothing to commit`. If it lists changed files, stop and ask. Someone edited the server copy by hand.

3. Get the new code:

   ```bash
   git pull
   ```

4. See what kind of files changed:

   ```bash
   git diff --name-only HEAD@{1} HEAD | grep -E "composer|package|migrations|\.env|frontend/"
   ```

   Write down what it prints. No output means only backend PHP code changed.

5. Clear Laravel's cache so it reads the new code:

   ```bash
   cd backend
   php artisan optimize:clear
   ```

Then do the extra steps that match step 4, and finish with **Checking it worked**.

## Extra steps, depending on what changed

Match what step 4 printed to a row and run its commands, top to bottom. Start each row from `~/DEMO_LAUNCHR/LAUNCHR`.

| If you saw… | Run this | Why |
| --- | --- | --- |
| `composer.json` or `composer.lock` | `cd backend && composer install` | Downloads new PHP packages. The `vendor/` folder is not in git. |
| a file in `database/migrations/` | 1. Back up first (next section) 2. `cd backend && php artisan migrate --force` | Adds new tables or columns to the database. |
| `package.json` or `package-lock.json` | `cd frontend && npm install` | Downloads new frontend packages. |
| any file under `frontend/` | `cd frontend && npm run build` | Rebuilds the screens. Run it in `frontend/`, not the repo root. |
| `.env.example` | Compare it with `backend/.env` and ask Sir Jason for any new value | The server's `.env` is not in git and never updates by itself. |
| PHP files only, or a migration | Restart the backend (see **Working with tmux**) | `artisan serve` keeps old code in memory. |

After `npm run build`, the frontend session picks up the new build on reload. You don't need to restart it.

## Backing up and restoring the database

Back up before every migration. It takes a few seconds; the last one was 8.2 MB.

**Back up** (put today's date in the name):

```bash
sudo mysqldump demo_launchr > ~/demo_launchr_backup_YYYY-MM-DD.sql
ls -lh ~/demo_launchr_backup_*.sql
```

Use `sudo`, not `-u root -p`. The root database login only works through sudo, so `-p` gives "Access denied". Check the file is not 0 bytes before you migrate.

**Restore** (only if a migration broke the demo, and tell the team first):

```bash
sudo mysql demo_launchr < ~/demo_launchr_backup_YYYY-MM-DD.sql
```

This puts back the whole database as it was at backup time. Anything saved after the backup is lost.

Backup files hold real-looking data and password hashes. Keep them in `~` on the server. Never commit them or send them in chat.

## Working with tmux

The safest way to restart the backend is from your normal shell, without going inside tmux at all.

**Restart the backend (recommended)**

```bash
tmux send-keys -t demo_hris_backend C-c
tmux send-keys -t demo_hris_backend 'php artisan serve --port=8002' Enter
```

The first line presses Ctrl+C inside that session. The second starts the server again.

**Look inside a session**

| To… | Type |
| --- | --- |
| List all sessions | `tmux ls` |
| Go inside one | `tmux attach -t demo_hris_backend` |
| Leave it running and come out | Ctrl+B, then D |
| Check if you are inside tmux | `echo $TMUX` (empty = you're outside) |
| Jump to another session while inside | `tmux switch-client -t demo_hris_backend` |

**Restart the backend by hand**: attach, press Ctrl+C, type `php artisan serve --port=8002`, then Ctrl+B D.

**Do not**

- Press Ctrl+C in `demo_hris` (frontend) or `demo_hris_queue`. They don't need restarts for a normal deploy.
- Attach from inside another session. tmux warns "sessions should be nested with care". Use `switch-client`, or Ctrl+B D out first.
- Type `exit` inside a session. That kills it. Only type `exit` in your normal shell to end SSH.

## Checking it worked

A deploy is done when all five checks pass. Run the commands from `~/DEMO_LAUNCHR/LAUNCHR/backend`.

- [ ] The server has your commit: `git log -1 --oneline` shows the same commit as GitHub.
- [ ] No migrations are waiting: `php artisan migrate:status | grep -i pending` prints nothing.
- [ ] The backend is running: `ps aux | grep 8002` shows a line with `artisan serve --port=8002` from `/home/jsonf/DEMO_LAUNCHR/LAUNCHR`.
- [ ] The screens work: open the demo in the browser, hard-refresh (Ctrl+Shift+R), and try the feature you changed. For BIR, open a form and press Preview PDF.
- [ ] Emails look right: `tail -n 50 storage/logs/laravel.log` shows the email text, since the demo writes emails to the log.

Post a short note in the group chat when you're done: the commit, and which extra steps you ran.

## Troubleshooting

The first four rows already happened on this server; the rest are the likely next ones.

| What you see | What it means | Fix |
| --- | --- | --- |
| `Class "setasign\Fpdi\..." not found` on Preview PDF | New PHP packages were not downloaded | `cd backend && composer install`, then restart the backend |
| `composer install` says `ext-gd` is missing | The PHP image library is not on the server | Ask Sir Jason, then `sudo apt install php8.4-gd` |
| `mysqldump: Access denied for user 'root'` | Root login only works through sudo | Use `sudo mysqldump demo_launchr > ...` with no `-u` or `-p` |
| "sessions should be nested with care" | You are already inside a tmux session | `echo $TMUX` to check; Ctrl+B D out, or use `tmux switch-client -t ...` |
| `Failed to listen on 127.0.0.1:8002` | The old backend is still running | Ctrl+C the old one in `demo_hris_backend` first, then start it again |
| `npm run build` fails with "Missing script" | You ran it in the repo root | `cd frontend`, then `npm run build` |
| A page errors about a missing column or table | A migration did not run | `php artisan migrate:status`, back up, then `php artisan migrate --force` |
| Links in emails open `localhost:4175` | `FRONTEND_URL` in `backend/.env` is still the default | Ask Sir Jason for the real demo address, set it, then `php artisan optimize:clear` |
| `git pull` refuses because of local changes | Someone edited files on the server | Stop. Run `git status`, post the list in the group chat, and wait |

Still stuck? Copy the exact error (no passwords) into the group chat with the command you ran.

## Quick reference

The whole deploy on one screen. Skip the lines marked "only if".

```bash
ssh jsonf@jsonf
cd ~/DEMO_LAUNCHR/LAUNCHR
git status                      # must be clean
git pull
git diff --name-only HEAD@{1} HEAD | grep -E "composer|package|migrations|\.env|frontend/"

cd backend
composer install                # only if composer files changed
sudo mysqldump demo_launchr > ~/demo_launchr_backup_YYYY-MM-DD.sql   # only if migrations changed
php artisan migrate --force     # only if migrations changed
php artisan optimize:clear

cd ../frontend
npm install                     # only if package files changed
npm run build                   # only if frontend files changed

tmux send-keys -t demo_hris_backend C-c
tmux send-keys -t demo_hris_backend 'php artisan serve --port=8002' Enter

cd ../backend
php artisan migrate:status | grep -i pending   # should print nothing
ps aux | grep 8002
exit                            # in your normal shell only
```
