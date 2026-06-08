# LAUNCHR SME HR System - Technical Documentation

## 1. Document Information
- **Project**: LAUNCHR SME HR System (Springboard Philippines) by Aaron Luyun
- **Scope**: Comprehensive Human Resources Management System (HRMS)
- **Last Updated**: 2026-04-29
- **Backend**: Laravel 13, PHP 8.3, Sanctum, MySQL
- **Frontend**: React 18, Vite, Tailwind CSS, TanStack Query, React Hook Form, React Router v6

---

## 2. System Overview
This system is a comprehensive, web-based HR platform designed for small and medium enterprises. It digitizes the complete employee lifecycle, from onboarding and attendance tracking to leave management and payroll generation.

### Core Capabilities:
- **Authentication & Authorization**: Strict Role-Based Access Control (RBAC) with `admin`, `hr`, and `employee` designations.
- **Attendance Management**: Real-time clock-in/out, automated status derivation (late, absent, half-day, working), and geolocation/virtual clock awareness.
- **Leave Management**: Configurable leave policies, dynamic approval workflows, and automated balance tracking.
- **Schedule Management**: Day-level precision scheduling, template assignments, and shift grace-period configuration.
- **Virtual System Clock**: Advanced administrative control allowing the testing and simulation of future/past dates across the entire system.

---

## 3. Architecture & Directory Structure

The project is structured as a decoupled SPA (Single Page Application) communicating with a Headless REST API.

### 3.1 Frontend Architecture (React + Vite)
The frontend relies heavily on a **Feature-Based Directory Structure** to keep domain logic isolated and scalable. State management and server-data synchronization are handled entirely by TanStack Query.

#### Frontend Routing Strategy (Strict RBAC):
- `/employee/*`: Self-service portal accessible by standard employees.
- `/hr/*`: Operational management dashboard accessible by both `hr` and `admin`. Handles daily operations (Attendance, Leaves, Payroll, Employees).
- `/admin/*`: System configuration portal restricted strictly to `admin`. Handles overarching rules (Configure Leaves, Departments, User Management).

#### Key Frontend Directories & Files:
- `frontend/src/App.jsx`: The root router. Defines the strict RBAC route wrappers (`<HrRoute>`, `<SystemAdminRoute>`, `<ProtectedRoute>`) ensuring absolute UI security.
- `frontend/src/api/queries.js`: The central API registry. Contains all Axios requests and TanStack Query hook definitions. Acts as the single source of truth for frontend-backend communication.
- `frontend/src/store/AuthContext.jsx`: Manages the JWT/Sanctum bearer token state, user session persistence, and global logout functionality.
- `frontend/src/components/layout/`: Contains the structural UI shells (`AppLayout.jsx` for Management, `EmployeeLayout.jsx` for Self-Service).
- `frontend/src/pages/`: Contains all route-level features grouped by domain:
  - `/admin/`: Configuration pages (e.g., `ConfigureLeavePage.jsx`, `SystemSettingsPage.jsx`).
  - `/attendance/`: HR attendance monitoring interfaces.
  - `/leaves/`: Leave approval and tracking interfaces.
  - `/employees/`: Employee CRUD and profiling interfaces.

### 3.2 Backend Architecture (Laravel)
The backend follows a standard MVC (Model-View-Controller) architecture, heavily relying on Eloquent ORM and Form Requests for validation.

#### Key Backend Directories & Files:
- `backend/routes/api.php`: The routing manifest. Extremely critical file that enforces `middleware('role:admin')` and `middleware('role:admin,hr')` to guarantee API security regardless of frontend state.
- `backend/app/Http/Controllers/Api/`: Contains all business logic handlers.
  - `AttendanceController.php`: Manages clock events, grace periods, and schedule cross-referencing.
  - `PayrollController.php`: Handles complex mathematical aggregations of attendance and basic salary.
- `backend/app/Models/`: Eloquent entity definitions mapping to the database.
- `backend/app/Services/`: Contains abstracted business logic (e.g., `AttendanceService.php`) that can be shared between API Controllers and background Cron jobs.
- `backend/app/Helpers/SystemClock.php`: A crucial singleton utility that overrides standard `Carbon::now()` to respect the Admin-configured virtual system time.

---

## 4. Core Business Modules & Logic

### 4.1 Attendance & Status Calculation
Attendance heavily relies on overlapping the `employee_schedules` with real-time `attendance_logs`.
- **Logic Flow**: When an employee clocks in, `AttendanceController` queries `SystemClock::today()`, fetches the assigned schedule template for that day, applies grace period rules, and determines if the clock-in is `working` or `late`.
- **Status Types**: `absent`, `late`, `completed`, `incomplete`, `working`, `on_leave`. 
- *(Note: The database column `status` is a `VARCHAR(50)`, allowing for seamless introduction of new statuses like `half_day` without requiring schema migrations).*

### 4.2 Schedule Management
- **Templates (`schedule_templates`)**: Defines reusable shifts (e.g., "Standard 9-to-6", "Night Shift").
- **Day Rules**: Nested JSON data inside templates allowing day-by-day overrides (e.g., half-days on Fridays).
- **Assignments (`employee_schedules`)**: Binds an employee to a template for a specific date range.

### 4.3 Virtual System Settings
The system contains a unique testing/simulation feature. 
- Admins can change the `system_date` in the database.
- Every single backend query replaces standard PHP time with `SystemClock::now()`.
- **Impact**: Altering the virtual clock instantly fast-forwards leave anniversary cycles, triggers auto-clock-outs, and shifts the dashboard summary analytics.

---

## 5. Database Schema (Entities)

- `users`: Authentication credentials, encrypted passwords, and RBAC `role` definitions.
- `employees`: PII, job titles, basic salary, and department associations.
- `departments`: Organizational groupings (soft-deletable).
- `attendance_logs`: Immutable daily ledger of clock-in/out timestamps and system-derived statuses.
- `leave_requests`: Transactional requests bridging an employee to an approver.
- `leave_types`: System configuration for leave pools (Vacation, Sick, etc.) and annual refresh rules.
- `employee_leave_balances`: Running ledger of remaining days off per employee.
- `schedule_templates` & `employee_schedules`: The scheduling engine tables.

---

## 6. Security Protocol

1. **Authentication**: Handled via Laravel Sanctum. The frontend stores tokens in `localStorage` and injects them via Axios interceptors.
2. **Double-Layer Defense**:
   - **Frontend**: React Router prevents unauthorized users from rendering administrative UI components.
   - **Backend (Ultimate Authority)**: Even if a frontend route is bypassed, `routes/api.php` enforces strict Laravel Middleware. Any unauthorized HTTP request immediately receives a `403 Forbidden` response.
3. **Data Isolation**: Standard `employee` roles can only fetch data where `employee_id` matches their own verified token signature.

---

## 7. Environment & Deployment Setup

### Backend Setup
```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
# Configure database credentials in .env
php artisan migrate --seed
php artisan serve
```

### Frontend Setup
```bash
cd frontend
npm install
# Ensure VITE_API_URL in .env points to the Laravel instance
npm run dev
```
*To build for production:* `npm run build`

---

## 8. Known Constraints & Roadmap

1. **Testing Suite**: A comprehensive Jest/Cypress integration testing suite for the React frontend needs to be established to prevent regression during large UI refactors.

---

## 9. Deployment Infrastructure

For a production environment, the following infrastructure must be configured to ensure automated tasks and background jobs run reliably.

### 9.1 Cron Job (Automation)
The system relies on Laravel's scheduler for daily automated tasks, such as auto-clocking out employees and marking absentees. 

Add the following entry to your server's crontab (`crontab -e`):

```bash
* * * * * cd /path-to-your-project/backend && php artisan schedule:run >> /dev/null 2>&1
```

**Configured Tasks (via `backend/routes/console.php`):**
- **23:59**: Runs `attendance:auto-clock-out` to close any remaining open attendance logs.
- **00:00**: Runs `attendance:mark-absent` to identify and mark employees who did not log any attendance for the previous day.

### 9.2 Queue Worker (Background Jobs)
For non-blocking operations (like sending email paystubs), the system uses Laravel Queues. Run a persistent queue worker process to handle these tasks:

```bash
cd backend
php artisan queue:work --tries=3
```

### 9.3 Persistence with Tmux
To ensure that the Laravel development/production server (`php artisan serve`) and the queue worker remain running after you disconnect from your SSH session, use `tmux`:

1. **Start a new session:** `tmux new -s launchr`
2. **Inside the session:**
   - Run your server: `php artisan serve`
   - Create a new window (`Ctrl+b`, `c`) to run your queue worker: `php artisan queue:work`
3. **Detach from the session:** `Ctrl+b`, `d`
4. **Re-attach later:** `tmux attach -t launchr`

