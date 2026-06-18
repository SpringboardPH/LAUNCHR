# LAUNCHR SME HR System — Technical Documentation

## 1. Document Information
- **Project**: LAUNCHR SME HR System
- **Author**: Aaron Luyun (Springboard Philippines)
- **Scope**: Comprehensive Human Resources Management System (HRMS)
- **Last Updated**: 2026-06-15
- **Backend**: Laravel 13, PHP 8.3, MySQL, Laravel Sanctum, spatie/laravel-permission
- **Frontend**: React 18, Vite, Tailwind CSS, TanStack Query v5, React Hook Form + Zod, React Router v6, Recharts, ExcelJS

---

## 2. System Overview

LAUNCHR is a web-based HRMS for small and medium enterprises that digitizes the complete employee lifecycle — from onboarding and attendance tracking to leave management and payroll generation.

The system is structured as a **decoupled SPA** (Single Page Application) communicating with a **Headless REST API**.

### Core Modules
| Module | Description |
|--------|-------------|
| Authentication | Two-step login with OTP email verification, Sanctum token auth |
| Employees | Full CRUD, soft-delete/restore, gov't ID storage |
| Attendance | Real-time clock-in/out, schedule-aware validation, auto-clock-out |
| Leaves | Multi-type request workflow, balance tracking, anniversary refresh |
| Payroll | Semi-monthly generation, PH statutory deductions, email paystubs |
| Schedules | Reusable templates with per-day rules, employee assignments |
| Calendar | Holiday/event management affecting attendance and payroll |
| Admin Settings | Virtual system clock, branding, payroll template, contribution tables |
| Audit Logs | Full action trail for all sensitive operations |
| Users | Admin-only account management |

---

## 3. Architecture

### 3.1 Frontend Architecture (React + Vite)

Feature-based directory structure. All server-state is managed by **TanStack Query**; forms use **React Hook Form + Zod**.

```
frontend/src/
├── api/
│   ├── axios.js          # Axios instance with token interceptor
│   └── queries.js        # ALL API calls + TanStack Query keys (single source of truth)
├── store/
│   └── AuthContext.jsx   # JWT/Sanctum token state, session persistence, logout
├── components/
│   └── layout/
│       ├── AppLayout.jsx       # Management shell (admin + hr)
│       └── EmployeeLayout.jsx  # Self-service shell (employee)
├── pages/
│   ├── admin/        # System config (admin only)
│   ├── attendance/   # HR attendance monitoring
│   ├── auth/         # Login page
│   ├── calendar/     # Shared calendar
│   ├── dashboard/    # HR/admin dashboard
│   ├── employee/     # Self-service portal
│   ├── employees/    # Employee CRUD (HR/admin)
│   ├── leaves/       # Leave approval interfaces
│   └── payroll/      # Payroll generation + detail
├── hooks/            # Custom React hooks
├── utils/
│   └── theme.js      # Theme color application
└── App.jsx           # Root router + RBAC route wrappers
```

#### Routing Strategy (Strict RBAC)
- `/` → redirects to `/hr` or `/employee` based on role
- `/hr/*` → operational dashboard for `hr`, `accounting`, and `admin`
- `/admin/*` → system configuration for `admin` only
- `/employee/*` → self-service portal for `employee`, `hr` (read-only self view)

Route guards defined in `App.jsx`:
- `<HrRoute>` — blocks non-hr/admin users
- `<SystemAdminRoute>` — blocks non-admin users
- `<ProtectedRoute>` — blocks unauthenticated users

### 3.2 Backend Architecture (Laravel)

Standard MVC with Eloquent ORM. All business logic lives in Controllers and Services.

```
backend/
├── app/
│   ├── Console/Commands/          # Artisan commands (AttendanceAutoClockOut, MarkAbsent)
│   ├── Helpers/
│   │   └── SystemClock.php        # Virtual time singleton (replaces Carbon::now())
│   ├── Http/Controllers/Api/      # All REST controllers
│   ├── Mail/
│   │   ├── OtpMail.php            # OTP email
│   │   └── PaystubMail.php        # Paystub email with attachment
│   ├── Models/                    # Eloquent models
│   ├── Services/
│   │   ├── AttendanceService.php  # Status calculation, metrics
│   │   └── PayrollService.php     # PH statutory contribution calculations
│   └── Traits/
│       └── Auditable.php          # Auto-logs model changes to audit_logs
├── database/
│   ├── migrations/                # 40+ migration files
│   └── seeders/                   # Default data seeders
└── routes/
    ├── api.php                    # All API routes with role middleware
    └── console.php                # Scheduled command definitions
```

---

## 4. Three-Tier Role-Based Access Control (RBAC)

| Role | Access |
|------|--------|
| `admin` | Full system access: config, audit logs, user management, hard-deletes, all HR operations |
| `hr` | Operational access: employees, attendance, leaves, payroll, schedules |
| `accounting` | Payroll-focused: payroll generate/edit/send/revert, attendance edit, schedules, calendar — no admin settings or user management |
| `employee` | Self-service only: clock in/out, own attendance/leaves/profile/schedule/calendar |

**Double-layer enforcement:**
1. **Frontend** — Route wrappers in `App.jsx` prevent rendering unauthorized pages
2. **Backend (authoritative)** — `role:admin` and `role:admin,hr` middleware in `routes/api.php` return `403 Forbidden` regardless of frontend state

---

## 5. Authentication Flow

Login supports two modes depending on the `login_otp_required` system setting:

- **OTP enabled (default: false)**: two-step flow — `POST /api/auth/request-otp` verifies credentials and sends a 6-digit OTP via email queue, then `POST /api/auth/verify-otp` validates the code and issues a Sanctum bearer token.
- **OTP disabled**: single-step — credentials are verified and a token is issued immediately.

Token lifetime:
- Standard session: 24-hour token
- "Remember Me": 30-day token

The bearer token is stored in `localStorage` and injected into every Axios request via an interceptor in `frontend/src/api/axios.js`.

Logout (`POST /api/logout`) deletes the current access token from the database.

---

## 6. Core Business Logic

### 6.1 Attendance & Status Calculation

When an employee clocks in:
1. `SystemClock::today()` is called to get the virtual date
2. The employee's schedule assignment is fetched for that day
3. Day-level rules (grace period, clock-in window) are applied
4. Initial status is set to `working` (on-time) or `late`

When an employee clocks out:
1. Elapsed time is compared against expected shift hours
2. Final status is calculated by `AttendanceService::calculateStatus()`

**Status values** (stored in `VARCHAR(50)` to allow future additions without migrations):

| Status | Condition |
|--------|-----------|
| `working` | Clocked in, not yet clocked out |
| `late` | Clocked in after grace window; completed shift hours |
| `completed` | On time, full hours worked (or deviation covered by grace period) |
| `overtime` | Hours worked exceed expected hours (manually confirmed) |
| `half_day` | Worked ≥ 50% but < 100% of expected hours |
| `undertime` | Worked < 50% of expected hours |
| `incomplete` | Clocked in, no clock-out (open log past shift end) |
| `absent` | No attendance log for a scheduled work day |
| `on_leave` | Covered by an approved leave request |
| `holiday` | Date is a configured calendar event (non-absence type) |

**Auto-clock-out** (`attendance:auto-clock-out`): Runs at 23:59 via cron. Checks all open logs (`clock_in_time` set, `clock_out_time` null) and closes them at the scheduled shift-end time. Requires `auto_clock_out_enabled = true` in system settings.

**Mark-absent** (`attendance:mark-absent`): Runs at 00:00 via cron. Creates `absent` records for all active employees who had no log on a scheduled work day.

### 6.2 Schedule Templates & Day Rules

A **Schedule Template** defines a reusable shift:
- `work_days` — array of day-of-week integers (0=Sun, 1=Mon … 6=Sat)
- `work_start_time` / `work_end_time` — official shift hours
- `clock_in_start` / `clock_in_end` — allowed clock-in window
- `clock_out_start` / `clock_out_end` — allowed clock-out window
- `late_threshold_minutes` — minutes after start before marking late
- `required_hours_per_day` — hours needed for `completed` status
- `day_rules` — JSON array for per-day overrides (e.g., early Friday departure)

**Day Rule structure:**
```json
{
  "day": 5,
  "enabled": true,
  "clock_in": "09:00:00",
  "clock_out": "17:00:00",
  "grace_enabled": true,
  "grace_type": "-/+",
  "grace_minutes": 15
}
```
`grace_type` values: `"+"` (late tolerance), `"-"` (early departure tolerance), `"-/+"` (both).

An **Employee Schedule** assigns a template to an employee for a date range. When an employee clocks in, their schedule context (`schedule_template_id`, `schedule_template_name`) is **snapshotted onto the attendance log** so historical records remain stable if the template changes later.

### 6.3 Virtual System Clock

`backend/app/Helpers/SystemClock.php` is a singleton that replaces `Carbon::now()` everywhere in the system.

- Admins set `system_date` and `system_time` in system settings
- The clock advances forward from the saved baseline using real elapsed seconds (so it keeps ticking after being set)
- Setting neither → falls back to real wall-clock time
- Setting only `system_date` → uses that date with real current time

Used in: attendance clock-in/out, absentee generation, leave balance checks, dashboard summaries.

### 6.4 Leave Management

- **Leave Types** (`leave_types`): Configurable pool names (Vacation Leave, Sick Leave, etc.) with annual credits and carryover rules
- **Leave Requests** (`leave_requests`): Submitted by employees; approved/rejected by HR/admin
- **Leave Balances** (`employee_leave_balances`): Decremented on approval, incremented on rejection
- Weekends are excluded from leave day counts unless `leave_include_weekends = true` in settings
- Approved leaves show as `on_leave` in attendance reports for the covered dates

### 6.5 Payroll Calculation

Payroll frequency is configurable via the `payroll_frequency` system setting (`semi_monthly` default, or `monthly`). Cutoff period boundaries are set via `payroll_period1_start_day`, `payroll_period1_end_day`, `payroll_period2_start_day`, `payroll_period2_end_day` (semi-monthly) or `payroll_monthly_start_day`, `payroll_monthly_end_day` (monthly). All calculations use Philippine labor law standards.

**Daily Rate Derivation:**
- Monthly employee: `(base_salary × 12) / divisor`
  - Mon–Fri schedule: divisor = 261
  - Mon–Sat schedule: divisor = 313
- Daily employee: `base_salary` used as-is per day

**Gross Pay:**
- Monthly: `base_salary / 2` (semi-monthly base)
- Daily: `daily_rate × days_worked`

**Additions:**
| Item | Rate |
|------|------|
| Overtime Pay | `daily_rate × 1.25 / 8` per OT hour |
| Rest Day Pay | `daily_rate × 1.30 / 8` per hour |
| Rest Day OT Pay | `daily_rate × 1.69 / 8` per hour |
| Allowance | `(undeclared_salary − base_salary) / 2` (monthly only) |

**Deductions:**
| Item | Formula |
|------|---------|
| Late | `(late_minutes / 60) × hourly_rate` |
| Undertime | `(undertime_minutes / 60) × hourly_rate` |
| Absent | `absent_days × daily_rate` |
| Half Day | `half_days × (daily_rate / 2)` |
| SSS EE | Per contribution table (2024/2025 schedule) stored in `system_settings` |
| PhilHealth EE | 5% total, 2.5% EE share, semi-monthly → divided by 2 |
| Pag-IBIG EE | 2% of salary up to ₱10,000 cap, semi-monthly → divided by 2 |
| Withholding Tax | TRAIN Law 2023–2027 brackets on taxable income; `PayrollService::calculateWithholdingTax($income, $frequency)` uses the correct BIR table for `semi_monthly` or `monthly` |

**Taxable Income** = Gross Pay − Late/Undertime/Absent deductions − SSS − PhilHealth − Pag-IBIG

**Undeclared Salary Toggle**: If an employee has an `undeclared_salary` on file, HR can toggle whether late/undertime/absent deductions use the base salary or the undeclared amount. This is the "toggle-undertime-calc" feature.

**Payroll Lifecycle**: `draft` → `finalized` → `paid`
- Finalized and paid payrolls are locked (no edits)
- Only finalized payrolls can be reverted to draft
- Paystubs (Excel files) are emailed to employee addresses when marked paid

---

## 7. Database Schema

### Core Tables

| Table | Description |
|-------|-------------|
| `users` | Auth credentials, role (`admin`/`hr`/`employee`), soft-deletable |
| `employees` | PII, position, department, salary, gov't IDs, bank account, rate type |
| `departments` | Organizational groupings, soft-deletable |
| `attendance_logs` | Daily clock-in/out ledger with status and schedule snapshot |
| `leave_requests` | Request records with status (`pending`/`approved`/`rejected`) |
| `leave_types` | Leave pool configuration (credits, carryover, is_active) |
| `employee_leave_balances` | Running balance per employee per leave type |
| `schedule_templates` | Reusable shift definitions with day_rules JSON |
| `employee_schedules` | Template assignment to employee for a date range |
| `calendar_event_types` | Event categories with `counts_as_absence` flag |
| `calendar_events` | Holidays and events with optional recurrence |
| `payrolls` | Semi-monthly payroll records per employee |
| `system_settings` | Key-value store for all configurable system parameters |
| `audit_logs` | Full action trail (action, model, old/new values, metadata) |
| `otp_codes` | Temporary OTP codes for 2FA login (6-digit, time-limited) |
| `personal_access_tokens` | Sanctum bearer tokens |

### Employee Fields (Notable)
- `salary` — declared base salary (used for gov't contributions)
- `undeclared_salary` — actual total compensation (used for deduction toggling)
- `rate_type` — `monthly` or `daily`
- `sss_number`, `philhealth_number`, `pagibig_number`, `tin_number` — gov't ID numbers
- `bank_account_number` — for payroll processing

### System Settings Keys

| Key | Default | Purpose |
|-----|---------|---------|
| `system_name` | `LAUNCHR` | Displayed in sidebar |
| `system_logo` | `launchr_black.svg` | Logo file in `public/storage/logos/` |
| `theme_color` | `sienna` | UI color preset (`green`, `blue`, `purple`, `sienna`, `rose`) |
| `work_start_time` | `09:00:00` | Official shift start |
| `work_end_time` | `18:00:00` | Official shift end |
| `late_threshold_minutes` | `0` | Minutes grace after shift start |
| `required_hours_per_day` | `9` | Hours for `completed` status |
| `auto_clock_out_enabled` | `false` | Enables automatic shift-end clock-out |
| `absent_marking_time` | `00:00` | Time the mark-absent cron runs |
| `leave_include_weekends` | `false` | Whether Sat/Sun count in leave days |
| `payroll_template` | `payrolltemplate.xlsx` | Excel template filename |
| `sss_contribution_table` | Full 2024/2025 table | JSON bracket table |
| `login_otp_required` | `false` | Whether email OTP is required to complete login |
| `payroll_frequency` | `semi_monthly` | Payroll cycle: `semi_monthly` or `monthly` |
| `payroll_period1_start_day` | `11` | Semi-monthly: start day of first period |
| `payroll_period1_end_day` | `25` | Semi-monthly: end day of first period |
| `payroll_period2_start_day` | `26` | Semi-monthly: start day of second period |
| `payroll_period2_end_day` | `10` | Semi-monthly: end day of second period (cross-month when end < start) |
| `payroll_monthly_start_day` | `1` | Monthly: start day of the payroll period |
| `payroll_monthly_end_day` | `31` | Monthly: end day of the payroll period (31 = end of month) |
| `system_date` | *(unset)* | Virtual system date override (YYYY-MM-DD) |
| `system_time` | *(unset)* | Virtual system time override (HH:MM:SS) |

---

## 8. API Reference Summary

All routes are prefixed with `/api`. Authenticated routes require `Authorization: Bearer {token}`.

### Public
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/login` | Step 1: verify credentials, send OTP |
| POST | `/auth/request-otp` | Same as `/login` |
| POST | `/auth/verify-otp` | Step 2: verify OTP, get token |
| POST | `/register` | Create user account |
| GET | `/theme-color` | Get current theme |
| GET | `/system-config` | Get system name + theme (used at startup) |

### Employee (any authenticated user)
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/attendance/clock-in` | Clock in |
| POST | `/attendance/clock-out` | Clock out (`confirm_early_clock_out`, `is_overtime` flags) |
| GET | `/attendance/today` | Today's own status |
| GET | `/attendance/{id}/monthly` | Monthly attendance report |
| POST | `/leaves` | Submit leave request |
| GET | `/leaves/balance` | Own leave balances |
| GET | `/leave-types` | Available leave types |
| GET | `/calendar-events` | View events |
| GET | `/my-schedules` | Own schedule assignments |
| GET | `/schedule-templates` | Available templates |
| GET | `/system-clock` | Current virtual time |
| PUT | `/profile` | Update own profile |
| PUT | `/user/password` | Change own password |

### HR + Admin
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/employees` | List all employees |
| GET/PUT | `/employees/{id}` | Get/update employee |
| POST | `/employees` | Create employee |
| PATCH | `/employees/{id}/deactivate` | Deactivate employee |
| GET | `/attendance` | All attendance (filterable) |
| PUT | `/attendance/{id}` | Edit attendance log |
| POST | `/attendance/bulk-mark-absent` | Trigger mark-absent for a date |
| PATCH | `/leaves/{id}/approve` | Approve leave |
| PATCH | `/leaves/{id}/reject` | Reject leave |
| POST | `/payroll/generate` | Generate payroll for cutoff |
| PUT | `/payroll/{id}` | Update payroll |
| POST | `/payroll/send-paystubs` | Email paystubs + mark paid |
| POST | `/payroll/{id}/revert-to-draft` | Unlock finalized payroll |
| POST | `/payroll/{id}/toggle-undertime-calc` | Toggle deduction basis |

### Admin Only
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET/PUT | `/admin/settings/{key}` | Read/write system settings |
| POST | `/admin/settings/logo` | Upload logo |
| POST | `/admin/settings/payroll-template` | Upload Excel template |
| GET/POST/PUT/DELETE | `/admin/users` | User management |
| GET/POST/PUT/DELETE | `/admin/departments` | Department management |
| GET/POST/PUT/DELETE | `/admin/leave-types` | Leave type configuration |
| GET/PUT/DELETE | `/admin/employee-leave-balances/{employee}/{type}` | Manual balance adjustment |
| GET | `/admin/audit-logs` | Full audit trail |
| DELETE | `/admin/employees/{id}/hard-delete` | Permanent employee delete |
| DELETE | `/admin/users/{id}/hard-delete` | Permanent user delete |

---

## 9. Security Protocol

1. **Authentication**: Sanctum bearer tokens. Stored in `localStorage`, sent via `Authorization: Bearer` header.
2. **Double-layer defense**:
   - Frontend route guards prevent rendering unauthorized pages
   - Backend middleware enforces RBAC on every API endpoint regardless of frontend state
3. **Data isolation**: Employees can only read their own records (enforced in each controller)
4. **OTP 2FA**: Login requires email-delivered OTP to complete
5. **Soft-deletes**: Users, employees, and departments are never hard-deleted by default (preserves history and audit trail)
6. **Payroll locking**: Finalized and paid payrolls are immutable (edit attempts return `422`)

---

## 10. Deploying for a New Company

### Prerequisites
- PHP 8.3+
- Composer
- MySQL 8.0+ (or MariaDB 10.6+)
- Node.js 20+ and npm
- Mail service (SMTP, Mailgun, Postmark, SES, etc.)
- A web server (Apache/Nginx) or just PHP CLI for internal deployment

---

### Step 1 — Clone the Repository

```bash
git clone <repository-url> launchr-hr
cd launchr-hr
```

---

### Step 2 — Backend Setup

```bash
cd backend
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

Edit `.env` with the company's configuration:

```env
APP_NAME="Company HRMS"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://hr.yourcompany.com

FRONTEND_URL=https://hr.yourcompany.com

# MySQL database
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=launchr_hr
DB_USERNAME=launchr_user
DB_PASSWORD=your_secure_password

# Timezone (important for attendance)
APP_TIMEZONE=Asia/Manila

# Mail (use SMTP, Mailgun, etc.)
MAIL_MAILER=smtp
MAIL_HOST=smtp.mailprovider.com
MAIL_PORT=587
MAIL_USERNAME=your@email.com
MAIL_PASSWORD=your_email_password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=hr@yourcompany.com
MAIL_FROM_NAME="Company HR"

# Sanctum allowed domains (must match frontend URL)
SANCTUM_STATEFUL_DOMAINS="hr.yourcompany.com"
```

Run migrations and seed default data:

```bash
php artisan migrate --force --seed
```

The seeder creates:
- One admin user (see `DatabaseSeeder.php` — **update the email and password before running**)
- Default departments, leave types, schedule templates, calendar event types, and system settings

**Important**: Edit `backend/database/seeders/DatabaseSeeder.php` before seeding to set the correct admin email:

```php
User::updateOrCreate(
    ['email' => 'admin@yourcompany.com'],   // ← change this
    [
        'name' => 'System Admin',           // ← change this
        'password' => bcrypt('change_me'),  // ← change this
        'role' => 'admin',
    ]
);
```

Optimize for production:

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan storage:link
```

---

### Step 3 — Frontend Setup

```bash
cd frontend
npm install
```

Create `frontend/.env`:

```env
VITE_API_URL=https://hr.yourcompany.com/api
```

Update `frontend/vite.config.js` — set `allowedHosts` to the company's domain:

```js
server: {
  allowedHosts: ['hr.yourcompany.com'],
  proxy: {
    '/api': {
      target: 'http://localhost:8000',
      changeOrigin: true,
    },
  },
},
```

Build for production:

```bash
npm run build
```

The built files go into `frontend/dist/`. Serve this directory from your web server as the document root, and proxy `/api/*` requests to the Laravel backend.

---

### Step 4 — Web Server Configuration

**Nginx example** (serves frontend + proxies API to Laravel):

```nginx
server {
    listen 80;
    server_name hr.yourcompany.com;

    # Frontend (React build output)
    root /var/www/launchr-hr/frontend/dist;
    index index.html;

    # SPA fallback
    location / {
        try_files $uri $uri/ /index.html;
    }

    # Proxy API requests to Laravel
    location /api/ {
        proxy_pass http://127.0.0.1:8000;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
    }
}
```

For the **Laravel backend**, either use `php artisan serve --host=127.0.0.1 --port=8000` (kept alive with tmux/supervisor) or configure a PHP-FPM + Nginx setup pointing to `backend/public/`:

```nginx
server {
    listen 8000;
    root /var/www/launchr-hr/backend/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

---

### Step 5 — Cron Job (Automated Attendance)

The system requires a cron entry to run the Laravel scheduler every minute. Add this to `crontab -e` on the server:

```bash
* * * * * cd /var/www/launchr-hr/backend && php artisan schedule:run >> /dev/null 2>&1
```

**Scheduled tasks** (configured in `backend/routes/console.php`):
- **23:59 daily** — `attendance:auto-clock-out`: closes all open clock-in logs at the shift end time
- **00:00 daily** — `attendance:mark-absent`: creates `absent` records for employees with no log on a scheduled day

> Auto-clock-out only runs if `auto_clock_out_enabled` setting is `true`. Enable it in Admin → System Settings after deployment.

---

### Step 6 — Queue Worker (Email)

OTP emails and paystub emails are dispatched through Laravel Queues (non-blocking). Run a persistent queue worker:

```bash
cd backend
php artisan queue:work --tries=3 --sleep=3
```

Use **Supervisor** to keep the worker alive:

```ini
[program:launchr-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/launchr-hr/backend/artisan queue:work --tries=3 --sleep=3
autostart=true
autorestart=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/launchr-worker.log
```

```bash
supervisorctl reread
supervisorctl update
supervisorctl start launchr-worker:*
```

---

### Step 7 — Development Setup (Alternative to Production)

For local development, use tmux to keep all processes running:

```bash
tmux new -s launchr

# Window 1: Laravel server
cd backend && php artisan serve

# Window 2 (Ctrl+b, c): Queue worker
cd backend && php artisan queue:work

# Window 3 (Ctrl+b, c): Vite dev server
cd frontend && npm run dev
```

Detach with `Ctrl+b, d`. Re-attach with `tmux attach -t launchr`.

Alternatively, from `backend/` run all at once:

```bash
composer run dev
```

This starts the Laravel server, queue worker, Pail log viewer, and Vite dev server concurrently.

---

### Step 8 — First-Time Company Configuration (Admin UI)

After deployment, log in as admin and configure:

1. **Admin → System Settings**
   - Set `system_name` to the company name
   - Upload the company logo (`.svg` or image file)
   - Set `theme_color` to one of: `green`, `blue`, `purple`, `sienna`, `rose`
   - Upload the company's payroll Excel template
   - Adjust `work_start_time`, `work_end_time`, `required_hours_per_day`
   - Enable `auto_clock_out_enabled` if desired

2. **Admin → Departments** — Create the company's department structure

3. **Admin → Configure Leave** — Set up leave types (Vacation Leave, Sick Leave, etc.) with annual credit amounts

4. **Admin → Schedule Templates** — Create shift templates matching the company's work patterns

5. **Admin → Users** — Create HR user accounts and employee accounts

6. **HR → Employees** — Add all employees with their salary, gov't ID numbers, and bank account details

7. **HR → Employee Schedules** — Assign schedule templates to each employee

8. **HR → Calendar** — Import or create company holidays and non-working days

---

## 11. Post-Deployment Operations

### Payroll Workflow (Each Cutoff)
1. HR → Payroll → Generate Payroll (select cutoff dates)
2. Review generated records; edit as needed (only in `draft` status)
3. Toggle undertime calculation basis for employees with undeclared salary if applicable
4. Change status to `Finalized` to lock records
5. Export Excel paystubs (ExcelJS fills the company template with employee data)
6. Send Paystubs → attaches the exported files and emails each employee
7. Records are marked `Paid` automatically

### Attendance Recovery
- If an employee forgot to clock out: HR can edit the attendance log directly (HR → Attendance → Edit)
- If an employee was not clocked in but did work: HR can create a manual attendance log
- For bulk past-date corrections: use the "Mark Absent" function for a specific date

### Virtual Clock (Testing / Demo)
Admin can set `system_date` and `system_time` in System Settings to simulate any date. The system will behave as if it is that date — useful for testing future leave cycles or demonstrating the system to clients. Clear these settings to return to real time.

---

## 12. Known Constraints & Roadmap

1. **PH-specific payroll**: Contribution rates (SSS, PhilHealth, Pag-IBIG, TRAIN Law withholding tax) are hardcoded to Philippine labor law. Adapting for other countries requires changes to `PayrollService.php` and the `sss_contribution_table` system setting.
2. **Frontend testing**: No Jest/Cypress test suite exists. Manual testing is required for UI changes.
3. **Single-tenant**: The system is designed for one company per deployment. Multi-tenancy would require significant schema changes.
4. **Payroll export**: The export is entirely client-side (ExcelJS). The backend `/payroll/{id}/export` endpoint returns a 400 directing users to the frontend button.
