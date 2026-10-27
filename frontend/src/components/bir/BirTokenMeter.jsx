import { useQuery } from '@tanstack/react-query'
import { Gauge } from 'lucide-react'
import clsx from 'clsx'
import { format } from 'date-fns'
import { birKeys, getBirTokenUsage } from '../../api/queries'

const PERIOD = { day: 'today', week: 'this week', month: 'this month' }
const n = (v) => Number(v ?? 0).toLocaleString('en-PH')

/**
 * The token line under the chat header: what is left of the budget until it resets ("38,250 tokens
 * left · resets Mon, Nov 2"), or what was used when no budget is set. Amber under a fifth
 * left, red once it is used up. The budget is set in System Settings → BIR Forms.
 */
export default function BirTokenMeter() {
  const { data } = useQuery({ queryKey: birKeys.tokenUsage, queryFn: getBirTokenUsage, staleTime: 30_000, refetchInterval: 5 * 60_000 })
  if (!data) return null

  // The reset day as the server counts it (Philippine time), whatever the browser's time zone.
  const day = /^(\d{4})-(\d{2})-(\d{2})/.exec(data.resets_at ?? '')
  const resets = day ? format(new Date(Number(day[1]), Number(day[2]) - 1, Number(day[3])), 'EEE, MMM d') : null
  const budget = data.budget ?? null
  const left = data.left ?? null
  const tone = budget === null ? 'text-gray-500 bg-gray-50'
    : left === 0 ? 'text-red-700 bg-red-50'
      : left < budget / 5 ? 'text-amber-700 bg-amber-50' : 'text-gray-600 bg-gray-50'
  const text = budget === null
    ? `${n(data.used)} tokens used ${PERIOD[data.period] ?? ''}`
    : left === 0 ? 'Token budget used up' : `${n(left)} tokens left`
  const title = [
    budget !== null ? `${n(data.used)} of ${n(budget)} tokens used ${PERIOD[data.period] ?? ''}` : 'No token budget set (System Settings → BIR Forms)',
    `${n(data.requests)} AI ${data.requests === 1 ? 'reply' : 'replies'}`,
    resets && `Resets ${resets}`,
  ].filter(Boolean).join(' · ')

  return (
    <div className={clsx('flex items-center gap-1.5 border-b border-gray-100 px-4 py-1 text-[11px] font-medium whitespace-nowrap', tone)} title={title}
      aria-label={title} data-guide="chat-tokens">
      <Gauge size={11} className="shrink-0" />
      <span>{text}</span>{resets && <span className="font-normal opacity-80">· resets {resets}</span>}
    </div>
  )
}
