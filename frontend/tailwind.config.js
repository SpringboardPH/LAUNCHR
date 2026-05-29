/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{js,jsx}'],
  theme: {
    extend: {
      colors: {
        brand: {
          50:  'var(--brand-50, #f0fdf4)',
          100: 'var(--brand-100, #dcfce7)',
          200: 'var(--brand-200, #bbf7d0)',
          400: 'var(--brand-400, #4ade80)',
          500: 'var(--brand-500, #22c55e)',
          600: 'var(--brand-600, #16a34a)',
          700: 'var(--brand-700, #15803d)',
          800: 'var(--brand-800, #166534)',
          900: 'var(--brand-900, #14532d)',
        },
      },
      fontFamily: {
        sans: ['Inter', 'system-ui', 'sans-serif'],
      },
    },
  },
  plugins: [],
}
