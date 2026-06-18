# LAUNCHR HRMS — Project Instructions for Claude

## Project Summary

Full-stack HR Management System. Backend: Laravel 13 / PHP 8.3 in `backend/`. Frontend: React 18 + Vite in `frontend/`. See `docs/TECHNICAL_DOCUMENTATION.md` for full detail.

---

## Rules

- Do what has been asked; nothing more, nothing less
- NEVER create files unless absolutely necessary — prefer editing existing files
- NEVER create documentation files unless explicitly requested
- ALWAYS read a file before editing it
- Keep files under 500 lines
- Validate input at system boundaries only
- NEVER commit secrets, credentials, or .env files

---

## Access Control (Touch With Care)

Any change to permissions requires updating **both** layers:
1. **Frontend** — route guards in `frontend/src/App.jsx` (`<HrRoute>`, `<SystemAdminRoute>`, `<ProtectedRoute>`)
2. **Backend** — role middleware in `backend/routes/api.php` (`middleware('role:admin')`, `middleware('role:admin,hr,accounting')`)

Roles: `admin` > `hr` > `accounting` > `employee`. The `accounting` role shares HR-level access to payroll and attendance editing but cannot access admin-only settings/user management.

Missing either one creates a security gap.

---

## Key Files

| File | Purpose |
|------|---------|
| `backend/routes/api.php` | All routes + RBAC middleware — the routing manifest |
| `backend/app/Helpers/SystemClock.php` | Virtual time singleton; use instead of `Carbon::now()` everywhere |
| `backend/app/Http/Controllers/Api/AttendanceController.php` | Most complex controller; handles clock-in/out, schedule validation, grace periods, absentee synthesis |
| `backend/app/Services/AttendanceService.php` | Status calculation logic (reused by controller + cron commands) |
| `backend/app/Services/PayrollService.php` | PH statutory deduction calculations (SSS, PhilHealth, Pag-IBIG, TRAIN tax) |
| `backend/app/Http/Controllers/Api/PayrollController.php` | Payroll generation, lifecycle management, paystub emailing |
| `frontend/src/App.jsx` | Root router + all RBAC route wrappers |
| `frontend/src/api/queries.js` | ALL Axios calls + TanStack Query keys — single source of truth |
| `frontend/src/store/AuthContext.jsx` | Auth token state + session management |
| `backend/database/seeders/DatabaseSeeder.php` | Creates admin user + default data |

---

## Conventions

### Backend (Laravel)
- Use `SystemClock::now()` / `SystemClock::today()` everywhere instead of `Carbon::now()` / `Carbon::today()`
- Schedule context (`schedule_template_id`, `schedule_template_name`) must be snapshotted onto `attendance_logs` at clock-in time so historical records are stable
- Attendance status is `VARCHAR(50)` — add new statuses without migrations
- Soft-delete (`SoftDeletes` trait) is used on `User`, `Employee`, `Department` — never hard-delete unless it's an explicit admin action
- Models with the `Auditable` trait auto-log changes to `audit_logs` — don't add manual audit calls for these
- All API responses follow: `{ success: bool, data: ..., message: string }`

### Frontend (React)
- All API calls live in `frontend/src/api/queries.js` — add new endpoints there, not inline in components
- Use TanStack Query for all server state — no `useState` + `useEffect` for fetched data
- Query keys follow the pattern: `entityKeys.list(params)`, `entityKeys.detail(id)`, etc.
- Use `invalidateQueries` with the correct key after mutations
- Form validation uses Zod schemas passed to React Hook Form via `@hookform/resolvers`

---

## Payroll Rules (Philippines)

- **Daily rate (monthly employee)**: `(salary × 12) / 261` for Mon–Fri; `/ 313` for Mon–Sat
- **Daily rate (daily employee)**: `salary` as-is
- **Overtime**: `daily_rate × 1.25 / 8` per hour
- **Rest Day**: `daily_rate × 1.30 / 8` per regular hour, `× 1.69 / 8` for OT
- **PhilHealth**: 5% total, employee pays 2.5% semi-monthly → `(salary × 0.05 × 0.5) / 2`
- **Pag-IBIG**: 2% up to ₱10,000, semi-monthly → `(min(salary, 10000) × 0.02) / 2`
- **SSS**: Bracket table stored in `system_settings` key `sss_contribution_table`
- **Withholding Tax**: TRAIN Law brackets — `PayrollService::calculateWithholdingTax($taxableIncome, $frequency)` supports `'semi_monthly'` (default) and `'monthly'`; uses the correct BIR table for each (RA 10963, RR 8-2018)
- Taxable income = gross pay − late/undertime/absent deductions − SSS − PhilHealth − Pag-IBIG

---

## Running the Project

```bash
# From backend/
composer run dev   # starts server + queue + logs + vite concurrently

# Or separately:
php artisan serve                          # backend on :8000
php artisan queue:work --tries=1           # email queue
cd frontend && npm run dev                 # frontend on :5173
```

## Running Tests

```bash
cd backend
php artisan test
```

No frontend test suite exists yet.

## Database

```bash
cd backend
php artisan migrate              # run new migrations
php artisan migrate --seed       # fresh seed (resets default data)
php artisan migrate:fresh --seed # drop all tables and reseed
```

---

## Common Patterns When Adding Features

**New API endpoint:**
1. Add route in `backend/routes/api.php` with appropriate middleware
2. Add controller method
3. Add Axios function + query key in `frontend/src/api/queries.js`
4. Build the UI component using `useQuery` or `useMutation`

**New attendance status:**
1. Add the value string to the status handling in `AttendanceService::calculateStatus()`
2. Add color/label mapping in the frontend status display components
3. No migration needed (`status` is `VARCHAR(50)`)

**New system setting:**
1. Add to `SystemSettingsSeeder.php`
2. Use `SystemSettings::where('key', 'your_key')->value('value')` in backend
3. Access via `GET /api/admin/settings/your_key` from frontend (admin only)
