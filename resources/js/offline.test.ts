import { beforeEach, describe, expect, it } from 'vitest';
import { dismiss, outbox, queueable } from './offline';

describe('offline outbox (24.5)', () => {
  beforeEach(() => localStorage.clear());

  it('queues only the six everyday changes, never money moving online', () => {
    for (const ok of [
      '/glow/sales',
      '/glow/bookings',
      '/glow/bookings/01J/paid',
      '/glow/money/expenses',
      '/glow/money/cash-up',
      '/glow/website/chats/ai/01J/reply',
    ]) {
      expect(queueable(ok)).toBe(true);
    }
    for (const no of [
      '/glow/sales/01J/refund',
      '/glow/shop/orders/01J/refund',
      '/glow/settings/billing/credits',
      '/glow/money/invoices',
      '/glow/bookings/settings',
    ]) {
      expect(queueable(no)).toBe(false);
    }
  });

  it('keeps what is waiting until it is sent or dismissed', () => {
    localStorage.setItem(
      'wurk-outbox',
      JSON.stringify([{ id: 'a', url: '/glow/sales', data: {}, label: 'Sale', at: '' }]),
    );
    expect(outbox()).toHaveLength(1);
    dismiss('a');
    expect(outbox()).toHaveLength(0);
  });
});
