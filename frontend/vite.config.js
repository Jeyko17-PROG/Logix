import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import { VitePWA } from 'vite-plugin-pwa'

// https://vite.dev/config/
export default defineConfig({
  server: {
    port: 5173,
    host: true, // expone el servidor en la red local (LAN) para abrir desde el celular vía IP
    proxy: {
      // Redirige las llamadas /api al backend Laravel
      '/api': {
        target: 'http://localhost:8000',
        changeOrigin: true,
      },
      // Redirige los archivos generados (PDFs de facturas y documentos) al backend
      '/storage': {
        target: 'http://localhost:8000',
        changeOrigin: true,
      },
    },
  },
  plugins: [
    react(),
    tailwindcss(),
    VitePWA({
      registerType: 'autoUpdate',
      includeAssets: ['favicon.svg', 'apple-touch-icon.png', 'logo.svg'],
      manifest: {
        name: 'Logix · Plataforma de gestión',
        short_name: 'Logix',
        description: 'Sistema de Gestión de Clientes, Inventario, Facturación y Reservas.',
        lang: 'es',
        theme_color: '#1d4ed8',
        background_color: '#0f172a',
        display: 'standalone',
        orientation: 'portrait',
        scope: '/',
        start_url: '/',
        icons: [
          { src: 'pwa-192x192.png', sizes: '192x192', type: 'image/png' },
          { src: 'pwa-512x512.png', sizes: '512x512', type: 'image/png' },
          { src: 'pwa-maskable-512x512.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' },
        ],
      },
    }),
  ],
})
