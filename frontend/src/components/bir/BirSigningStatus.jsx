import { PenLine, CheckCircle2, Clock } from 'lucide-react'
import { format, parseISO } from 'date-fns'

const on = (at) => (at ? ` on ${format(parseISO(at), 'MMM d, yyyy')}` : '')

// A short word for lists: null while nothing is signed. Signing is optional and never blocks anything.
export const signingChip = (signing, formType) => {
  if (signing?.method === 'paper') return ['Signed by hand', 'badge-gray']
  if (signing?.method !== 'digital' || !signing.employer) return null
  if (formType === '2316' && !signing.employee) return ['Waiting for the employee', 'badge-yellow']
  return ['Signed', 'badge-green']
}

/**
 * Who signed the form and when, from `signing` on every draft (contract §2):
 * "Signed by <name> on <date>" per signer, "Signed by hand" for paper, and on a 2316 the
 * employer signed, "Waiting for the employee". Nothing when no signing method is chosen.
 */
export default function BirSigningStatus({ signing, formType, className = '' }) {
  if (!signing?.method) return null

  if (signing.method === 'paper') {
    return (
      <p className={`flex items-center gap-1.5 text-xs text-gray-700 ${className}`}>
        <PenLine size={12} className="text-gray-500" /> Signed by hand (wet signature on the printed form)
      </p>
    )
  }

  const rows = [['Employer', signing.employer]]
  if (formType === '2316') rows.push(['Employee', signing.employee])

  return (
    <ul className={`space-y-0.5 text-xs ${className}`}>
      {rows.map(([role, who]) => (
        <li key={role} className="flex items-center gap-1.5">
          {who
            ? <><CheckCircle2 size={12} className="text-green-600" /><span className="text-gray-500">{role}:</span> <span className="text-gray-800">Signed by {who.name}{on(who.signed_at)}</span></>
            : <><Clock size={12} className="text-amber-500" /><span className="text-gray-500">{role}:</span> <span className="text-amber-700">Waiting for the {role.toLowerCase()}</span></>}
        </li>
      ))}
    </ul>
  )
}
