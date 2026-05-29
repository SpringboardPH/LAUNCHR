export const themeColors = {
  green: {
    '--brand-50': '#f0fdf4',
    '--brand-100': '#dcfce7',
    '--brand-200': '#bbf7d0',
    '--brand-400': '#4ade80',
    '--brand-500': '#22c55e',
    '--brand-600': '#16a34a',
    '--brand-700': '#15803d',
    '--brand-800': '#166534',
    '--brand-900': '#14532d',
  },
  blue: {
    '--brand-50': '#eff6ff',
    '--brand-100': '#dbeafe',
    '--brand-200': '#bfdbfe',
    '--brand-400': '#60a5fa',
    '--brand-500': '#3b82f6',
    '--brand-600': '#2563eb',
    '--brand-700': '#1d4ed8',
    '--brand-800': '#1e40af',
    '--brand-900': '#1e3a8a',
  },
  purple: {
    '--brand-50': '#faf5ff',
    '--brand-100': '#f3e8ff',
    '--brand-200': '#e9d5ff',
    '--brand-400': '#c084fc',
    '--brand-500': '#a855f7',
    '--brand-600': '#9333ea',
    '--brand-700': '#7e22ce',
    '--brand-800': '#6b21a8',
    '--brand-900': '#581c87',
  },
  indigo: {
    '--brand-50': '#eef2ff',
    '--brand-100': '#e0e7ff',
    '--brand-200': '#c7d2fe',
    '--brand-400': '#818cf8',
    '--brand-500': '#6366f1',
    '--brand-600': '#4f46e5',
    '--brand-700': '#4338ca',
    '--brand-800': '#3730a3',
    '--brand-900': '#312e81',
  },
  rose: {
    '--brand-50': '#fff1f2',
    '--brand-100': '#ffe4e6',
    '--brand-200': '#fecdd3',
    '--brand-400': '#fb7185',
    '--brand-500': '#f43f5e',
    '--brand-600': '#e11d48',
    '--brand-700': '#be123c',
    '--brand-800': '#9f1239',
    '--brand-900': '#881337',
  }
}

export const applyTheme = (colorName) => {
  const root = document.documentElement
  const colors = themeColors[colorName] || themeColors.green
  Object.entries(colors).forEach(([variable, value]) => {
    root.style.setProperty(variable, value)
  })
}
