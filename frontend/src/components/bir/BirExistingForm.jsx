import { useMutation, useQueryClient } from '@tanstack/react-query'
import { FolderOpen, GitBranch } from 'lucide-react'
import { birKeys, reviseBirDraft } from '../../api/queries'

// POST /bir/drafts answers 409 when the period already has a form (contract §6, one return per
// period): data.existing is its latest version { id, label, status, version }.
export const existingForm = (err) => (err?.response?.status === 409 ? err.response.data?.data?.existing ?? null : null)

/**
 * The way to the form that already exists: open it, or, once it is filed, start its correction
 * (the same revise as the "Start a correction" button). onOpen receives the draft to show.
 */
export function ExistingFormButton({ existing, onOpen }) {
  const qc = useQueryClient()
  const revise = useMutation({
    mutationFn: () => reviseBirDraft(existing.id),
    onSuccess: (res) => {
      qc.invalidateQueries({ queryKey: birKeys.lists })
      onOpen(res.data)
    },
  })
  const filed = existing.status === 'finalized'
  return (
    <span className="inline-flex flex-wrap items-center gap-2">
      <button
        type="button"
        onClick={() => (filed ? revise.mutate() : onOpen({ id: existing.id }))}
        disabled={revise.isPending}
        className="btn-secondary py-1 px-2.5 text-xs"
      >
        {filed ? <GitBranch size={12} /> : <FolderOpen size={12} />}
        {filed ? (revise.isPending ? 'Creating…' : 'Start a correction') : `Open form ${existing.label ?? `#${existing.id}`}`}
      </button>
      {revise.isError && <span className="text-xs text-red-600">{revise.error?.response?.data?.message || 'Could not start the correction.'}</span>}
    </span>
  )
}

/** A failed create, as the server words it, with the button when the form already exists. */
export default function BirCreateError({ error, onOpen, fallback = 'Could not create the form.' }) {
  if (!error) return null
  const existing = existingForm(error)
  return (
    <div className="space-y-1.5">
      <p className="text-xs text-red-600">{error?.response?.data?.message || fallback}</p>
      {existing && <ExistingFormButton existing={existing} onOpen={onOpen} />}
    </div>
  )
}
