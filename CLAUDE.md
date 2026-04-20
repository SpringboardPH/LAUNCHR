# SME HR System — Springboard MVP Internship

## Project Overview
A web-based HR management system built for Philippine SMEs (10–50 employees) as part of the Springboard MVP Internship Program. Built solo within 320 hours / 8 weeks.

**Problem:** PH SMEs manage employee records, attendance, and leave manually via spreadsheets, causing payroll errors and compliance gaps.
**Solution:** A lightweight, deployable HR web app covering the full employee lifecycle from onboarding to payroll.

## Tech Stack
| Layer | Technology |
|---|---|
| Backend | Laravel 11 (PHP 8.2+) |
| Auth | Laravel Sanctum (token-based SPA auth) |
| Database | MySQL 8 |
| Frontend | React 18 + Vite |
| HTTP Client | Axios |
| Styling | TailwindCSS v3 |
| State/Data | React Query (TanStack Query v5) |
| Forms | React Hook Form + Zod |

## Project Structure
```
hr-system/
├── CLAUDE.md              ← you are here
├── backend/               ← Laravel API
│   ├── app/
│   │   ├── Http/
│   │   │   ├── Controllers/Api/
│   │   │   │   ├── AuthController.php
│   │   │   │   ├── EmployeeController.php
│   │   │   │   ├── AttendanceController.php
│   │   │   │   ├── LeaveController.php
│   │   │   │   └── PayrollController.php
│   │   │   ├── Requests/
│   │   │   └── Resources/
│   │   └── Models/
│   ├── database/
│   │   ├── migrations/
│   │   └── seeders/
│   └── routes/api.php
└── frontend/              ← React SPA
    ├── src/
    │   ├── api/           ← Axios instances + query functions
    │   ├── components/    ← Shared UI components
    │   ├── pages/         ← Route-level page components
    │   │   ├── auth/
    │   │   ├── employees/
    │   │   ├── attendance/
    │   │   ├── leaves/
    │   │   ├── payroll/
    │   │   └── dashboard/
    │   ├── hooks/         ← Custom React hooks
    │   ├── store/         ← Auth context
    │   └── utils/
    └── public/
```

## 5 Core Features (SCOPE-LOCKED — do not add more)
| # | Feature | Key Screens |
|---|---|---|
| F1 | Employee Records | List, Create, View, Edit, Deactivate |
| F2 | Attendance Tracking | Clock In/Out, Daily Log, Monthly Summary |
| F3 | Leave Management | Request Leave, Approve/Reject, Balance View |
| F4 | Payroll Summary | Compute Period, Summary Table, Export CSV |
| F5 | Admin Dashboard | Headcount, Attendance Rate, Pending Leaves, Payroll Status |

## Database Models
- `users` — auth (admin/hr/employee roles)
- `employees` — profile info, position, salary, status
- `attendance_logs` — clock in/out per employee per day
- `leave_requests` — leave type, dates, status, approver
- `payroll_runs` — period, per-employee computed summary

## API Base URL
- Local: `http://localhost:8000/api`
- Production: `https://hr.springboard.ph/api` (TBD)

## Auth Flow
- Laravel Sanctum SPA tokens
- React stores token in `localStorage` (key: `hr_token`)
- All protected routes require `Authorization: Bearer {token}` header
- Roles: `admin`, `hr`, `employee`

## Key Conventions

### Backend (Laravel)
- All API responses follow this shape:
```json
{
  "success": true,
  "data": { ... },
  "message": "Optional message"
}
```
- Error responses:
```json
{
  "success": false,
  "message": "Error description",
  "errors": { "field": ["validation message"] }
}
```
- Use Form Requests for all validation
- Use API Resources for all response transformation
- Soft deletes on `employees` (never hard delete)
- All timestamps in `Asia/Manila` timezone

### Frontend (React)
- Use React Query for all server state (no Redux)
- Use React Hook Form + Zod for all forms
- Components follow: `PascalCase.jsx`
- API query keys follow: `['employees', id]`, `['attendance', employeeId, month]`
- Use TailwindCSS utility classes only — no custom CSS files
- All dates displayed in `MMM DD, YYYY` format (Philippines locale)

## Environment Variables

### Backend (.env)
```
APP_NAME="HR System"
APP_URL=http://localhost:8000
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=hr_system
DB_USERNAME=root
DB_PASSWORD=
SANCTUM_STATEFUL_DOMAINS=localhost:5173
FRONTEND_URL=http://localhost:5173
```

### Frontend (.env)
```
VITE_API_BASE_URL=http://localhost:8000/api
```

## Development Commands

### Backend
```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve          # runs on :8000
```

### Frontend
```bash
cd frontend
npm install
cp .env.example .env
npm run dev                # runs on :5173
```

## Springboard Program Rules (Non-Negotiables)
- Max 5 core features — do not scope creep
- No custom ML model training
- Must be deployed by Week 7
- Interns work individually
- Focus: usable product, not prototype

## Deployment Targets (Week 7)
- Backend: Railway.app or Render.com (free tier)
- Frontend: Vercel (free tier)
- DB: PlanetScale or Railway MySQL

## Feasibility Gate Checklist (Week 1)
- [x] ≤ 5 core features
- [x] No custom ML model training
- [x] Deployable as web application
- [x] SME validation source: target PH SME HR managers

## Weekly Deliverables
| Week | Output |
|---|---|
| 1 | Problem validation + approved concept |
| 2 | PRD + system design + DB schema |
| 3 | Core backend + Employee module (F1) |
| 4 | Attendance (F2) + Leave (F3) modules |
| 5 | Payroll (F4) + Dashboard (F5) modules |
| 6 | Testing + SME user testing session |
| 7 | Live deployed product |
| 8 | Business model + pitch deck |
