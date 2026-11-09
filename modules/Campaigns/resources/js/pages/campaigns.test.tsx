import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  Link: ({ children, href }: { children: unknown; href: string }) => (
    <a href={href}>{children as never}</a>
  ),
  router: { post: vi.fn(), put: vi.fn(), get: vi.fn() },
  usePage: () => ({ props: { errors: {} } }),
  useForm: (d: Record<string, unknown>) => ({
    data: d,
    setData: vi.fn(),
    post: vi.fn(),
    processing: false,
    errors: {},
  }),
}));
vi.mock('@/layouts/AppShell', () => ({
  default: ({ children }: { children: unknown }) => children,
}));

import Compose from './Compose';
import Index from './Index';
import Show from './Show';

// The business back office's Campaigns pages (Sprint 15.3a) render — the browser-test business has no package with campaigns.
describe('Campaigns pages', () => {
  it('lists campaigns with their results and the ready-made ones', () => {
    render(
      <Index
        campaigns={[
          {
            id: '1',
            name: 'Win back',
            channel: 'email',
            status: 'sent',
            scheduled_at: null,
            sent_at: '2026-11-09',
            sent: 12,
            failed: 1,
            opened: 5,
            clicked: 2,
            redeemed: 3,
            sales_cents: 45000,
          },
        ]}
        readyMade={{
          'win-back': {
            name: 'We miss you',
            channel: 'email',
            about: 'Customers you haven’t seen in 60 days or more.',
          },
        }}
      />,
    );
    expect(screen.getByRole('heading', { name: 'Campaigns' })).toBeTruthy();
    expect(
      screen.getByText(/12 sent · 1 failed · 5 opened · 2 clicked\s*· 3 redeemed · R450 in sales/),
    ).toBeTruthy();
    expect(screen.getByText('We miss you')).toBeTruthy();
  });

  it('composes from a ready-made draft', () => {
    globalThis.fetch = vi.fn(() =>
      Promise.resolve({
        ok: true,
        json: () => Promise.resolve({ recipients: 3, credits: 0, spendable: 0, enough: true }),
      }),
    ) as never;
    render(
      <Compose
        segments={{ all: 'Everyone', inactive: 'Not seen in 60+ days' }}
        branches={[]}
        draft={{
          name: 'We miss you',
          channel: 'email',
          segment: 'inactive',
          subject: 'Hi',
          body: 'Hi {first_name}',
        }}
      />,
    );
    expect(screen.getByRole('form', { name: 'New campaign' })).toBeTruthy();
    expect((screen.getByLabelText('Subject') as HTMLInputElement).value).toBe('Hi');
  });

  it('shows a draft with its estimate, test and send', () => {
    render(
      <Show
        campaign={{
          id: '1',
          name: 'Thank you',
          channel: 'sms',
          status: 'draft',
          subject: null,
          body: 'Hi {first_name}',
          scheduled_at: null,
          sent_at: null,
        }}
        segment="Everyone"
        estimate={{ recipients: 3, credits: 3, spendable: 1, enough: false }}
      />,
    );
    expect(screen.getByText(/not enough credits/)).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Send now' }).hasAttribute('disabled')).toBe(true);
    expect(screen.getByRole('button', { name: 'Send me a test' })).toBeTruthy();
  });
});
