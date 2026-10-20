import { fileURLToPath, URL } from 'node:url';
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vitest/config';

// Separate from vite.config.ts: the Laravel Vite plugin refuses to start a dev
// server in CI, and unit tests do not need it.
export default defineConfig({
  plugins: [react()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
      '@modules': fileURLToPath(new URL('./modules', import.meta.url)),
    },
  },
  test: {
    environment: 'jsdom',
    include: ['resources/js/**/*.test.{ts,tsx}', 'modules/*/resources/js/**/*.test.{ts,tsx}'],
  },
});
