import { useEffect, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Send, Bot, User, AlertTriangle, FileCheck2, RotateCcw, HelpCircle, X, Maximize2, Minimize2 } from 'lucide-react'
import clsx from 'clsx'
import {
  birKeys, sendBirChatMessage, createBirDraft, updateBirDraft, validateBirDraft, getBirEmployees, reviseBirDraft, submitBirDraft,
} from '../../api/queries'
import { ExistingFormButton, existingForm } from './BirExistingForm'
import BirTokenMeter from './BirTokenMeter'
import { useAuth } from '../../store/AuthContext'

// The 2316 example names someone who is really on file, so it works on any database.
const suggestionsFor = (employee) => [
  'Generate the August 2026 1601-C',
  employee
    ? `I need a 2316 for ${employee.first_name} ${employee.last_name} for ${new Date().getFullYear()}`
    : `I need a 2316 for ${new Date().getFullYear()}`,
  'Prepare the 1601-C for last month',
]

const GREETING = {
  id: 'greeting',
  role: 'assistant',
  text: 'Hi! Tell me which BIR form you need and for what period — for example, "the August 2026 1601-C". I only prepare drafts; a second person still reviews every form before it is final.',
}

let seq = 0
const nextId = () => `m${++seq}`

// The conversation survives leaving the page (Full view and back) for the rest of this tab's
// session, per user and per login: signing in again starts a fresh chat. Storage can be
// unavailable (private mode, blocked): then it simply isn't kept.
const storageKey = (userId) => {
  let login = ''
  try {
    login = (localStorage.getItem('hr_token') ?? '').split('|')[0] // the token's id, not the secret
  } catch {
    // No token to tell logins apart; the user id alone keys the chat.
  }
  return `bir-chat:${userId ?? 'anon'}:${login}`
}
const loadChat = (userId) => {
  try {
    const saved = JSON.parse(sessionStorage.getItem(storageKey(userId)) ?? 'null')
    if (!Array.isArray(saved?.messages) || !saved.messages.length) return null
    // Message ids restart after a reload; carry on from the highest one kept.
    for (const m of saved.messages) seq = Math.max(seq, Number(String(m.id).slice(1)) || 0)
    // A card that was mid-create when the page was left can't finish now: offer it again.
    const messages = saved.messages.map(m => (m.intentStatus === 'creating' ? { ...m, intentStatus: 'open' } : m))
    return { messages, filling: saved.filling ?? null }
  } catch {
    return null
  }
}
const saveChat = (userId, chat) => {
  try {
    if (chat) sessionStorage.setItem(storageKey(userId), JSON.stringify(chat))
    else sessionStorage.removeItem(storageKey(userId))
  } catch {
    // Not kept; the chat still works for this visit.
  }
}

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
    return `${m}/${y}` // MM/YYYY, as item 1 of the 1601-C writes it
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
        {(intent.employee_name || intent.employee_query) && (
          <>
            <dt className="text-gray-400">Employee</dt>
            <dd className="font-medium">{intent.employee_name ?? intent.employee_query}</dd>
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

// The help text for the box a question asks about, behind a "What does this mean?" link.
function Guidance({ text }) {
  const [open, setOpen] = useState(false)
  return (
    <div className="mt-1.5">
      <button onClick={() => setOpen(o => !o)} className="inline-flex items-center gap-1 text-xs text-brand-700 hover:underline">
        <HelpCircle size={12} /> {open ? 'Hide help' : 'What does this mean?'}
      </button>
      {open && <p className="mt-1 text-xs text-gray-600 whitespace-normal">{text}</p>}
    </div>
  )
}

function Message({ msg, onConfirm, onDecline, onPick, onOpen, busy }) {
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
        {msg.guidance && <Guidance text={msg.guidance} />}
        {msg.existing && onOpen && <div className="mt-2"><ExistingFormButton existing={msg.existing} onOpen={onOpen} /></div>}
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

// 2316 item 2 "To", asked when the year isn't over: a day of the form's year, sent as the
// answer in MM/DD, or the date it already has.
function ChatDatePicker({ picker, onSend }) {
  const { year, value } = picker
  const md = String(value).match(/^(\d{2})\/(\d{2})$/)
  const [date, setDate] = useState(md ? `${year}-${md[1]}-${md[2]}` : `${year}-12-31`)

  return (
    <div className="flex flex-wrap items-center gap-2 border-t border-gray-100 px-3 pt-3">
      <input
        type="date" value={date} min={`${year}-01-01`} max={`${year}-12-31`}
        onChange={(e) => setDate(e.target.value)}
        className="input py-1 text-sm w-auto" aria-label="Last day this 2316 covers"
      />
      <button type="button" onClick={() => onSend(`${date.slice(5, 7)}/${date.slice(8, 10)}`)} disabled={!date} className="btn-primary py-1.5 text-xs">
        Use this date
      </button>
      <button type="button" onClick={() => onSend(value)} className="btn-ghost py-1.5 text-xs">Keep {value}</button>
    </div>
  )
}

function TypingIndicator() {
  return (
    <div className="flex gap-2.5" aria-live="polite" aria-label="Assistant is typing">
      <div className="w-7 h-7 shrink-0 rounded-full bg-brand-100 text-brand-700 flex items-center justify-center">
        <Bot size={14} />
      </div>
      <div className="rounded-2xl rounded-tl-sm bg-gray-100 px-4 py-3 flex items-center gap-1">{[0, 150, 300].map(d => (
          <span key={d} className="w-1.5 h-1.5 rounded-full bg-gray-400 animate-bounce" style={{ animationDelay: `${d}ms` }} />
        ))}
      </div>
    </div>
  )
}

/**
 * Conversation with the BIR assistant. Chat history is kept for this tab's session (so
 * Full view and back doesn't lose it); drafts it creates are server state and are
 * reported via onDraftCreated.
 *
 * openDraftId is the draft open on the page (from Saved forms, "Open with the assistant",
 * or one this chat created), and openKey counts each time one is opened. When a draft the
 * chat isn't on is opened, the chat moves to it: it asks that draft's next question, and
 * changes and questions apply to it.
 *
 * The other way round, when the chat moves to another form (a box changed on a form that
 * wasn't open, a correction it started), that form is opened on the page through
 * onDraftCreated, so the preview always shows what the chat is working on. When the chat
 * asks for a workflow step (action: start a correction, or submit for review), it is run
 * here through the same endpoints as the buttons, and the chat's reply is shown once it
 * has gone through.
 */
export default function BirChatPanel({ onDraftCreated, onQuestion, openDraftId = null, openKey = 0, onClose, className, voidedDraftId = null, fullscreen = false, onToggleFullscreen }) {
  const qc = useQueryClient()
  const { user } = useAuth()
  const { data: employees } = useQuery({ queryKey: birKeys.employees, queryFn: getBirEmployees, staleTime: 5 * 60_000 })
  const [restored] = useState(() => loadChat(user?.id))
  const [messages, setMessages] = useState(restored?.messages ?? [GREETING])
  const [input, setInput] = useState('')
  const scrollRef = useRef(null)
  const inputRef = useRef(null)
  // The server remembers a conversation for 30 minutes; the first message after
  // opening the page or "New conversation" tells it to start over.
  const freshRef = useRef(!restored)
  // Once a draft exists, the chat stays on it until "New conversation": which draft, and the
  // field it just asked about (null when nothing is open, so a message is a question about it).
  const [filling, setFilling] = useState(restored?.filling ?? null)
  // A draft this chat just created: the page reports it back as the open draft, and the chat
  // is already on it.
  const createdRef = useRef(null)

  useEffect(() => {
    saveChat(user?.id, messages.length > 1 ? { messages, filling } : null)
  }, [user?.id, messages, filling])

  const push = (msg) => setMessages(prev => [...prev, { id: nextId(), ...msg }])
  const patch = (id, changes) => setMessages(prev => prev.map(m => (m.id === id ? { ...m, ...changes } : m)))

  // The open form was voided: it can no longer be changed (a 404 for every change), so the chat lets it go.
  useEffect(() => {
    if (!voidedDraftId || filling?.draftId !== voidedDraftId) return
    setFilling(null)
    onQuestion?.(null)
    push({ role: 'assistant', text: `Form #${voidedDraftId} was voided. It stays on the list for the record. Which form and period do you need now?` })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [voidedDraftId])

  // Opens on the page the form the chat has moved to, without announcing it in the chat.
  const follow = (draftId) => {
    if (!draftId || draftId === openDraftId) return
    createdRef.current = draftId
    onDraftCreated?.({ id: draftId })
  }

  const chat = useMutation({
    mutationFn: ({ message, reset, draftId, field }) => sendBirChatMessage(message, { reset, draftId, field, actions: true }),
    onSuccess: (res) => {
      // A workflow step the chat asked for runs first; its reply is shown once it has.
      if (res.action) {
        act.mutate({ action: res.action, reply: res.reply })
        return
      }
      follow(res.draft_id)
      // The chat can change a form itself (a due date, a box): the lists and the to-do catch up.
      qc.invalidateQueries({ queryKey: birKeys.allDrafts })
      qc.invalidateQueries({ queryKey: [...birKeys.lists, 'list'] })
      qc.invalidateQueries({ queryKey: birKeys.tokenUsage })
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
        guidance: res.question?.guidance ?? null,
      })
      if ('draft_id' in res) {
        setFilling({ draftId: res.draft_id, field: res.question?.field ?? null, picker: res.question?.picker ?? null })
        onQuestion?.(res.question?.field ?? null) // the preview highlights the box being asked about
      }
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

  // "yes" to a correction: start it like the "Start a correction" button, then the chat makes
  // the change it offered on the new version. "Submit it for review": submit, like the "Submit for review" button.
  const act = useMutation({
    mutationFn: ({ action }) => (action.type === 'revise' ? reviseBirDraft(action.draft_id) : submitBirDraft(action.draft_id)),
    onSuccess: (res, { action, reply }) => {
      qc.invalidateQueries({ queryKey: birKeys.lists })
      qc.invalidateQueries({ queryKey: birKeys.detail(action.draft_id) })
      if (action.type === 'revise') {
        setFilling({ draftId: res.data.id, field: null })
        follow(res.data.id)
        chat.mutate({ draftId: res.data.id })
        return
      }
      if (reply) push({ role: 'assistant', text: reply })
    },
    onError: (err) => push({ role: 'assistant', error: true, text: errorText(err) }),
  })

  const create = useMutation({
    mutationFn: async ({ intent }) => {
      const created = await createBirDraft({
        form_type: intent.form_type,
        period: intent.period,
        ...(intent.employee_id && { employee_id: intent.employee_id }),
      })
      // The draft exists now. If validate fails, carry on with it rather than reporting an
      // error, which would invite a retry that creates a second draft.
      try {
        return await validateBirDraft(created.data.id)
      } catch {
        return created
      }
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
      createdRef.current = draft.id
      onDraftCreated?.(draft)
      chat.mutate({ draftId: draft.id }) // first missing question, if any
    },
    onError: (err, { msgId }) => {
      patch(msgId, { intentStatus: 'open' })
      // 409: the period already has a form; the message carries the button to it.
      push({ role: 'assistant', error: true, text: errorText(err), existing: existingForm(err) })
    },
  })

  const busy = chat.isPending || create.isPending || save.isPending || act.isPending

  // A draft opened on the page (Saved forms, "Open with the assistant"): move the chat to it.
  // Run after the render settles, and cancelled on cleanup: StrictMode (development) mounts
  // twice, and only the second run goes ahead, so the opening is announced and asked once.
  useEffect(() => {
    if (!openDraftId || openDraftId === filling?.draftId || openDraftId === createdRef.current) return
    const t = setTimeout(() => {
      setFilling({ draftId: openDraftId, field: null })
      push({ role: 'assistant', text: `Now working on draft #${openDraftId}. Ask about any box, or say "change item 12 to …".` })
      // The first message of a conversation says so, even on a form, so "undo" forgets older chats.
      chat.mutate({ draftId: openDraftId, reset: freshRef.current }) // its next question, or that nothing is left to ask
      freshRef.current = false
    }, 0)
    return () => clearTimeout(t)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [openDraftId, openKey])

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
      ? { message, draftId: filling.draftId, field: filling.field, reset: freshRef.current }
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
    createdRef.current = null
    onQuestion?.(null)
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
    <div className={clsx('card flex flex-col overflow-hidden', className ?? 'h-[calc(100vh-220px)] min-h-[460px]')}>
      <div className="flex items-center justify-between px-4 py-3 border-b border-gray-100">
        <div className="flex items-center gap-2">
          <Bot size={16} className="text-brand-600" />
          <h2 className="text-sm font-semibold text-gray-900">Form assistant</h2>
        </div>
        <div className="flex items-center gap-1">
          <button onClick={reset} disabled={busy || messages.length === 1} className="btn-ghost text-xs py-1">
            <RotateCcw size={12} /> New conversation
          </button>
          {onToggleFullscreen && (
            <button onClick={onToggleFullscreen} className="btn-ghost p-1" data-guide="chat-fullscreen"
              aria-label={fullscreen ? 'Exit full screen' : 'Full screen'} title={fullscreen ? 'Exit full screen (Esc)' : 'Full screen'}>
              {fullscreen ? <Minimize2 size={15} /> : <Maximize2 size={15} />}
            </button>
          )}
          {onClose && (
            <button onClick={onClose} className="btn-ghost p-1" aria-label="Close the form assistant" title="Close">
              <X size={16} />
            </button>
          )}
        </div>
      </div>
      <BirTokenMeter />

      <div ref={scrollRef} className="flex-1 overflow-y-auto px-4 py-4 space-y-4" role="log" aria-live="polite">
        {messages.map(m => (
          <Message key={m.id} msg={m} onConfirm={confirm} onDecline={decline} onPick={pick} onOpen={onDraftCreated} busy={busy} />
        ))}
        {busy && <TypingIndicator />}
        {showSuggestions && (
          <div className="flex flex-wrap gap-2 pl-9">
            {suggestionsFor(employees?.data?.[0]).map(s => (
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

      {filling?.picker && !busy && <ChatDatePicker key={`${filling.draftId}-${filling.field}`} picker={filling.picker} onSend={send} />}

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
          placeholder={busy ? 'Waiting for the assistant…'
            : filling?.field ? 'Type your answer, or ask what this box means…'
            : filling ? 'Ask about a box, e.g. "where did item 21 come from?"'
            : 'e.g. Generate the August 2026 1601-C'}
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