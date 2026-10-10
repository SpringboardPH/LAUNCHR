import { useEffect, useRef, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Send, Bot, User, AlertTriangle, FileCheck2, RotateCcw } from 'lucide-react'
import clsx from 'clsx'
import { birKeys, sendBirChatMessage, createBirDraft, updateBirDraft, validateBirDraft } from '../../api/queries'

const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July',
  'August', 'September', 'October', 'November', 'December']

const SUGGESTIONS = [
  'Generate the August 2026 1601-C',
  'I need a 2316 for Ana Reyes for 2025',
  'Prepare the 1601-C for last month',
]

const GREETING = {
  id: 'greeting',
  role: 'assistant',
  text: 'Hi! Tell me which BIR form you need and for what period — for example, "the August 2026 1601-C". I only prepare drafts; a second person still reviews every form before it is final.',
}

let seq = 0
const nextId = () => `m${++seq}`

// Official titles, used wherever a form is named on screen.
export const FORM_NAMES = {
  '1601-C': 'Monthly Remittance Return of Income Taxes Withheld on Compensation',
  '2316': 'Certificate of Compensation Payment/Tax Withheld',
}
export const formName = (formType) => FORM_NAMES[formType] ?? formType

export const describePeriod = (formType, period) => {
  if (!period) return '—'
  if (formType === '1601-C' && /^\d{4}-\d{2}$/.test(period)) {
    const [y, m] = period.split('-')
    return `${MONTHS[Number(m) - 1]} ${y}`
  }
  return `Tax year ${period}`
}

const errorText = (err) =>
  err?.response?.data?.message || 'Something went wrong. Please try again.'

// PUT refuses a bad answer with a 422 naming the field: { errors: { "fields.<key>": ["…"] } }.
const fieldError = (err, field) =>
  err?.response?.data?.errors?.[`fields.${field}`]?.[0] ?? errorText(err)

function IntentCard({ intent, status, onConfirm, onDecline }) {
  return (
    <div className="mt-2 rounded-lg border border-brand-100 bg-white p-3 text-xs text-gray-700">
      <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1">
        <dt className="text-gray-400">Form</dt>
        <dd className="font-medium">{formName(intent.form_type)}</dd>
        <dt className="text-gray-400">Period</dt>
        <dd className="font-medium">{describePeriod(intent.form_type, intent.period)}</dd>
        {intent.employee_query && (
          <>
            <dt className="text-gray-400">Employee</dt>
            <dd className="font-medium">{intent.employee_query}</dd>
          </>
        )}
      </dl>
      {status === 'open' && (
        <div className="mt-3 flex flex-wrap gap-2">
          <button onClick={onConfirm} className="btn-primary text-xs py-1.5">
            <FileCheck2 size={13} /> Create draft
          </button>
          <button onClick={onDecline} className="btn-ghost text-xs py-1.5">
            Not quite
          </button>
        </div>
      )}
      {status === 'creating' && <p className="mt-2 text-gray-400">Creating draft…</p>}
      {status === 'done' && <p className="mt-2 text-green-600">Draft created.</p>}
      {status === 'declined' && <p className="mt-2 text-gray-400">Cancelled.</p>}
    </div>
  )
}

function Candidates({ candidates, disabled, onPick }) {
  return (
    <div className="mt-2 flex flex-wrap gap-1.5">
      {candidates.map(c => (
        <button
          key={c.id}
          onClick={() => onPick(c)}
          disabled={disabled}
          className="rounded-full border border-brand-200 bg-white px-3 py-1 text-xs text-brand-700 hover:bg-brand-50 disabled:opacity-50 disabled:cursor-not-allowed"
        >
          {c.name} <span className="text-gray-400">({c.employee_id})</span>
        </button>
      ))}
    </div>
  )
}

function Message({ msg, onConfirm, onDecline, onPick, busy }) {
  const isUser = msg.role === 'user'
  return (
    <div className={clsx('flex gap-2.5', isUser && 'flex-row-reverse')}>
      <div className={clsx(
        'w-7 h-7 shrink-0 rounded-full flex items-center justify-center',
        isUser ? 'bg-gray-200 text-gray-600' : msg.error ? 'bg-amber-100 text-amber-700' : 'bg-brand-100 text-brand-700'
      )}>
        {isUser ? <User size={14} /> : msg.error ? <AlertTriangle size={14} /> : <Bot size={14} />}
      </div>
      <div className={clsx(
        'max-w-[85%] rounded-2xl px-3.5 py-2.5 text-sm leading-relaxed whitespace-pre-line',
        isUser && 'bg-brand-600 text-white rounded-tr-sm',
        !isUser && !msg.error && 'bg-gray-100 text-gray-800 rounded-tl-sm',
        msg.error && 'bg-amber-50 text-amber-900 border border-amber-200 rounded-tl-sm'
        )}>
        {msg.text}
        {msg.candidates?.length > 0 && (
          <Candidates candidates={msg.candidates} disabled={busy || msg.picked} onPick={(c) => onPick(msg, c)} />
        )}
        {msg.intent && (
          <IntentCard
            intent={msg.intent}
            status={msg.intentStatus}
            onConfirm={() => onConfirm(msg)}
            onDecline={() => onDecline(msg)}
          />
        )}
      </div>
    </div>
  )
}

function TypingIndicator() {
  return (
    <div className="flex gap-2.5" aria-live="polite" aria-label="Assistant is typing">
      <div className="w-7 h-7 shrink-0 rounded-full bg-brand-100 text-brand-700 flex items-center justify-center">
        <Bot size={14} />
      </div>
      <div className="rounded-2xl rounded-tl-sm bg-gray-100 px-4 py-3 flex items-center gap-1">
        {[0, 150, 300].map(d => (
          <span key={d} className="w-1.5 h-1.5 rounded-full bg-gray-400 animate-bounce" style={{ animationDelay: `${d}ms` }} />
        ))}
      </div>
    </div>
  )
}

/**
 * Conversation with the BIR assistant. Chat history is UI state for this visit
 * only; drafts it creates are server state and are reported via onDraftCreated.
 */
export default function BirChatPanel({ onDraftCreated }) {
  const qc = useQueryClient()
  const [messages, setMessages] = useState([GREETING])
  const [input, setInput] = useState('')
  const scrollRef = useRef(null)
  const inputRef = useRef(null)
  // The server remembers a conversation for 30 minutes; the first message after
  // opening the page or "New conversation" tells it to start over.
  const freshRef = useRef(true)
  // While the chat is filling in a draft: which draft, and which field it just asked about.
  const [filling, setFilling] = useState(null)

  const push = (msg) => setMessages(prev => [...prev, { id: nextId(), ...msg }])
  const patch = (id, changes) => setMessages(prev => prev.map(m => (m.id === id ? { ...m, ...changes } : m)))

  const chat = useMutation({
    mutationFn: ({ message, reset, draftId, field }) => sendBirChatMessage(message, { reset, draftId, field }),
    onSuccess: (res) => {
      // An answer the chat read is saved first; "Got it" is only shown once it is.
      if (res.answer) {
        save.mutate({ draftId: res.draft_id, ...res.answer, reply: res.reply })
        return
      }
      push({
        role: 'assistant',
        text: res.reply,
        intent: res.understood ? res.intent : null,
        intentStatus: res.understood ? 'open' : null,
        candidates: res.candidates ?? [],
      })
      if ('draft_id' in res) setFilling(res.question ? { draftId: res.draft_id, field: res.question.field } : null)
    },
    onError: (err) => push({ role: 'assistant', error: true, text: errorText(err) }),
  })

  // Saves an answer through PUT, so it is checked and tracked like any other edit, then asks
  // the next question. A refused answer leaves the same question open for another try.
  const save = useMutation({
    mutationFn: async ({ draftId, field, value }) => {
      await updateBirDraft(draftId, { [field]: value })
      try {
        await validateBirDraft(draftId) // keeps validation_errors current for the preview
      } catch {
        // The answer is saved either way; the next validate brings the errors up to date.
      }
    },
    onSuccess: (_, { draftId, reply }) => {
      push({ role: 'assistant', text: reply })
      qc.invalidateQueries({ queryKey: birKeys.lists })
      qc.invalidateQueries({ queryKey: birKeys.detail(draftId) })
      chat.mutate({ draftId })
    },
    onError: (err, { field }) => push({ role: 'assistant', error: true, text: fieldError(err, field) }),
  })

  const create = useMutation({
    mutationFn: async ({ intent }) => {
      const created = await createBirDraft({
        form_type: intent.form_type,
        period: intent.period,
        ...(intent.employee_id && { employee_id: intent.employee_id }),
      })
      return validateBirDraft(created.data.id)
    },
    onSuccess: (res, { msgId }) => {
      const draft = res.data
      const issues = draft.validation_errors ?? []
      const errors = issues.filter(e => e.severity === 'error').length
      const warnings = issues.filter(e => e.severity === 'warning').length
      patch(msgId, { intentStatus: 'done' })
      push({
        role: 'assistant',
        text: `Draft #${draft.id} is ready: ${formName(draft.form_type)}, ${describePeriod(draft.form_type, draft.period)}. `
          + (errors
            ? `${errors} issue${errors === 1 ? '' : 's'} must be fixed before it can be submitted`
            : 'Nothing is blocking submission')
          + (warnings ? ` (${warnings} warning${warnings === 1 ? '' : 's'} to review).` : '.'),
      })
      qc.invalidateQueries({ queryKey: birKeys.lists })
      qc.invalidateQueries({ queryKey: birKeys.detail(draft.id) })
      onDraftCreated?.(draft)
      chat.mutate({ draftId: draft.id }) // first missing question, if any
    },
    onError: (err, { msgId }) => {
      patch(msgId, { intentStatus: 'open' })
      push({ role: 'assistant', error: true, text: errorText(err) })
    },
  })

  const busy = chat.isPending || create.isPending || save.isPending

  useEffect(() => {
    scrollRef.current?.scrollTo({ top: scrollRef.current.scrollHeight, behavior: 'smooth' })
  }, [messages, busy])

  useEffect(() => {
    if (!busy) inputRef.current?.focus()
  }, [busy])

  const send = (text) => {
    const message = text.trim()
    if (!message || busy) return
    push({ role: 'user', text: message })
    setInput('')
    chat.mutate(filling
      ? { message, draftId: filling.draftId, field: filling.field }
      : { message, reset: freshRef.current })
    freshRef.current = false
  }

  const pick = (msg, candidate) => {
    patch(msg.id, { picked: true })
    send(candidate.employee_id)
  }

  const confirm = (msg) => {
    patch(msg.id, { intentStatus: 'creating' })
    create.mutate({ intent: msg.intent, msgId: msg.id })
  }

  const decline = (msg) => {
    patch(msg.id, { intentStatus: 'declined' })
    push({ role: 'assistant', text: 'No problem — which form and period did you mean?' })
  }

  const reset = () => {
    setMessages([GREETING])
    setInput('')
    setFilling(null)
    freshRef.current = true
  }

  const onKeyDown = (e) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault()
      send(input)
    }
  }

  const showSuggestions = messages.length === 1 && !busy

  return (
    <div className="card flex flex-col h-[calc(100vh-220px)] min-h-[460px] overflow-hidden">
      <div className="flex items-center justify-between px-4 py-3 border-b border-gray-100">
        <div className="flex items-center gap-2">
          <Bot size={16} className="text-brand-600" />
          <h2 className="text-sm font-semibold text-gray-900">Form assistant</h2>
        </div>
        <button onClick={reset} disabled={busy || messages.length === 1} className="btn-ghost text-xs py-1">
          <RotateCcw size={12} /> New conversation
        </button>
      </div>

      <div ref={scrollRef} className="flex-1 overflow-y-auto px-4 py-4 space-y-4" role="log" aria-live="polite">
        {messages.map(m => (
          <Message key={m.id} msg={m} onConfirm={confirm} onDecline={decline} onPick={pick} busy={busy} />
        ))}
        {busy && <TypingIndicator />}
        {showSuggestions && (
          <div className="flex flex-wrap gap-2 pl-9">
            {SUGGESTIONS.map(s => (
              <button
                key={s}
                onClick={() => send(s)}
                className="rounded-full border border-gray-200 bg-white px-3 py-1.5 text-xs text-gray-600 hover:border-brand-300 hover:text-brand-700 transition-colors"
             >
                {s}
              </button>
            ))}
          </div>
        )}
      </div>

      <form
        onSubmit={(e) => { e.preventDefault(); send(input) }}
        className="border-t border-gray-100 p-3 flex items-end gap-2"
      >
        <textarea
          ref={inputRef}
          rows={1}
          value={input}
          maxLength={500}
          onChange={(e) => setInput(e.target.value)}
          onKeyDown={onKeyDown}
          disabled={busy}
          placeholder={busy ? 'Waiting for the assistant…' : filling ? 'Type your answer…' : 'e.g. Generate the August 2026 1601-C'}
          className="input flex-1 resize-none max-h-32 disabled:bg-gray-50"
          aria-label="Message the form assistant"
        />
        <button type="submit" disabled={busy || !input.trim()} className="btn-primary h-[38px]" aria-label="Send">
          <Send size={15} />
        </button>
      </form>
    </div>
  )
} 