import {
  FileText, CalendarClock, ClipboardCheck, MessageCircle, ListChecks, FolderOpen, LayoutList, Palette, PencilLine,
  CheckCircle2, Signature, History, BookOpen,
} from 'lucide-react'
import { fmt } from '../OnboardingTutorial'
import BirGuideTour from './BirGuideTour'

const OPEN_A_FORM = 'Open a form from Saved forms to see this part. It is pointed at the form preview for now.'

// A walk-through of the BIR Forms page for a new accountant. Each step points at its part of the
// page (data-guide); the ones about a form fall back to the form preview while none is open.
export const BIR_GUIDE_STEPS = [
  {
    icon: FileText,
    title: 'Welcome to BIR Forms',
    body: 'This page prepares the BIR forms needed for compliance from payroll data. You check them, approve them, file them with the BIR as usual, and keep the record here.',
    bullets: [
      fmt('**1601-C**: taxes withheld on compensation, one per month'),
      fmt("**2316**: each employee's certificate, one per employee per year"),
      fmt('The arrows show where to click. Use **Next**, or the arrow keys'),
    ],
  },
  {
    icon: CalendarClock, target: 'pending',
    title: 'Pending: what to file next',
    body: 'Every form still to be filed, with its filing window and deadline.',
    bullets: [
      fmt("A month's **1601-C** is filed from the 1st to the 10th of the next month"),
      fmt('The **2316s** are filed from January 1 to 31'),
      fmt('Red is overdue. Click **Start** to begin a form, or **Open** to continue one'),
    ],
  },
  {
    icon: ClipboardCheck, target: 'attention',
    title: 'Needs your attention',
    body: 'Forms waiting for you: to review, returned to you, approved but not marked as filed, or not signed yet. Each shows its deadline; click one to open it.',
  },
  {
    icon: MessageCircle, target: 'chat',
    title: 'Ask the assistant',
    body: 'Click this button to open the assistant, and type what you need.',
    bullets: [
      fmt('*"Make the 1601-C for September 2026"* or *"2316 for Juan Cruz for 2025"*'),
      fmt('It fills in what it can and asks you for the rest, one box at a time'),
      fmt('Use the **full screen** button at the top of the chat for more room'),
    ],
  },
  {
    icon: ListChecks, target: 'picker',
    title: 'Or pick a form yourself',
    body: 'Choose the form, the year, and the month (1601-C) or the employee (2316), then click Create draft. If it already exists, you are offered to open it instead.',
  },
  {
    icon: FolderOpen, target: 'saved',
    title: 'Saved forms',
    body: 'Every form, newest first. Click one to open it.',
    bullets: [
      fmt('Filter by status or form, or tick **Group versions**'),
      fmt("The **Employees** tab shows each employee's 2316 for a year and starts the missing ones"),
    ],
  },
  {
    icon: LayoutList, target: 'preview',
    title: 'The form',
    body: 'The open form shows here, every box in the same order as the printed BIR form. Full view opens it on its own page.',
  },
  {
    icon: Palette, target: ['sources', 'preview'], missing: OPEN_A_FORM,
    title: 'Where each value came from',
    body: 'From payroll, Company settings, Typed by user, Manually changed, or Awaiting input. Click one to show only those boxes.',
    bullets: [fmt('The green bar shows how many boxes are filled; click the red **errors** count to jump to them')],
  },
  {
    icon: LayoutList, target: ['parts', 'preview'], missing: OPEN_A_FORM,
    title: 'Parts of the form',
    body: 'The boxes are grouped by the parts of the printed form, each in its own color. Click a part to jump to it.',
  },
  {
    icon: PencilLine, target: ['boxes', 'preview'], missing: OPEN_A_FORM,
    title: 'Fill in and change boxes',
    body: 'Click Fill in or Change on a box, type the value, and press Enter.',
    bullets: [
      fmt('Click **?** next to a box to see what it means'),
      fmt('A payroll figure you change is marked **Manually changed** and keeps the original'),
    ],
  },
  {
    icon: CheckCircle2, target: ['actions', 'preview'], missing: OPEN_A_FORM,
    title: 'Check, submit, approve and file',
    body: "The form's next step is always here.",
    bullets: [
      fmt('**Preview PDF** to check it, then **Submit for review**'),
      fmt('Accounting **Approves** or **Returns** it with a note'),
      fmt('File the approved PDF with the BIR, then click **Mark as filed**'),
      fmt('A draft made by mistake can be **voided** with a reason; nothing is deleted'),
    ],
  },
  {
    icon: Signature, target: ['actions', 'preview'], missing: OPEN_A_FORM,
    title: 'Sign the filed form (optional)',
    body: 'On a filed form, click Sign, or Sign by hand if it will be signed with a pen.',
    bullets: [
      fmt('Upload your signature once on **My Profile → E-signature**'),
      fmt('On a 2316, the employee then signs on their **Forms to sign** page'),
    ],
  },
  {
    icon: History, target: ['history', 'preview'], missing: OPEN_A_FORM,
    title: 'History of this form',
    body: 'Click here to see everything that happened to the form: who created, changed, submitted, approved, filed, signed or voided it, and when.',
  },
  {
    icon: BookOpen, target: 'guide',
    title: 'Open this guide again',
    body: "Click Guide any time to see this again. You're ready to start!",
  },
]

/** The guide itself: a tour that points at each part of the BIR Forms page. */
export default function BirAccountantGuide({ open, onDismiss }) {
  return <BirGuideTour open={open} onDismiss={onDismiss} steps={BIR_GUIDE_STEPS} />
}
