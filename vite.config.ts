import { fileURLToPath, URL } from 'node:url';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';

export default defineConfig({
  plugins: [
    laravel({
      input: ['resources/css/app.css', 'resources/js/app.tsx', 'resources/css/public-site.css', 'resources/js/site-islands.ts'],
      ssr: 'resources/js/ssr.tsx',
      refresh: ['modules/*/routes/**', 'modules/*/resources/**', 'resources/views/**'],
    }),
    react(),
    tailwindcss(),
  ],
  // The SSR bundle carries its dependencies, so production needs only the Node binary.
  ssr: { noExternal: true },
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
      '@modules': fileURLToPath(new URL('./modules', import.meta.url)),
    },
  },
  server: {
    host: process.env.VITE_HOST ?? 'localhost',
    hmr: { host: 'localhost' },
  },
});
