import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { useState } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const posted: { url: string; data: Record<string, unknown> }[] = [];

vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  Link: ({ children, href }: { children: unknown; href: string }) => (
    <a href={href}>{children as never}</a>
  ),
  router: { post: vi.fn(), on: vi.fn() },
  usePage: () => ({ props: { errors: {} } }),
  useForm: <T extends Record<string, unknown>>(initial: T) => {
    const [data, set] = useState(initial);
    return {
      data,
      setData: (k: keyof T, v: unknown) => set((d) => ({ ...d, [k]: v })),
      post: (url: string) => posted.push({ url, data }),
      transform: vi.fn(),
      reset: vi.fn(),
      processing: false,
      errors: {},
    };
  },
}));
vi.mock('@/layouts/AppShell', () => ({
  default: ({ children }: { children: unknown }) => children,
}));

import { recentError } from '@/lib/recent-error';
import New from './New';

// Sprint 16.3: help first, then a ticket with what went wrong (shown, removable).
describe('Contact support', () => {
  beforeEach(() => {
    posted.length = 0;
    window.history.pushState({}, '', '/glow/support/new');
    sessionStorage.setItem(
      'wurk.recentError',
      JSON.stringify({
        message: 'The server answered 500',
        page: '/glow/payments',
        at: new Date().toISOString(),
      }),
    );
  });

  it('shows matching help first, then sends with the page and last error unless removed', async () => {
    const fetchMock = vi.fn(() =>
      Promise.resolve({
        ok: true,
        json: () =>
          Promise.resolve({
            answer: 'Connect Yoco in Settings.',
            sources: [{ title: 'Card payments', url: 'https://help/a/cards' }],
          }),
      }),
    );
    globalThis.fetch = fetchMock as never;
    render(<New target="8 business hours" from="" />);
    fireEvent.change(screen.getByLabelText('What do you need help with?'), {
      target: { value: 'Card payments' },
    });
    fireEvent.change(screen.getByLabelText('Tell us more'), {
      target: { value: 'Customers see an error.' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Next' }));
    expect(await screen.findByText('Connect Yoco in Settings.')).toBeTruthy();
    expect(String((fetchMock.mock.calls[0] as unknown[])[0])).toBe('/glow/support/suggest');
    expect(posted).toHaveLength(0);

    fireEvent.click(screen.getByRole('button', { name: 'I still need help' }));
    expect(screen.getByText('The server answered 500')).toBeTruthy();
    expect((screen.getByLabelText('Page you were on') as HTMLInputElement).value).toBe(
      '/glow/payments',
    );
    fireEvent.click(screen.getByRole('button', { name: 'Don’t include this' }));
    fireEvent.click(screen.getByRole('button', { name: 'Send to WURK' }));
    await waitFor(() => expect(posted).toHaveLength(1));
    expect(posted[0]).toMatchObject({
      url: '/glow/support',
      data: { subject: 'Card payments', last_error: '', page: '/glow/payments' },
    });
  });

  it('forgets errors older than an hour', () => {
    sessionStorage.setItem(
      'wurk.recentError',
      JSON.stringify({ message: 'old', page: '/', at: '2020-01-01T00:00:00Z' }),
    );
    expect(recentError()).toBeNull();
  });
});
