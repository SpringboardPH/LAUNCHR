# How to set up the Springboard presentation

This guide boots a local (or hosted) LAUNCHR instance with the Springboard PH demo roster. Password login has no OTP step. Clock-in does not ask for GPS. Historical attendance rows still have Makati coordinates.

For a blank company deploy, follow [Deploying for a new company](TECHNICAL_DOCUMENTATION.md#10-deploying-for-a-new-company) and stop after `php artisan migrate --seed`. Do not run `DemoSeeder` on a real company database.

## What you will have

- Admin, HR, accounting, and employee logins
- About 28 employees, mixed Today attendance, pending leave, prior finalized payroll
- Juan Cruz with no log for today, so live clock-in works

## Prerequisites

- PHP 8.3
- Composer
- MySQL 8 (database name `launchr`)
- Node.js 20 and npm

SMTP is not required. Set `MAIL_MAILER=log`.

## Local from scratch

1. Clone the repository.

```bash
git clone https://github.com/SpringboardPH/LAUNCHR.git
cd LAUNCHR
```

2. Create `backend/.env` from the example.

```bash
cd backend
cp .env.example .env
php artisan key:generate
composer install
```

3. Set these values in `backend/.env`.

```env
APP_TIMEZONE=Asia/Manila
APP_URL=http://localhost:8000
FRONTEND_URL=http://localhost:5173
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=launchr
DB_USERNAME=root
DB_PASSWORD=
MAIL_MAILER=log
SANCTUM_STATEFUL_DOMAINS="localhost:5173,127.0.0.1:5173,localhost:8000,127.0.0.1:8000"
```

4. Create the MySQL database if it does not exist, then migrate and load the demo roster.

```bash
php artisan migrate --seed
php artisan db:seed --class=DemoSeeder
```

`composer run demo` is the same as the second command.

5. Install the frontend.

```bash
cd ../frontend
cp .env.example .env
```

Keep `VITE_API_BASE_URL=http://localhost:8000/api`. Then run `npm install`.

6. Start the stack. From `backend/` you can run `composer run dev`. That starts `php artisan serve`, the queue worker, Pail, and the React app in `frontend/`.

To start processes yourself:

```bash
cd backend && php artisan serve
cd backend && php artisan queue:work --tries=1
cd frontend && npm run dev
```

The queue worker is optional while OTP is off and mail is `log`.

7. Open http://localhost:5173. Log in with `dev@springboardph.com` and `password`. You land on `/hr` with no OTP field.

## Hosted from scratch

Follow the Nginx, cron, and queue steps in [Deploying for a new company](TECHNICAL_DOCUMENTATION.md#10-deploying-for-a-new-company). After `php artisan migrate --force --seed`, run:

```bash
php artisan db:seed --class=DemoSeeder --force
```

Set `APP_TIMEZONE=Asia/Manila` and `VITE_API_BASE_URL` to `https://<your-host>/api` before you build the frontend.

## Logins

Password for every account is `password`.

- `dev@springboardph.com` admin
- `hr@springboardph.com` HR (Maria Santos)
- `accounting@springboardph.com` accounting (Carlo Reyes)
- `juan@springboardph.com` employee (Juan Cruz, live clock-in)

## Talk track (about 6 to 7 minutes)

Present during the clock-in window 08:45–18:15 Asia/Manila. If you are outside that window, set Admin virtual clock in System Settings.

1. Log in as `dev@springboardph.com`. Open `/hr/employees`. Open Maria Santos or Juan Cruz.
2. Flash `/hr/employee-schedules` and `/hr/calendar`. Log out.
3. Log in as `juan@springboardph.com`. Open `/employee/attendance`. Clock in. No location modal appears.
4. Log in as `hr@springboardph.com`. Open `/hr/requests`. Approve Sofia Mendoza's pending vacation.
5. Open `/hr/payroll`. Open a prior finalized row. Generate the current period. Open a detail and point at SSS, PhilHealth, Pag-IBIG, and withholding tax.

Do not turn on Require OTP at login or Capture location during the run. Leave, loans, holidays, and mixed Today attendance are already in the data if someone clicks around.

## After a previous demo

If this database already ran an older demo seed with geo capture on, run the demo seed again. `updateOrCreate` writes the off settings.

```bash
cd backend
php artisan db:seed --class=DemoSeeder
```

To wipe and start over:

```bash
php artisan migrate:fresh --seed
php artisan db:seed --class=DemoSeeder
```

Do not use `composer setup` as the demo path. It seeds a blank company and runs `npm` inside `backend/`.
