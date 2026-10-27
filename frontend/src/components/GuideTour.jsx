import { useEffect, useLayoutEffect, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { X, ChevronLeft, ChevronRight, ArrowUp, ArrowDown, ArrowLeft, ArrowRight } from 'lucide-react'

const GAP = 44 // room for the arrow between the card and what it points at
const CARD_W = 340 // the default; the app-wide tour is wider
const MARGIN = 16

// A target is a data-guide name, or a page's address ("/hr/payroll"): its sidebar link
// (data-guide="nav:/hr/payroll"), or the sidebar group holding it while the group is closed.
const lookup = (target) => (target.startsWith('/')
  ? document.querySelector(`[data-guide="nav:${target}"]`) ?? document.querySelector(`[data-guide-group~="${target}"]`)
  : document.querySelector(`[data-guide="${target}"]`))
// On screen at all? A closed mobile sidebar is moved off the left edge.
const visible = (el) => {
  const r = el?.getBoundingClientRect()
  return !!r && r.width > 0 && r.right > 0 && r.left < window.innerWidth
}

// The first of the step's targets that is on the page, and whether it is a fallback.
const findTarget = (step) => {
  const targets = [].concat(step?.target ?? [])
  for (let i = 0; i < targets.length; i++) {
    const el = lookup(targets[i])
    if (visible(el)) return { el, fallback: i > 0 }
  }
  return { el: null, fallback: targets.length > 0 }
}

// Where the card goes: under the target, above it, or beside it, whichever fits; centred without one.
function place(rect, cardH, cardW) {
  const vw = window.innerWidth, vh = window.innerHeight
  if (!rect) return { card: { top: (vh - cardH) / 2, left: (vw - cardW) / 2 } }
  const cx = Math.min(Math.max(rect.left + rect.width / 2 - cardW / 2, MARGIN), vw - cardW - MARGIN)
  const midY = Math.min(Math.max(rect.top + rect.height / 2, 60), vh - 60)
  const top = Math.min(Math.max(midY - cardH / 2, MARGIN), vh - cardH - MARGIN)
  // A target on the far left (a sidebar link): beside it, so the card doesn't cover the sidebar.
  if (rect.right < vw * 0.3 && rect.right + GAP + cardW < vw - MARGIN) {
    return { card: { top, left: rect.right + GAP }, arrow: { Icon: ArrowLeft, top: midY - 16, left: rect.right + 6 } }
  }
  if (rect.bottom + GAP + cardH < vh - MARGIN) {
    return { card: { top: rect.bottom + GAP, left: cx }, arrow: { Icon: ArrowUp, top: rect.bottom + 6, left: rect.left + rect.width / 2 - 16, bounce: true } }
  }
  if (rect.top - GAP - cardH > MARGIN) {
    return { card: { top: rect.top - GAP - cardH, left: cx }, arrow: { Icon: ArrowDown, top: rect.top - 38, left: rect.left + rect.width / 2 - 16, bounce: true } }
  }
  if (rect.right + GAP + cardW < vw - MARGIN) {
    return { card: { top, left: rect.right + GAP }, arrow: { Icon: ArrowLeft, top: midY - 16, left: rect.right + 6 } }
  }
  if (rect.left - GAP - cardW > MARGIN) {
    return { card: { top, left: rect.left - GAP - cardW }, arrow: { Icon: ArrowRight, top: midY - 16, left: rect.left - 38 } }
  }
  return { card: { top: vh - cardH - MARGIN, left: (vw - cardW) / 2 } }
}

/**
 * A guided tour that points at the page (the BIR Forms guide and the app's first-login tour): each step highlights its target ([data-guide="…"]),
 * dims the rest, and shows a bouncing arrow from the card to it. A step whose target isn't on
 * the page (no form open, say) falls back to the next target listed and shows its `missing` note.
 */
export default function GuideTour({ open, onDismiss, steps, width = CARD_W }) {
  const [index, setIndex] = useState(0)
  const [rect, setRect] = useState(null)
  const [fallback, setFallback] = useState(false)
  const [cardH, setCardH] = useState(260)
  const cardRef = useRef(null)
  const step = steps[index]

  useEffect(() => { if (open) setIndex(0) }, [open])

  // Find the target, scroll it into view, and keep measuring it while the page moves.
  useEffect(() => {
    if (!open) return undefined
    const { el, fallback: fb } = findTarget(step)
    setFallback(fb)
    if (!el) { setRect(null); return undefined }
    el.scrollIntoView({ block: el.offsetHeight > window.innerHeight * 0.6 ? 'start' : 'center', behavior: 'smooth' })
    const measure = () => {
      const r = el.getBoundingClientRect()
      setRect({ top: Math.max(r.top, 0), left: r.left, width: r.width, bottom: Math.min(r.bottom, window.innerHeight), right: r.right,
        height: Math.min(r.bottom, window.innerHeight) - Math.max(r.top, 0) })
    }
    measure()
    const timer = setTimeout(measure, 400) // after the smooth scroll
    window.addEventListener('resize', measure)
    window.addEventListener('scroll', measure, true)
    return () => { clearTimeout(timer); window.removeEventListener('resize', measure); window.removeEventListener('scroll', measure, true) }
  }, [open, index]) // eslint-disable-line react-hooks/exhaustive-deps

  useLayoutEffect(() => { if (cardRef.current) setCardH(cardRef.current.offsetHeight) }, [open, index, fallback])

  useEffect(() => {
    if (!open) return undefined
    const onKey = (e) => {
      if (e.key === 'Escape') onDismiss()
      if (e.key === 'ArrowRight') setIndex(i => Math.min(i + 1, steps.length - 1))
      if (e.key === 'ArrowLeft') setIndex(i => Math.max(i - 1, 0))
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [open, onDismiss, steps.length])

  if (!open || !step) return null
  const last = index === steps.length - 1
  const { card, arrow } = place(rect, cardH, width)
  const Icon = step.icon

  return createPortal(
    <div className="fixed inset-0 z-[60]" role="dialog" aria-modal="true" aria-label={`Guide: ${step.title}`}>
      {rect ? (
        // The highlight: a clear window round the target, everything else dimmed by its shadow.
        <div className="pointer-events-none fixed rounded-xl ring-4 ring-brand-400 transition-all duration-300"
          style={{ top: rect.top - 6, left: rect.left - 6, width: rect.width + 12, height: rect.height + 12, boxShadow: '0 0 0 9999px rgba(15, 23, 42, 0.55)' }} />
      ) : <div className="fixed inset-0 bg-slate-900/55" />}

      {arrow && (
        <div className={`pointer-events-none fixed flex h-8 w-8 items-center justify-center rounded-full bg-brand-600 text-white shadow-lg ${arrow.bounce ? 'animate-bounce' : 'animate-pulse'}`}
          style={{ top: arrow.top, left: arrow.left }} aria-hidden="true">
          <arrow.Icon size={18} strokeWidth={2.5} />
        </div>
      )}

      <div ref={cardRef} className="fixed rounded-2xl bg-white shadow-2xl transition-all duration-300" style={{ top: card.top, left: card.left, width }}>
        <div className="flex items-start gap-3 px-5 pt-4">
          {Icon && <div className="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600"><Icon size={16} /></div>}
          <div className="min-w-0 flex-1">
            <p className="text-[10px] font-semibold uppercase tracking-wider text-gray-400">Step {index + 1} of {steps.length}</p>
            <h2 className="text-base font-bold text-gray-900">{step.title}</h2>
          </div>
          <button onClick={onDismiss} className="btn-ghost p-1" aria-label="Close the guide"><X size={15} /></button>
        </div>
        <div className="px-5 pt-2 pb-3 space-y-2 text-sm text-gray-700">
          <p>{step.body}</p>
          {step.bullets?.length > 0 && (
            <ul className="space-y-1">
              {step.bullets.map((b, i) => (
                <li key={i} className="flex gap-2 text-[13px] text-gray-600"><span className="mt-[7px] h-1.5 w-1.5 shrink-0 rounded-full bg-brand-500" /><span>{b}</span></li>
              ))}
            </ul>
          )}
          {fallback && step.missing && <p className="rounded-lg bg-amber-50 px-2.5 py-1.5 text-xs text-amber-800">{step.missing}</p>}
        </div>
        <div className="flex items-center justify-between gap-2 border-t border-gray-100 px-5 py-3">
          <div className="flex flex-wrap gap-1">
            {steps.map((_, i) => (
              <button key={i} onClick={() => setIndex(i)} aria-label={`Go to step ${i + 1}`}
                className={`h-1.5 rounded-full transition-all ${i === index ? 'w-4 bg-brand-600' : 'w-1.5 bg-gray-200 hover:bg-gray-300'}`} />
            ))}
          </div>
          <div className="flex shrink-0 gap-2">
            {index > 0 && <button onClick={() => setIndex(i => i - 1)} className="btn-secondary px-2.5 py-1.5 text-xs"><ChevronLeft size={13} /> Back</button>}
            <button onClick={() => (last ? onDismiss() : setIndex(i => i + 1))} className="btn-primary px-3 py-1.5 text-xs">
              {last ? 'Done' : <>Next <ChevronRight size={13} /></>}
            </button>
          </div>
        </div>
      </div>
    </div>,
    document.body,
  )
}
