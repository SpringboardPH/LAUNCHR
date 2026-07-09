import { useState, useEffect } from 'react'
import {
  X, ChevronRight, ChevronLeft,
  LayoutDashboard, Clock, ClipboardList, CalendarRange, User,
  FileText, CheckCircle2, Users, Banknote, Settings, UserCog,
  Building2, Sliders, History, Wallet,
} from 'lucide-react'

// Inline text formatter: **bold**, *italic*, __underline__
function fmt(str) {
  const parts = str.split(/(\*\*[^*]+\*\*|\*[^*]+\*|__[^_]+__)/g)
  if (parts.length === 1) return str
  return parts.map((p, i) => {
    if (p.startsWith('**') && p.endsWith('**'))
      return <strong key={i} className="font-semibold text-gray-900">{p.slice(2, -2)}</strong>
    if (p.startsWith('__') && p.endsWith('__'))
      return <span key={i} className="underline underline-offset-2 decoration-brand-500 font-medium">{p.slice(2, -2)}</span>
    if (p.startsWith('*') && p.endsWith('*'))
      return <em key={i} className="italic text-gray-700">{p.slice(1, -1)}</em>
    return p
  })
}

// ─── Employee steps ────────────────────────────────────────────
export const EMPLOYEE_STEPS = [
  {
    icon: LayoutDashboard,
    title: 'Welcome to LAUNCHR!',
    body: "This quick tour covers the main features available to you as an employee. Use the arrows or dots to navigate between steps.",
  },
  {
    icon: LayoutDashboard,
    title: 'Dashboard',
    route: '/employee',
    body: "Your starting point — a real-time summary of today's attendance and your leave balances.",
    bullets: [
      fmt("**Today's Status** shows whether you're clocked in, clocked out, or haven't started"),
      fmt("**Leave Balance** shows your remaining days per leave type"),
      fmt("**Monthly Attendance chart** breaks down completed, late, absent, and on-leave days"),
    ],
  },
  {
    icon: Clock,
    title: 'Attendance — Clock In / Out',
    route: '/employee/attendance',
    body: 'Record your work hours every day using the Attendance page.',
    bullets: [
      fmt("Click **Clock In** when you arrive and **Clock Out** when you leave"),
      fmt("The **timer** tracks your elapsed work time in real time"),
      fmt("Your **assigned schedule** is shown so you know your expected shift"),
      fmt("Browse past logs by **cutoff period** using the Prev / Next buttons"),
      fmt("__Note:__ late arrivals and absences are *automatically deducted from payroll*"),
    ],
  },
  {
    icon: ClipboardList,
    title: 'My Requests',
    route: '/employee/requests/new',
    body: 'Submit and track any HR request from a single page.',
    bullets: [
      fmt("**Overtime** — request recognition of extra hours worked after your shift"),
      fmt("**Half-Day / Undertime** — notify HR of an adjusted shift"),
      fmt("**Leave** — file a leave request; your *remaining balance is shown* before you submit"),
      fmt("**Certificate of Employment (COE)** — request official employment documentation"),
      fmt("**Schedule Change** — ask HR to adjust your assigned schedule for a specific date"),
      fmt("**Concern** — raise any question or issue directly to HR"),
      fmt("**Cash Advance** — request an amount, choose how many cutoffs to repay it over, and an optional interest rate"),
    ],
  },
  {
    icon: Wallet,
    title: 'My Loans',
    route: '/employee/loans',
    body: 'Track any cash advances or company loans you have, and see exactly how they’re being repaid.',
    bullets: [
      fmt("Once your Cash Advance request is **approved**, it appears here as an active loan"),
      fmt("See your **balance, installment amount,** and **status** at a glance"),
      fmt("Click the eye icon on a loan to see its **full payment history** — every cutoff that has deducted from it so far"),
      fmt("__Repayment is automatic__ — a portion is deducted from your payslip each cutoff until it's paid off"),
    ],
  },
  {
    icon: CalendarRange,
    title: 'Company Calendar',
    route: '/employee/calendar',
    body: 'See company-wide events and public holidays at a glance.',
    bullets: [
      'Browse by month to view scheduled holidays and company events',
      fmt("Holidays are *automatically reflected in payroll* — no action needed from you"),
    ],
  },
  {
    icon: User,
    title: 'My Profile',
    route: '/employee/profile',
    body: 'Keep your personal and payroll details up to date.',
    bullets: [
      fmt("Update your **name, email address,** and **phone number**"),
      fmt("Enter or correct your **government IDs**: SSS, PhilHealth, Pag-IBIG, and TIN"),
      fmt("Set your **bank account number** for salary disbursement"),
      fmt("Change your **login password** under the **Security** section"),
    ],
  },
  {
    icon: FileText,
    title: 'DTR Upload',
    route: '/employee/dtr',
    body: 'If your HR team has enabled this feature, you can upload your Daily Time Record (DTR) for payroll verification.',
    bullets: [
      fmt("Select the **month** and **cutoff period** (1st or 2nd cutoff)"),
      fmt("Upload a **PDF, JPG, or PNG** file — *maximum 10 MB*"),
      fmt("You can replace or delete an upload *before it auto-expires*"),
      fmt("This page *only appears in the sidebar* when DTR upload is enabled"),
    ],
  },
  {
    icon: CheckCircle2,
    title: "You're all set!",
    body: "You know the essentials of LAUNCHR. Click Done to get started, or revisit this guide anytime by clicking the Help & Tutorial button at the bottom of the sidebar.",
  },
]

// ─── HR / Accounting steps ─────────────────────────────────────
export const HR_STEPS = [
  {
    icon: LayoutDashboard,
    title: 'Welcome to LAUNCHR!',
    body: "This quick tour covers the management features available to HR and Accounting users. Use the arrows or dots to navigate.",
  },
  {
    icon: LayoutDashboard,
    title: 'Dashboard',
    route: '/hr',
    body: 'Your command center — a live overview of the entire organization.',
    bullets: [
      fmt("**Today's critical metrics:** present, absent, late, on leave, and short-hour counts"),
      fmt("**Weekly attendance trend chart** across the whole company"),
      fmt("**Department-level attendance rates** with color-coded health indicators"),
      fmt("**Pending leave requests** listed for immediate action"),
    ],
  },
  {
    icon: Users,
    title: 'Employees',
    route: '/hr/employees',
    body: 'Manage all employee records in one place.',
    bullets: [
      fmt("View the full **employee roster** with search and filter options"),
      fmt("**Add** new employees and fill in employment, payroll, and government ID details"),
      fmt("Open an employee profile to see their **attendance history, leave balance, and payslips**"),
      fmt("**Edit or deactivate** an employee record as needed"),
    ],
  },
  {
    icon: Clock,
    title: 'Attendance',
    route: '/hr/attendance',
    body: 'Monitor and manage attendance across all employees.',
    bullets: [
      fmt("**Attendance Logs** — view every clock-in and clock-out entry, filterable by date and employee"),
      fmt("Statuses are *calculated automatically*: completed, late, undertime, absent, on leave"),
      fmt("**DTR Management** — (if enabled) review and manage employee-submitted Daily Time Records"),
    ],
  },
  {
    icon: CalendarRange,
    title: 'Schedules',
    route: '/hr/employee-schedules',
    body: 'Control when and how employees are expected to work.',
    bullets: [
      fmt("**Employee Schedules** — assign a schedule template to each employee"),
      fmt("**Schedule Templates** — create fixed or flexible shift templates (start time, end time, grace period)"),
      fmt("__Employees without a schedule cannot clock in__ — assign one before their first day"),
    ],
  },
  {
    icon: ClipboardList,
    title: 'Requests',
    route: '/hr/requests',
    body: 'Review and act on all employee-submitted HR requests.',
    bullets: [
      fmt("The **sidebar badge** shows the count of pending items that need attention"),
      fmt("**Approve or reject** leave requests — the employee sees your decision immediately"),
      fmt("Handle overtime, undertime, half-day, **schedule change, COE,** and concerns"),
      fmt("*Approved leaves* are automatically reflected in **attendance and payroll**"),
      fmt("Approving a **Cash Advance** request creates an active loan, visible on the **Loans** page, repaid automatically each payroll cutoff"),
    ],
  },
  {
    icon: CalendarRange,
    title: 'Calendar',
    route: '/hr/calendar',
    body: 'Manage company-wide events and public holidays.',
    bullets: [
      fmt("**Add holidays or company events** that will appear on all employee calendars"),
      fmt("Assign **event types** (e.g., Regular Holiday, Special Non-Working) to control pay rules"),
      fmt("Events are *reflected automatically in payroll calculations*"),
    ],
  },
  {
    icon: Banknote,
    title: 'Payroll',
    route: '/hr/payroll',
    body: 'Generate and manage payroll runs for each cutoff period.',
    bullets: [
      fmt("**Payroll Runs** — generate payroll for a cutoff, review statutory deductions, and finalize"),
      fmt("**Email paystubs** directly to employees from the payroll detail page"),
      fmt("**13th Month Pay** — calculate and release 13th month pay based on actual days worked"),
      fmt("__Completed payrolls are locked__ to preserve records"),
    ],
  },
  {
    icon: Wallet,
    title: 'Loans',
    route: '/hr/loans',
    body: 'Track employee cash advances and government loans, and manage them after they’re created.',
    bullets: [
      fmt("Lists **cash advances** approved through Requests and **government loans** (SSS, Pag-IBIG) entered directly here"),
      fmt("Click **New Government Loan** to enter an SSS or Pag-IBIG loan — it's created active immediately, no approval needed"),
      fmt("Click the **pencil icon** to edit an installment amount, start cutoff, notes, or status"),
      fmt("Click the **cancel icon** on an active loan to stop future deductions — past history is preserved"),
      fmt("*Loans are repaid automatically* each payroll cutoff, capped so net pay never drops below the configured floor"),
    ],
  },
  {
    icon: User,
    title: 'Self Service',
    route: '/employee',
    body: 'As an HR or Accounting user, you also have access to your own employee portal.',
    bullets: [
      fmt("Your **personal dashboard, attendance clock, requests, and profile** are under Self Service"),
      'These work exactly the same as for regular employees',
      'Your own attendance and leaves are tracked and included in payroll',
    ],
  },
  {
    icon: CheckCircle2,
    title: "You're all set!",
    body: "You now know the key features of LAUNCHR. Click Done to start managing, or revisit this guide anytime via the Help & Tutorial button at the bottom of the sidebar.",
  },
]

// ─── Admin steps ───────────────────────────────────────────────
export const ADMIN_STEPS = [
  {
    icon: LayoutDashboard,
    title: 'Welcome, Administrator!',
    body: "As an Admin, you have full access to all management and system configuration features. This tour covers everything available to you.",
  },
  {
    icon: LayoutDashboard,
    title: 'Dashboard',
    route: '/hr',
    body: 'Your command center — a live overview of the entire organization.',
    bullets: [
      fmt("**Today's critical metrics:** present, absent, late, on leave, and short-hour counts"),
      fmt("**Weekly attendance trend chart** and **department-level attendance rates**"),
      fmt("**Pending leave requests** and quick stats — new hires, last payroll, and more"),
    ],
  },
  {
    icon: Users,
    title: 'Employees',
    route: '/hr/employees',
    body: 'Full control over all employee records.',
    bullets: [
      fmt("View, **add, edit, and deactivate** employee accounts"),
      fmt("Set employment details, **salary**, department, and employment type"),
      fmt("View each employee's **attendance history, leave balance, and payslips** from their profile"),
    ],
  },
  {
    icon: Clock,
    title: 'Attendance',
    route: '/hr/attendance',
    body: 'Monitor attendance across all employees and manage raw time records.',
    bullets: [
      fmt("**Attendance Logs** — browse and filter all clock-in/out records by employee or date"),
      fmt("Statuses (*completed, late, absent*, etc.) are *calculated automatically* by the system"),
      fmt("**DTR Management** — review employee-submitted Daily Time Records when the feature is enabled"),
      fmt("**Raw Attendance Logs** (under System) — an unfiltered audit-level view of all attendance events"),
    ],
  },
  {
    icon: CalendarRange,
    title: 'Schedules',
    route: '/hr/employee-schedules',
    body: 'Define and assign work schedules across the organization.',
    bullets: [
      fmt("**Schedule Templates** — create fixed-time or flexi-hour shift templates with grace periods"),
      fmt("**Employee Schedules** — assign the right template to each employee"),
      fmt("__Unassigned employees cannot clock in__ — always assign a schedule before their start date"),
    ],
  },
  {
    icon: ClipboardList,
    title: 'Requests',
    route: '/hr/requests',
    body: 'Review and act on all employee-submitted HR requests.',
    bullets: [
      fmt("The **sidebar badge** shows pending items that need action"),
      fmt("**Approve or reject** leave, overtime, undertime, half-day, schedule change, COE, and concerns"),
      fmt("*Approved leaves* are automatically applied to **attendance and payroll**"),
      fmt("Approving a **Cash Advance** request creates an active loan, visible on the **Loans** page, repaid automatically each payroll cutoff"),
    ],
  },
  {
    icon: CalendarRange,
    title: 'Calendar',
    route: '/hr/calendar',
    body: 'Manage the company calendar that all employees can see.',
    bullets: [
      fmt("**Add holidays and events** — they appear on *every employee's* calendar"),
      fmt("**Event types** (Regular Holiday, Special Non-Working, etc.) control payroll pay rules"),
      fmt("**Configure Calendar Event Types** in the System section to add or edit type categories"),
    ],
  },
  {
    icon: Banknote,
    title: 'Payroll',
    route: '/hr/payroll',
    body: 'Generate and finalize payroll for every cutoff period.',
    bullets: [
      fmt("**Payroll Runs** — generate for a cutoff, review gross pay, deductions (SSS, PhilHealth, Pag-IBIG, withholding tax), and net pay"),
      fmt("**Email paystubs** to employees directly from the payroll detail page"),
      fmt("**13th Month Pay** — calculate and release based on actual days worked in the year"),
      fmt("__Finalized payrolls are locked__ — create a new run to make corrections"),
    ],
  },
  {
    icon: Wallet,
    title: 'Loans',
    route: '/hr/loans',
    body: 'Track employee cash advances and government loans, and manage them after they’re created.',
    bullets: [
      fmt("Lists **cash advances** approved through Requests and **government loans** (SSS, Pag-IBIG) entered directly here"),
      fmt("Click **New Government Loan** to enter an SSS or Pag-IBIG loan — it's created active immediately, no approval needed"),
      fmt("Click the **pencil icon** to edit an installment amount, start cutoff, notes, or status"),
      fmt("Click the **cancel icon** on an active loan to stop future deductions — past history is preserved"),
      fmt("*Loans are repaid automatically* each payroll cutoff, capped so net pay never drops below the configured floor"),
    ],
  },
  {
    icon: Settings,
    title: 'System Settings',
    route: '/admin/system-settings',
    body: 'The control panel for how the entire system behaves.',
    bullets: [
      fmt("**Company identity** — set the system name, upload a logo, and choose the theme color"),
      fmt("**Payroll configuration** — set cutoff dates, overtime rules, and payroll frequency"),
      fmt("**DTR feature toggle** — enable or disable employee DTR file uploads system-wide"),
      fmt("__Changes here affect all users immediately__"),
    ],
  },
  {
    icon: Sliders,
    title: 'Configure Leave',
    route: '/admin/configure-leave',
    body: 'Define all leave types and the policies that govern them.',
    bullets: [
      fmt("**Create leave types** (e.g., Vacation Leave, Sick Leave, Maternity Leave)"),
      fmt("**Set the annual balance**, whether weekends count, and whether a balance is required"),
      fmt("*Policies apply to all employees* — per-employee overrides are not currently supported"),
    ],
  },
  {
    icon: CalendarRange,
    title: 'Configure Calendar Types',
    route: '/admin/calendar-event-types',
    body: 'Define the categories of events that can appear on the company calendar.',
    bullets: [
      fmt("**Create event types** like Regular Holiday, Special Non-Working Day, or Company Event"),
      fmt("Assign a **color** and **short code** for easy identification on the calendar"),
      fmt("These types are selected when *adding calendar events* in the Calendar page"),
    ],
  },
  {
    icon: UserCog,
    title: 'User Management',
    route: '/admin/users',
    body: 'Control who can access the system and what they can do.',
    bullets: [
      fmt("**Create user accounts** and assign roles: **admin, hr, accounting,** or **employee**"),
      fmt("Roles determine which **pages and features** a user can access"),
      fmt("**Deactivating** a user prevents login but *preserves all their historical records*"),
    ],
  },
  {
    icon: Building2,
    title: 'Departments',
    route: '/admin/departments',
    body: 'Manage the organizational structure used throughout the system.',
    bullets: [
      fmt("**Add, rename, or remove** departments"),
      fmt("Departments are assigned to **employees** and appear in **dashboard breakdowns**"),
      fmt("*Removing a department* does not delete employees — it only removes the label"),
    ],
  },
  {
    icon: History,
    title: 'Audit Logs',
    route: '/admin/audit-logs',
    body: 'A complete, tamper-evident record of every significant action taken in the system.',
    bullets: [
      fmt("See **who** made a change, **what** they changed, and **when**"),
      fmt("Covers **employee records, payroll edits, settings changes,** and more"),
      fmt("__Essential for compliance, dispute resolution, and accountability__"),
    ],
  },
  {
    icon: CheckCircle2,
    title: "You're all set!",
    body: "You have full visibility over LAUNCHR. Click Done to get started, or revisit this guide anytime via the Help & Tutorial button at the bottom of the sidebar.",
  },
]

// ─── Shared hook ──────────────────────────────────────────────
export function useOnboardingTutorial(key) {
  const [open, setOpen] = useState(() => !localStorage.getItem(key))
  const show = () => setOpen(true)
  const dismiss = () => {
    localStorage.setItem(key, '1')
    setOpen(false)
  }
  return { open, show, dismiss }
}

// ─── Modal component ──────────────────────────────────────────
export default function OnboardingTutorial({ open, onDismiss, steps }) {
  const [step, setStep] = useState(0)

  useEffect(() => {
    if (open) setStep(0)
  }, [open])

  if (!open || !steps?.length) return null

  const current = steps[step]
  const Icon = current.icon
  const isFirst = step === 0
  const isLast = step === steps.length - 1

  const next = () => (isLast ? onDismiss() : setStep(s => s + 1))
  const prev = () => setStep(s => Math.max(0, s - 1))

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50">
      <div className="bg-white rounded-2xl shadow-2xl w-full max-w-lg overflow-hidden">
        <div className="bg-brand-600 px-6 py-5 text-white relative">
          <button onClick={onDismiss} className="absolute top-4 right-4 p-1 rounded-lg hover:bg-white/20 transition-colors" aria-label="Close tutorial">
            <X size={16} />
          </button>
          <div className="flex items-center gap-3 mb-3">
            <div className="w-9 h-9 rounded-xl bg-white/20 flex items-center justify-center">
              <Icon size={18} />
            </div>
            <span className="text-xs font-semibold text-white/70 uppercase tracking-wider">
              Step {step + 1} of {steps.length}
            </span>
          </div>
          <h2 className="text-xl font-bold pr-8">{current.title}</h2>
          {current.route && <p className="text-xs text-white/50 font-mono mt-1">{current.route}</p>}
        </div>

        <div className="px-6 py-5 min-h-[180px]">
          <p className="text-sm text-gray-700 mb-4 leading-relaxed">{current.body}</p>
          {current.bullets?.length > 0 && (
            <ul className="space-y-2">
              {current.bullets.map((b, i) => (
                <li key={i} className="flex items-start gap-2.5 text-sm text-gray-600">
                  <span className="w-1.5 h-1.5 rounded-full bg-brand-500 mt-[7px] shrink-0" />
                  <span>{b}</span>
                </li>
              ))}
            </ul>
          )}
        </div>

        <div className="px-6 pb-6 flex items-center justify-between gap-3">
          <div className="flex items-center gap-1.5">
            {steps.map((_, i) => (
              <button key={i} onClick={() => setStep(i)} aria-label={`Go to step ${i + 1}`}
                className={`h-2 rounded-full transition-all ${i === step ? 'bg-brand-600 w-4' : 'bg-gray-200 hover:bg-gray-300 w-2'}`}
              />
            ))}
          </div>
          <div className="flex items-center gap-2">
            {!isFirst && (
              <button onClick={prev} className="btn-secondary flex items-center gap-1 px-3 py-2 text-sm">
                <ChevronLeft size={14} /> Back
              </button>
            )}
            <button onClick={next} className="btn-primary flex items-center gap-1 px-4 py-2 text-sm">
              {isLast ? 'Done' : <>Next <ChevronRight size={14} /></>}
            </button>
          </div>
        </div>
      </div>
    </div>
  )
}
