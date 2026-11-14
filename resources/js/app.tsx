import { createInertiaApp } from '@inertiajs/react';
import { createRoot, hydrateRoot } from 'react-dom/client';
import type { ComponentType } from 'react';
import { reportCrash, watchForErrors } from '@/lib/recent-error';
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
    watchForErrors(); // for "Contact support" (16.3) and Watchtower (18.0)
    // A screen that crashes while drawing is reported to Watchtower (18.0).
    const onUncaughtError = (error: unknown) => {
      if (error instanceof Error) reportCrash(error.message, error.stack, 'render');
      console.error(error);
    };
    // Pages rendered on the server (the marketing site) are hydrated, not re-rendered.
    if (el.dataset.serverRendered === 'true') {
      hydrateRoot(el, <App {...props} />, { onUncaughtError });
    } else {
      createRoot(el, { onUncaughtError }).render(<App {...props} />);
    }
  },
  progress: { color: '#6d4aff' },
});
