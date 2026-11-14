import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

const post = vi.fn();
vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  router: { post: (...a: unknown[]) => post(...a) },
}));
vi.mock('@/layouts/AppShell', () => ({
  default: ({ children }: { children: unknown }) => children,
}));

import Access from './Access';

const request = {
  id: 'g1',
  staff: 'Lindi',
  ticket: 1001,
  reason: 'To see how your coupon is set up.',
  requested_level: 'full',
  level: null,
  minutes: null,
  status: 'requested' as const,
  ends_at: null,
  asked_at: '2026-11-14T10:00:00Z',
};

// Sprint 16.4: the owner decides — view-only and an hour unless they choose otherwise.
describe('Support access', () => {
  it('approves view-only for an hour by default, even when full was asked for', () => {
    window.history.pushState({}, '', '/glow/support/access');
    render(<Access grants={[request]} minutes={[15, 30, 60, 120]} />);
    expect(screen.getByText('Lindi asks for full access')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Approve' }));
    expect(post).toHaveBeenCalledWith(
      '/glow/support/access/g1/approve',
      { level: 'view', minutes: 60 },
      { preserveScroll: true },
    );
  });

  it('offers to end live access at once', () => {
    post.mockClear();
    render(
      <Access
        grants={[
          {
            ...request,
            status: 'active',
            level: 'view',
            minutes: 30,
            ends_at: '2026-11-14T12:30:00Z',
          },
        ]}
        minutes={[15, 30, 60, 120]}
      />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'End access now' }));
    expect(post).toHaveBeenCalledWith('/glow/support/access/g1/end', {}, { preserveScroll: true });
  });
});
