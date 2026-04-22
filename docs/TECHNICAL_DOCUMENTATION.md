# SME HR System - Technical Documentation

## 1. Document Information
- Project: SME HR System (Springboard MVP Internship)
- Scope: HR system core modules (Payroll marked as TBA)
- Last Updated: 2026-04-22
- Backend: Laravel 13, PHP 8.3, Sanctum, MySQL
- Frontend: React 18, Vite, Tailwind CSS, TanStack Query, React Hook Form, Zod

## 2. System Overview
This system is a web-based HR platform for small and medium enterprises. It manages:
- User authentication and role-based access
- Employee records and lifecycle
- Attendance and clock-in/clock-out operations
- Leave requests and leave balance management
- Schedule templates and employee schedule assignments
- Admin settings including virtual system date/time
- Dashboard summaries for operational monitoring

### Out of Scope (Current)
- Payroll module implementation details are TBA

## 3. Core Business Modules

### 3.1 Authentication and Authorization
- Auth method: Laravel Sanctum token-based API auth
- Roles: `admin`, `hr`, `employee`
- Role guards used in API routing:
  - Admin-only routes under `/admin/*`
  - Admin + HR routes for schedule template/assignment management

### 3.2 Employee Management
- CRUD operations for employee profiles
- Employee status lifecycle (active/inactive)
- Soft delete/restore support for employee-related records
- Hard delete for admin maintenance workflows

### 3.3 Attendance Management
- Clock-in and clock-out endpoints
- Monthly and today views for attendance
- Supports employee self-service and HR/admin management mode
- Includes schedule-aware validations and status derivation:
  - working, completed, late, incomplete, absent, on_leave

### 3.4 Leave Management
- Leave request creation and review flow
- Approval/rejection actions by authorized roles
- Anniversary-cycle leave balance calculation
- Leave type configuration and employee-specific override support

### 3.5 Schedule Management
- Schedule templates with day-level rules
- Employee weekly schedule assignment
- Current schedule resolution by virtual date
- Carry-forward behavior for future weeks when explicit assignment does not exist

### 3.6 Settings and Virtual Clock
- Admin settings include `system_date` and `system_time`
- A virtual system clock endpoint is used by backend logic and frontend displays
- Features that depend on date/time (attendance, leave cycles, dashboard metrics) should use virtual clock data

### 3.7 Dashboard
- Summary metrics for attendance and leave operations
- Uses virtual system date/time for day and month calculations

### 3.8 Payroll (TBA)
- API routes exist under `/payroll/*`
- Detailed design, calculations, exports, and reconciliation are TBA

## 4. High-Level Architecture

### 4.1 Backend Layer
- Framework: Laravel 13
- Key concerns:
  - API controllers in `backend/app/Http/Controllers/Api`
  - Eloquent models in `backend/app/Models`
  - API routes in `backend/routes/api.php`
  - Validation via request validators and controller validation rules

### 4.2 Frontend Layer
- Framework: React + Vite
- Data fetching and cache: TanStack Query
- API wrappers and query keys: `frontend/src/api/queries.js`
- Route-level pages: `frontend/src/pages/*`
- Shared UI components: `frontend/src/components/ui`

### 4.3 Data Layer
- Relational store: MySQL
- Main entities:
  - users
  - employees
  - attendance_logs
  - leave_requests
  - leave_types
  - employee_leave_balances
  - schedule_templates
  - employee_schedules
  - system_settings

## 5. Directory Reference

## 5.1 Backend
- `backend/app/Http/Controllers/Api` - API controllers
- `backend/app/Models` - Eloquent models
- `backend/routes/api.php` - API route definitions
- `backend/database/migrations` - schema changes
- `backend/database/seeders` - initial data setup
- `backend/tests` - backend tests

### 5.2 Frontend
- `frontend/src/api/queries.js` - API client/query keys
- `frontend/src/pages` - route-level features
- `frontend/src/components/ui` - shared components
- `frontend/src/utils` - utility modules

### 5.3 Documentation
- `docs/TECHNICAL_DOCUMENTATION.md` - this file

## 6. Data Model Summary

### 6.1 users
- Stores credentials and role
- Linked to employee record where applicable

### 6.2 employees
- Employee profile and employment metadata
- Linked to user account

### 6.3 attendance_logs
- Daily attendance events and derived status
- Contains schedule snapshot fields for history stability

### 6.4 leave_requests
- Leave lifecycle entity with type, date range, status, approver metadata

### 6.5 leave_types
- Leave type definitions and default allowance/policy flags

### 6.6 employee_leave_balances
- Employee-specific leave override values
- Supports active/inactive override behavior

### 6.7 schedule_templates
- Template-level schedule details and day rules

### 6.8 employee_schedules
- Employee-week assignment records
- Used to resolve applicable schedule for attendance checks

### 6.9 system_settings
- Key-value settings including virtual date/time controls

## 7. API Surface (Summary)
Source of truth: `backend/routes/api.php`

### 7.1 Public
- `POST /login`
- `POST /register`

### 7.2 Authenticated Core
- Auth:
  - `POST /logout`
  - `GET /user`
- Employees:
  - REST `employees`
  - `PATCH /employees/{id}/deactivate`
- Attendance:
  - `POST /attendance/clock-in`
  - `POST /attendance/clock-out`
  - `GET /attendance/today`
  - `GET /attendance/{employeeId}/monthly`
  - `GET /attendance`
  - `GET /attendance/{id}`
- Leave:
  - `POST /leaves`
  - `GET /leaves/balance`
  - `GET /leaves`
  - `GET /leaves/{id}`
  - `PATCH /leaves/{id}/approve`
  - `PATCH /leaves/{id}/reject`
  - `GET /leave-types`
- Dashboard:
  - `GET /dashboard/summary`
- Virtual clock:
  - `GET /system-clock`

### 7.3 Admin
- Settings:
  - `GET /admin/settings`
  - `GET /admin/settings/defaults`
  - `POST /admin/settings/initialize`
  - `GET /admin/settings/{key}`
  - `PUT /admin/settings/{key}`
- Leave type admin:
  - `GET/POST/GET/PUT/DELETE /admin/leave-types/*`
- Employee leave balances:
  - `GET /admin/employee-leave-balances/{employee}`
  - `PUT /admin/employee-leave-balances/{employee}/{leaveType}`
  - `DELETE /admin/employee-leave-balances/{employee}/{leaveType}`
- Departments:
  - REST `admin/departments`
  - restore/hard-delete routes
- Employee admin maintenance:
  - hard-delete and restore routes
- Users:
  - hard-delete route
  - REST `admin/users`

### 7.4 Admin + HR
- Employee schedules:
  - REST `admin/employee-schedules`
  - `GET /admin/employee-schedules/employee/{employeeId}/current`
- Schedule templates:
  - REST `admin/schedule-templates`

### 7.5 Payroll (TBA)
- Existing route placeholders:
  - `POST /payroll/compute`
  - `GET /payroll`
  - `GET /payroll/{id}`
  - `GET /payroll/{id}/export`

## 8. Frontend State and Query Strategy

### 8.1 Query Key Conventions
- Feature-scoped keys in `frontend/src/api/queries.js`
- Include dynamic dependencies like employee ID and virtual date where needed

### 8.2 Cache Invalidation Strategy
- Mutations invalidate feature-level and dependent query keys
- Date/time setting changes must invalidate:
  - system clock keys
  - attendance keys
  - leave keys
  - employee leave balance keys
  - dashboard keys (when date-dependent summaries are shown)

### 8.3 Forms and Validation
- React Hook Form + Zod for frontend form handling
- Laravel validation on backend remains source of truth

## 9. Date/Time Design Notes

### 9.1 Virtual Clock
- Admin-defined date/time should be treated as system truth for business rules
- Backend uses helper-based access (`SystemClock`) for operations
- Frontend consumes `/system-clock` and refetches date-dependent queries accordingly

### 9.2 Common Pitfalls
- Using browser-local `new Date()` for business logic where virtual date is required
- Missing query-key dependencies on virtual date leading to stale UI
- Mixing historical and current schedule states without explicit resolution rules

## 10. Security and Access Control
- Sanctum token auth for API access
- Role middleware guards in API routing
- Endpoint-level ownership checks for employee self-service paths
- Prevent self-destructive operations where applicable (for user delete flows)

## 11. Environment and Local Setup

### 11.1 Backend
1. `cd backend`
2. `composer install`
3. Copy env: `.env.example` to `.env`
4. `php artisan key:generate`
5. `php artisan migrate --seed`
6. `php artisan serve`

### 11.2 Frontend
1. `cd frontend`
2. `npm install`
3. Configure `.env` (API base URL)
4. `npm run dev`

## 12. Build, QA, and Testing

### 12.1 Existing Status
- Backend test scaffolding exists with example tests
- Frontend test suite is not yet established

### 12.2 Recommended Test Layers
- API feature tests for auth, schedules, attendance, leave, settings
- Service-level tests for cycle calculations and schedule resolution
- Frontend integration tests for date-dependent pages and critical forms
- Manual role-based smoke suite (admin/hr/employee)

### 12.3 Suggested Regression Pack (No Payroll)
- Virtual date jump scenarios (today -> future week -> anniversary boundary)
- Schedule day-rule enable/disable behavior
- Leave cycle rollover and override behavior
- Attendance month displays and status integrity

## 13. Known Constraints and Technical Debt
- Payroll module documentation and implementation are pending (TBA)
- Frontend automated tests need to be introduced
- Broader API schema documentation (OpenAPI/Swagger) is not yet generated

## 14. Roadmap (Short-Term)
- Stabilize non-payroll modules with regression tests
- Add frontend test framework (unit + integration)
- Publish OpenAPI spec for current endpoints
- Implement payroll module and replace TBA sections with finalized design

## 15. Change Log
- 2026-04-22: Initial consolidated technical documentation created; Payroll marked TBA.
