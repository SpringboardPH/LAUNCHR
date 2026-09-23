import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

const docker = Boolean(process.env.DOCKER)

export default defineConfig({
  plugins: [react()],
  server: {
    port: 5173,
    ...(docker ? { host: true } : {}),
    allowedHosts: docker ? true : ['hris.springboard.com.ph'],
    ...(docker
      ? {
          hmr: {
            protocol: process.env.VITE_HMR_PROTOCOL || 'ws',
            clientPort: Number(process.env.VITE_HMR_CLIENT_PORT || 80),
          },
        }
      : {}),
    proxy: {
      '/api': {
        target: 'http://localhost:8000',
        changeOrigin: true,
      },
    },
  },
})
