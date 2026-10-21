import type { PageProps as InertiaPageProps } from '@inertiajs/core';

export interface NavigationItem {
  label: string;
  href: string;
  icon: string;
  section: string;
  active: boolean;
}

export interface SharedProps extends Record<string, unknown> {
  app: {
    name: string;
    environment: string;
  };
  navigation: NavigationItem[];
  auth: { user: { name: string; email: string } | null };
  flash: { status: string | null };
}

declare module '@inertiajs/core' {
  interface PageProps extends InertiaPageProps, SharedProps {}
}
