import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react(), tailwindcss()],
  server: {
    // Set by compose.yaml: file change events do not reach a Linux container
    // from a Windows folder, so the dockerised dev server polls instead.
    watch: process.env.VITE_USE_POLLING === 'true' ? { usePolling: true, interval: 300 } : undefined,
    proxy: {
      '/api': {
        // 8090, not Laravel's default 8000: port 8000 on the dev machine is held
        // by a Windows svchost service, so proxying there returns 502 Bad
        // Gateway. The API container publishes 8090; mobile's API_PORT matches.
        // Inside Docker, compose.yaml points this at the api service instead.
        target: process.env.VITE_API_TARGET || 'http://127.0.0.1:8090',
        changeOrigin: true,
      },
    },
  },
})
