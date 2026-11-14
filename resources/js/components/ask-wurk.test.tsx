import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const page = {
  component: 'Campaigns::Index',
  props: { assistant: { url: '/glow/_assistant' } as unknown },
};
vi.mock('@inertiajs/react', () => ({ usePage: () => page }));

import { TooltipProvider } from '@/design-system';
import { AskWurk } from './AskWurk';

const usage = { used: 50, free: 50, free_left: 0, cap: 500, credits_each: 1, balance: 9 };

function reply(body: unknown, ok = true) {
  return Promise.resolve({ ok, json: () => Promise.resolve(body) });
}

function open() {
  render(
    <TooltipProvider>
      <AskWurk />
    </TooltipProvider>,
  );
  fireEvent.click(screen.getByRole('button', { name: 'Ask WURK' }));
}

// Sprint 16.2: "Ask WURK" — answers, proposed changes confirmed before they happen, undo, and credits asked first.
describe('Ask WURK', () => {
  beforeEach(() => {
    page.props.assistant = { url: '/glow/_assistant' };
  });

  it('is not shown when WURK has switched it off', () => {
    page.props.assistant = null;
    render(
      <TooltipProvider>
        <AskWurk />
      </TooltipProvider>,
    );
    expect(screen.queryByRole('button', { name: 'Ask WURK' })).toBeNull();
  });

  it('shows a proposed change and makes it only when confirmed, then offers undo', async () => {
    const calls: string[] = [];
    globalThis.fetch = vi.fn((url: string) => {
      calls.push(url);
      if (url === '/glow/_assistant')
        return reply({ usage, history: [], suggestions: ['Create a 10% coupon for December'] });
      if (url.endsWith('/ask'))
        return reply({
          status: 'answered',
          id: 'q1',
          answer: 'Here’s the coupon.',
          sources: [],
          action: {
            id: 'a1',
            summary: 'Create coupon DEC10 (10% off)',
            changes: ['Code: DEC10'],
            status: 'proposed',
            link: null,
          },
          usage,
        });
      if (url.endsWith('/confirm'))
        return reply({
          status: 'applied',
          message: 'Coupon DEC10 is ready.',
          action: {
            id: 'a1',
            summary: 'Create coupon DEC10 (10% off)',
            changes: ['Code: DEC10'],
            status: 'applied',
            link: '/glow/coupons',
          },
        });
      return reply({}, false);
    }) as never;
    open();
    fireEvent.click(
      await screen.findByRole('button', { name: 'Create a 10% coupon for December' }),
    );
    expect(await screen.findByText('Create coupon DEC10 (10% off)')).toBeTruthy();
    expect(calls.some((c) => c.endsWith('/confirm'))).toBe(false);
    fireEvent.click(screen.getByRole('button', { name: 'Confirm' }));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Undo' })).toBeTruthy());
    expect(screen.getByRole('link', { name: 'Open' }).getAttribute('href')).toBe('/glow/coupons');
    expect(screen.getByText('Each question now uses 1 credit · you have 9')).toBeTruthy();
  });

  it('asks before spending a credit, and only then asks again with the OK', async () => {
    const bodies: unknown[] = [];
    globalThis.fetch = vi.fn((url: string, init?: { body?: string }) => {
      if (url === '/glow/_assistant') return reply({ usage, history: [], suggestions: [] });
      bodies.push(JSON.parse(init?.body ?? '{}'));
      return bodies.length === 1
        ? reply({
            status: 'quote',
            credits: 1,
            balance: 9,
            message: 'This one uses 1 credit (you have 9).',
          })
        : reply({
            status: 'answered',
            id: 'q2',
            answer: 'Sales were R1 200.',
            sources: [],
            action: null,
            usage,
          });
    }) as never;
    open();
    fireEvent.change(screen.getByRole('textbox', { name: 'Your question' }), {
      target: { value: 'Sales this month?' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Ask' }));
    expect(await screen.findByText('This one uses 1 credit (you have 9).')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Ask anyway' }));
    expect(await screen.findByText('Sales were R1 200.')).toBeTruthy();
    expect(bodies).toEqual([
      { question: 'Sales this month?', screen: 'Campaigns::Index', accept_charge: false },
      { question: 'Sales this month?', screen: 'Campaigns::Index', accept_charge: true },
    ]);
  });
});
