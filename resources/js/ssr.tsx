import { createInertiaApp } from '@inertiajs/react';
import createServer from '@inertiajs/react/server';
import type { ComponentType } from 'react';
import { renderToString } from 'react-dom/server';
import { resolvePage } from '@/lib/resolve-page';

type PageModule = { default: ComponentType };

// Only the public marketing site is server-rendered (enabled per host in
// HandleInertiaRequests); the back offices render in the browser.
const pages = import.meta.glob<PageModule>('../../modules/Marketing/resources/js/pages/**/*.tsx');

createServer(
  (page) =>
    createInertiaApp({
      page,
      render: renderToString,
      title: (title) => (title ? `${title} · WURK` : 'WURK'),
      resolve: async (name) => (await resolvePage(name, pages)).default,
      setup: ({ App, props }) => <App {...props} />,
    }),
  // In containers the SSR process listens on all interfaces (SSR_HOST=0.0.0.0).
  { port: Number(process.env.SSR_PORT ?? 13714), host: process.env.SSR_HOST ?? '127.0.0.1' },
);
