import { useState, useRef, useEffect } from 'react'
import { useMutation } from '@tanstack/react-query'
import { Bot, Send, X, Sparkles } from 'lucide-react'
import { sendAssistantMessage } from '../../api/queries'
import { Spinner } from '../ui'

const GREETING = {
  role: 'assistant',
  content: "Hi! I'm LaunchAssist (a placeholder for now). Ask me about leave, attendance, payslips, or your schedule.",
}

/**
 * LaunchAssist floating chatbot. MVP placeholder — talks to the stub
 * /assistant/chat endpoint. History is ephemeral (component state only).
 */
export default function ChatWidget() {
  const [open, setOpen] = useState(false)
  const [messages, setMessages] = useState([GREETING])
  const [draft, setDraft] = useState('')
  const scrollRef = useRef(null)

  const mutation = useMutation({
    mutationFn: (history) => sendAssistantMessage(history),
    onSuccess: (data) => {
      setMessages((m) => [...m, { role: 'assistant', content: data.reply }])
    },
    onError: () => {
      setMessages((m) => [...m, {
        role: 'assistant',
        content: "Sorry — I couldn't reach the assistant just now. Please try again.",
      }])
    },
  })

  useEffect(() => {
    scrollRef.current?.scrollTo({ top: scrollRef.current.scrollHeight, behavior: 'smooth' })
  }, [messages, open, mutation.isPending])

  const send = (e) => {
    e?.preventDefault()
    const text = draft.trim()
    if (!text || mutation.isPending) return
    const next = [...messages, { role: 'user', content: text }]
    setMessages(next)
    setDraft('')
    // Send only text-role history (drop the local greeting) to the API.
    mutation.mutate(next.filter((m, i) => !(i === 0 && m === GREETING)))
  }

  return (
    <>
      {/* Launcher */}
      {!open && (
        <button
          onClick={() => setOpen(true)}
          className="fixed bottom-5 right-5 z-40 flex items-center gap-2 rounded-full bg-brand-600 px-4 py-3 text-white shadow-lg hover:bg-brand-700 transition-colors"
          aria-label="Open LaunchAssist"
        >
          <Sparkles size={18} />
          <span className="text-sm font-medium">Ask LaunchAssist</span>
        </button>
      )}

      {/* Panel */}
      {open && (
        <div className="fixed bottom-5 right-5 z-40 flex h-[32rem] w-[22rem] max-w-[calc(100vw-2.5rem)] flex-col rounded-xl border border-gray-200 bg-white shadow-2xl">
          {/* Header */}
          <div className="flex items-center gap-2 rounded-t-xl border-b border-gray-100 bg-brand-600 px-4 py-3 text-white">
            <Bot size={18} />
            <div className="min-w-0">
              <p className="text-sm font-semibold leading-tight">LaunchAssist</p>
              <p className="text-[10px] uppercase tracking-wide opacity-80">Placeholder · beta</p>
            </div>
            <button onClick={() => setOpen(false)} className="ml-auto rounded p-1 hover:bg-white/15" aria-label="Close">
              <X size={16} />
            </button>
          </div>

          {/* Messages */}
          <div ref={scrollRef} className="flex-1 space-y-3 overflow-y-auto px-3 py-3">
            {messages.map((m, i) => (
              <div key={i} className={m.role === 'user' ? 'flex justify-end' : 'flex justify-start'}>
                <div
                  className={
                    'max-w-[85%] whitespace-pre-wrap rounded-2xl px-3 py-2 text-sm ' +
                    (m.role === 'user'
                      ? 'bg-brand-600 text-white rounded-br-sm'
                      : 'bg-gray-100 text-gray-800 rounded-bl-sm')
                  }
                >
                  {m.content}
                </div>
              </div>
            ))}
            {mutation.isPending && (
              <div className="flex items-center gap-2 text-xs text-gray-400">
                <Spinner size="sm" /> thinking…
              </div>
            )}
          </div>

          {/* Composer */}
          <form onSubmit={send} className="flex items-center gap-2 border-t border-gray-100 p-2">
            <input
              className="input flex-1"
              placeholder="Type a message…"
              value={draft}
              onChange={(e) => setDraft(e.target.value)}
              disabled={mutation.isPending}
            />
            <button type="submit" className="btn-primary px-3" disabled={!draft.trim() || mutation.isPending} aria-label="Send">
              <Send size={16} />
            </button>
          </form>
        </div>
      )}
    </>
  )
}
