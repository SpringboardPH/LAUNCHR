import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Send, CheckCircle2, Undo2, Archive, GitBranch, Lock, Clock, PenLine } from 'lucide-react'
import clsx from 'clsx'
import {
  birKeys, getBirConfig, submitBirDraft, approveBirDraft, rejectBirDraft, finalizeBirDraft, reviseBirDraft,
} from '../../api/queries'
import { useAuth } from '../../store/AuthContext'
import { formatValue, formatWhen } from './BirDraftEditor'

const DONE = {
  submit: 'Submitted for review.',
  approve: 'Approved. The form is now locked.',
  reject: 'Returned to the preparer with your note.',
  finalize: 'Marked as filed.',
}

const FALLBACK_FLOW = { draft: ['pending'], pending: ['approved', 'draft'], approved: ['finalized'], finalized: [] }

// What the reader of the bar needs to know about the form in each status.
const STATUS_NOTE = {
  draft: 'Draft: fill in and check the boxes, then submit it for review.',
  pending: 'Waiting for review. The reviewer may correct boxes before approving.',
  approved: 'Approved and locked: boxes can no longer be changed. Mark it as filed once it is filed with the BIR.',
  finalized: 'Filed. This is the record of what was filed and can never be changed. To correct it, start a new version.',
}

/**
 * The form's next steps, driven by `status_flow` from /bir/config rather than a hardcoded list.
 * A preparer never sees Approve on their own form (the server refuses it with 403 too).
 */
export default function BirApprovalBar({ draft, onOpenDraft, edits = [], onShowEdits }) {
  const qc = useQueryClient()
  const { user } = useAuth()
  const [returning, setReturning] = useState(false)
  const [reason, setReason] = useState('')
  const [confirmFinalize, setConfirmFinalize] = useState(false)
  const [notice, setNotice] = useState(null)

  const { data: config } = useQuery({ queryKey: birKeys.config, queryFn: getBirConfig, staleTime: Infinity })
  const next = (config?.status_flow ?? FALLBACK_FLOW)[draft.status] ?? []
  const isPreparer = user?.id != null && draft.prepared_by?.id === user.id

  const run = useMutation({
    mutationFn: ({ action }) => ({
      submit: () => submitBirDraft(draft.id),
      approve: () => approveBirDraft(draft.id),
      reject: () => rejectBirDraft(draft.id, reason.trim()),
      finalize: () => finalizeBirDraft(draft.id),
      revise: () => reviseBirDraft(draft.id),
    })[action](),
    onMutate: () => setNotice(null),
    onSuccess: (res, { action }) => {
      qc.invalidateQueries({ queryKey: birKeys.lists })
      setReturning(false)
      setReason('')
      setConfirmFinalize(false)
      if (action === 'revise') {
        onOpenDraft(res.data)
        return
      }
      qc.setQueryData(birKeys.detail(draft.id), res.data)
      setNotice({ tone: 'ok', text: DONE[action] })
    },
    onError: (err) => {
      const status = err?.response?.status
      const body = err?.response?.data
      // 422 on submit/approve is "fix the form": the draft comes back with its errors marked.
      if (status === 422 && body?.data?.id === draft.id) qc.setQueryData(birKeys.detail(draft.id), body.data)
      // 400 means the form moved on elsewhere (someone else acted on it): show its current state.
      if (status === 400) qc.invalidateQueries({ queryKey: birKeys.detail(draft.id) })
      setNotice({ tone: 'error', text: body?.message || 'That did not go through. Please try again.' })
    },
  })
  const busy = run.isPending
  const doing = busy ? run.variables?.action : null

  const errorCount = (draft.validation_errors ?? []).filter(e => e.severity !== 'warning').length
  const people = [
    draft.prepared_by && `Prepared by ${draft.prepared_by.name}${isPreparer ? ' (you)' : ''}`,
    draft.approved_by && `approved by ${draft.approved_by.name}`,
  ].filter(Boolean).join(' · ')

  return (
    <div className="px-4 py-3 border-b border-gray-100 bg-gray-50/70 space-y-2">
      <p className="flex gap-2 text-xs text-gray-600">
        {['approved', 'finalized'].includes(draft.status)
          ? <Lock size={13} className="mt-0.5 shrink-0 text-gray-400" />
          : <Clock size={13} className="mt-0.5 shrink-0 text-gray-400" />}
        <span>
          {STATUS_NOTE[draft.status] ?? `Status: ${draft.status}.`}
          {people && <span className="block text-[11px] text-gray-400">{people}</span>}
        </span>
      </p>

      <div className="flex flex-wrap items-center gap-2">
        {next.includes('pending') && (
          <button onClick={() => run.mutate({ action: 'submit' })} disabled={busy} className="btn-primary py-1.5 text-xs"
            title={errorCount ? `${errorCount} error${errorCount === 1 ? '' : 's'} must be fixed first` : undefined}>
            <Send size={12} /> {doing === 'submit' ? 'Submitting…' : 'Submit for review'}
          </button>
        )}
        {next.includes('approved') && !isPreparer && (
          <button onClick={() => run.mutate({ action: 'approve' })} disabled={busy} className="btn-primary py-1.5 text-xs">
            <CheckCircle2 size={12} /> {doing === 'approve' ? 'Approving…' : 'Approve'}
          </button>
        )}
        {draft.status === 'pending' && next.includes('draft') && !returning && (
          <button onClick={() => setReturning(true)} disabled={busy} className="btn-secondary py-1.5 text-xs">
            <Undo2 size={12} /> Return to preparer
          </button>
        )}
        {next.includes('finalized') && (
          confirmFinalize ? (
            <span className="flex flex-wrap items-center gap-2 text-xs text-gray-700">
              Filed with the BIR? This can't be undone.
              <button onClick={() => run.mutate({ action: 'finalize' })} disabled={busy} className="btn-primary py-1.5 text-xs">
                {doing === 'finalize' ? 'Saving…' : 'Yes, mark as filed'}
              </button>
              <button onClick={() => setConfirmFinalize(false)} disabled={busy} className="btn-ghost py-1.5 text-xs">Cancel</button>
            </span>
          ) : (
            <button onClick={() => setConfirmFinalize(true)} disabled={busy} className="btn-primary py-1.5 text-xs">
              <Archive size={12} /> Mark as filed
            </button>
          )
        )}
        {draft.status === 'finalized' && (
          <button onClick={() => run.mutate({ action: 'revise' })} disabled={busy} className="btn-secondary py-1.5 text-xs">
            <GitBranch size={12} /> {doing === 'revise' ? 'Creating…' : 'Start a correction (new version)'}
          </button>
        )}
      </div>

      {/* Every box changed by hand, so the reviewer sees them all before approving. */}
      {edits.length > 0 && (
        <details open={draft.status === 'pending' && !isPreparer} className="rounded-lg border border-amber-200 bg-amber-50/60 px-3 py-2">
          <summary className="cursor-pointer text-xs font-medium text-amber-900">
            <PenLine size={12} className="inline mr-1 -mt-0.5" />
            {edits.length} box{edits.length === 1 ? '' : 'es'} changed by hand
          </summary>
          <ul className="mt-1.5 space-y-1">
            {edits.map(({ field, entry }) => (
              <li key={field.key} className="text-[11px] text-amber-900">
                <span className="font-medium">{field.item ? `Item ${field.item} · ` : ''}{field.label}:</span>{' '}
                {entry.system_value != null && <><span className="line-through">{formatValue(entry.system_value, field.type)}</span> → </>}
                <span className="font-semibold">{formatValue(entry.value, field.type) ?? 'cleared'}</span>
                <span className="text-amber-700">
                  {entry.edited_by ? ` · ${entry.edited_by.name}${entry.edited_at ? `, ${formatWhen(entry.edited_at)}` : ''}` : ' · editor not on record'}
                </span>
              </li>
            ))}
          </ul>
          {onShowEdits && (
            <button onClick={onShowEdits} className="mt-1.5 text-[11px] font-medium text-amber-800 underline">Show only these boxes</button>
          )}
        </details>
      )}

      {draft.status === 'pending' && isPreparer && (
        <p className="text-[11px] text-gray-500">You prepared this form, so someone else must approve it.</p>
      )}

      {returning && (
        <form
          className="space-y-2"
          onSubmit={(e) => { e.preventDefault(); if (reason.trim()) run.mutate({ action: 'reject' }) }}
        >
          <label className="block text-xs text-gray-600">
            What needs fixing? The preparer sees this on the form.
            <textarea
              className="input mt-1 text-sm"
              rows={2}
              maxLength={1000}
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              placeholder="e.g. Item 25 does not match the payroll register for May."
              autoFocus
            />
          </label>
          <div className="flex gap-2">
            <button type="submit" disabled={busy || !reason.trim()} className="btn-danger py-1.5 text-xs">
              {doing === 'reject' ? 'Returning…' : 'Return form'}
            </button>
            <button type="button" onClick={() => { setReturning(false); setReason('') }} disabled={busy} className="btn-ghost py-1.5 text-xs">
              Cancel
            </button>
          </div>
        </form>
      )}

      {notice && (
        <p role={notice.tone === 'error' ? 'alert' : 'status'}
          className={clsx('text-xs', notice.tone === 'error' ? 'text-red-600' : 'text-green-700')}>
          {notice.text}
        </p>
      )}
    </div>
  )
}
