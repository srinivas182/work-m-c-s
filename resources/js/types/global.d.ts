import type { PageProps as InertiaPageProps } from '@inertiajs/core';

export interface SharedProps {
  app: {
    name: string;
    environment: string;
  };
}

declare module '@inertiajs/core' {
  interface PageProps extends InertiaPageProps, SharedProps {}
}
