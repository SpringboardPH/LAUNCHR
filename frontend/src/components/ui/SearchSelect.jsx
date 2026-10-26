import { useEffect, useId, useLayoutEffect, useMemo, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { ChevronDown } from 'lucide-react'
import clsx from 'clsx'

export default function SearchSelect({
  options = [],
  value = '',
  onChange,
  getOptionValue = (option) => option,
  getLabel = (option) => String(option),
  getSearchText,
  placeholder = 'Select…',
  disabled = false,
}) {
  const rootRef = useRef(null)
  const panelRef = useRef(null)
  const inputRef = useRef(null)
  const listId = useId()
  const [open, setOpen] = useState(false)
  const [query, setQuery] = useState('')
  const [panelStyle, setPanelStyle] = useState(null)

  const searchOf = getSearchText ?? getLabel
  const selected = options.find((option) => String(getOptionValue(option)) === String(value))

  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase()
    if (!q) return options
    return options.filter((option) => searchOf(option).toLowerCase().includes(q))
  }, [options, query, searchOf])

  function closePanel() {
    setOpen(false)
    setQuery('')
  }

  function choose(option) {
    onChange(String(getOptionValue(option)))
    closePanel()
  }

  useLayoutEffect(() => {
    if (!open || !rootRef.current) return

    function place() {
      const rect = rootRef.current.getBoundingClientRect()
      const gap = 4
      const maxPanel = 240
      const spaceBelow = window.innerHeight - rect.bottom - gap
      const spaceAbove = rect.top - gap
      const openUp = spaceBelow < 160 && spaceAbove > spaceBelow
      const maxHeight = Math.min(maxPanel, Math.max(120, openUp ? spaceAbove : spaceBelow))
      setPanelStyle({
        position: 'fixed',
        left: rect.left,
        width: rect.width,
        maxHeight,
        zIndex: 80,
        ...(openUp
          ? { bottom: window.innerHeight - rect.top + gap }
          : { top: rect.bottom + gap }),
      })
    }

    place()
    window.addEventListener('resize', place)
    window.addEventListener('scroll', place, true)
    return () => {
      window.removeEventListener('resize', place)
      window.removeEventListener('scroll', place, true)
    }
  }, [open])

  useEffect(() => {
    if (!open) return
    function onPointerDown(event) {
      const target = event.target
      if (rootRef.current?.contains(target) || panelRef.current?.contains(target)) return
      closePanel()
    }
    function onKeyDown(event) {
      if (event.key === 'Escape') closePanel()
    }
    document.addEventListener('mousedown', onPointerDown)
    document.addEventListener('keydown', onKeyDown)
    return () => {
      document.removeEventListener('mousedown', onPointerDown)
      document.removeEventListener('keydown', onKeyDown)
    }
  }, [open])

  useEffect(() => {
    if (open) inputRef.current?.focus()
  }, [open])

  const panel = open && !disabled && panelStyle && createPortal(
    <div
      ref={panelRef}
      id={listId}
      style={panelStyle}
      className="rounded-lg border border-gray-100 bg-white shadow-lg overflow-hidden flex flex-col"
    >
      <div className="p-2 border-b border-gray-100 shrink-0">
        <input
          ref={inputRef}
          type="text"
          className="input text-sm w-full"
          value={query}
          onChange={(event) => setQuery(event.target.value)}
          placeholder="Search…"
        />
      </div>
      <div className="overflow-y-auto p-1 min-h-0 flex-1">
        {filtered.length === 0 ? (
          <p className="text-xs text-gray-400 px-2 py-3">No matches.</p>
        ) : (
          filtered.map((option) => {
            const optionValue = String(getOptionValue(option))
            const isSelected = optionValue === String(value)
            return (
              <button
                key={optionValue}
                type="button"
                onClick={() => choose(option)}
                className={clsx(
                  'w-full text-left text-sm text-gray-700 px-2 py-1.5 rounded hover:bg-gray-50 truncate',
                  isSelected && 'bg-gray-50 font-medium',
                )}
              >
                {getLabel(option)}
              </button>
            )
          })
        )}
      </div>
    </div>,
    document.body,
  )

  return (
    <div ref={rootRef} className="relative">
      <button
        type="button"
        disabled={disabled}
        aria-expanded={open}
        aria-controls={listId}
        onClick={() => {
          if (disabled) return
          if (open) closePanel()
          else setOpen(true)
        }}
        className={clsx(
          'input text-sm w-full flex items-center gap-2 text-left h-10 py-1.5',
          disabled && 'opacity-60 cursor-not-allowed',
        )}
      >
        <span className={clsx('flex-1 min-w-0 truncate', !selected && 'text-gray-400')}>
          {selected ? getLabel(selected) : placeholder}
        </span>
        <ChevronDown size={14} className={clsx('shrink-0 text-gray-400 transition-transform', open && 'rotate-180')} />
      </button>
      {panel}
    </div>
  )
}
