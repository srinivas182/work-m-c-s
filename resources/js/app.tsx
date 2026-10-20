import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import type { ComponentType } from 'react';
import { resolvePage } from '@/lib/resolve-page';

type PageModule = { default: ComponentType };

const pages = {
  ...import.meta.glob<PageModule>('./pages/**/*.tsx'),
  ...import.meta.glob<PageModule>('../../modules/*/resources/js/pages/**/*.tsx'),
};

void createInertiaApp({
  title: (title) => (title ? `${title} · WURK` : 'WURK'),
  resolve: async (name) => (await resolvePage(name, pages)).default,
  setup({ el, App, props }) {
    createRoot(el).render(<App {...props} />);
  },
  progress: { color: '#6d4aff' },
});
