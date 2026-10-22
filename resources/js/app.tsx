import { createInertiaApp } from '@inertiajs/react';
import { createRoot, hydrateRoot } from 'react-dom/client';
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
    // Pages rendered on the server (the marketing site) are hydrated, not re-rendered.
    if (el.dataset.serverRendered === 'true') {
      hydrateRoot(el, <App {...props} />);
    } else {
      createRoot(el).render(<App {...props} />);
    }
  },
  progress: { color: '#6d4aff' },
});
