import {
  FileText, FilePlus2, LayoutList, PencilLine, ScanSearch, CheckCircle2, Archive, Signature, History, Lightbulb,
} from 'lucide-react'
import OnboardingTutorial, { fmt } from '../OnboardingTutorial'

// A walk-through of the BIR Forms page for a new accountant, in the order a form is worked on.
export const BIR_GUIDE_STEPS = [
  {
    icon: FileText,
    title: 'Welcome to BIR Forms',
    body: 'This page prepares the BIR forms needed for compliance from payroll data. You check them, approve them, file them with the BIR as usual, and keep the record here.',
    bullets: [
      fmt('**1601-C**: the Monthly Remittance Return of Income Taxes Withheld on Compensation, one per month'),
      fmt("**2316**: each employee's Certificate of Compensation Payment/Tax Withheld, one per employee per year"),
      fmt('Payroll figures come from **finalized or paid** payroll runs, so finish payroll for the period first'),
      fmt('Open this guide again any time with the **Guide** button at the top of the page'),
    ],
  },
  {
    icon: FilePlus2,
    title: 'Start a form',
    body: 'There are two ways to start a form. Either one fills in every box it can from payroll and company settings.',
    bullets: [
      fmt('**Ask the assistant**: click the chat bubble at the bottom right and type, e.g. *"Make the 1601-C for September 2026"* or *"2316 for Juan Cruz for 2025"*'),
      fmt('**Pick a form manually**: choose the form, the year, and the month (1601-C) or the employee (2316), then **Create draft**'),
      fmt('If that form already exists, you are offered **Open form** instead, or **Start a correction** if it was already filed'),
      fmt('The assistant also asks you for the boxes it could not fill in, one at a time'),
    ],
  },
  {
    icon: LayoutList,
    title: 'Read the form',
    body: 'The form preview shows every box in the same order as the printed BIR form.',
    bullets: [
      fmt('Boxes are grouped by the **parts of the form**, each in its own color; click a part in **Form parts** to jump to it'),
      fmt('**Source** tells you where each value came from: *From payroll*, *Company settings*, *Typed by user*, *Manually changed*, or *Awaiting input*; click one to show only those boxes'),
      fmt('The green bar shows how many boxes are filled'),
      fmt('**Errors** (red) must be fixed before submitting; **warnings** (amber) are worth checking. Click the count to jump to the box'),
    ],
  },
  {
    icon: PencilLine,
    title: 'Fill in and correct boxes',
    body: 'Any box can be filled in or corrected until the form is filed.',
    bullets: [
      fmt('Click **Fill in** or **Change** on a box, type the value, and press Enter'),
      fmt('Click the **?** next to a box to see what it means and where its figure comes from'),
      fmt('Changing a figure from payroll marks it **Manually changed** and keeps the original, so the reviewer can see both'),
      fmt("On a 1601-C, **Employees on this form** shows each employee's share of the totals"),
      fmt('On a 2316 for a non-taxable employee, the taxable section is greyed out because it does not apply'),
    ],
  },
  {
    icon: ScanSearch,
    title: 'Check before you submit',
    body: 'Before sending the form for review, check it the way it will be filed.',
    bullets: [
      fmt('**Preview PDF** shows the official BIR form with the values filled in; it says *DRAFT — NOT FOR FILING* until approved'),
      fmt('**Compare with filed form**: import the figures of a form you filed before, and each box shows whether it matches'),
      fmt('**Boxes changed by hand** lists every manual change in one place'),
    ],
  },
  {
    icon: CheckCircle2,
    title: 'Submit, review and approve',
    body: 'Approving, returning and filing are done by accounting only.',
    bullets: [
      fmt('**Submit for review** once there are no errors'),
      fmt('The reviewer can correct boxes, then **Approve**, or **Return to preparer** with a note that the preparer sees on the form'),
      fmt('You may approve a form you prepared yourself'),
      fmt('Changing a box on an approved form sends it back to draft, to be submitted and approved again'),
    ],
  },
  {
    icon: Archive,
    title: 'File it and mark it as filed',
    body: 'Filing with the BIR itself is done outside LAUNCHR, as usual.',
    bullets: [
      fmt('Once approved, use **Download PDF** for the official form and file it with the BIR'),
      fmt('Then click **Mark as filed**. A filed form is locked: it is the record of what was filed'),
      fmt('To correct a filed form, click **Start a correction (new version)**. A 1601-C can be amended **up to 5 times** per month'),
    ],
  },
  {
    icon: Signature,
    title: 'Sign the filed form (optional)',
    body: "Signing is optional and never blocks anything. Upload your signature once and reuse it.",
    bullets: [
      fmt('Upload your signature on **My Profile → E-signature** (a PNG or JPG picture of your signature)'),
      fmt('On a filed form, click **Sign** to sign as the employer, or **Sign by hand (wet signature)** if it will be signed with a pen on the printout'),
      fmt('On a 2316, the employee then gets an email and signs it on their **Forms to sign** page'),
      fmt('The form shows who signed and when, or *Waiting for the employee*'),
    ],
  },
  {
    icon: History,
    title: 'Void a mistake, and see the history',
    body: 'Forms are never deleted, so the record stays complete.',
    bullets: [
      fmt('A draft made by mistake can be **voided** with a reason. It stays on the list, marked void, and its month or year can be prepared again'),
      fmt('**History of this form** shows everything that happened to it: who created, changed, submitted, approved, filed, signed or voided it, and when'),
      fmt('Every changed box in the history shows its old and new value'),
    ],
  },
  {
    icon: Lightbulb,
    title: 'Tips',
    body: "You're ready to start. A few more things that help:",
    bullets: [
      fmt('**Saved forms** lists every form; filter by status or form, or tick **Group versions** to see corrections together'),
      fmt("The **Employees** tab shows each employee's 2316 for a year, filterable by client, and starts one for anyone who doesn't have it yet"),
      fmt('**Full view** opens a form on its own page, using the whole screen'),
      fmt('Ask the assistant questions about the data too, e.g. *"Who still needs a 2316 for 2025?"*'),
      fmt('If the page says BIR Forms is turned off, ask the administrator to turn it on in System Settings'),
    ],
  },
]

/** The guide itself: the app's step-by-step tour, with the BIR Forms steps. */
export default function BirAccountantGuide({ open, onDismiss }) {
  return <OnboardingTutorial open={open} onDismiss={onDismiss} steps={BIR_GUIDE_STEPS} />
}
