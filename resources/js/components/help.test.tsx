import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
  usePage: () => ({ component: 'Payments::Settings', props: {} }),
}));

import { TooltipProvider } from '@/design-system';
import { HelpPanel } from './HelpPanel';
import { HelpTip } from './HelpTip';

// Sprint 16.1b: "?" help on every screen, and "What's this?" on key fields.
describe('In-app help', () => {
  it('asks for this screen’s help when "?" is pressed and shows it', async () => {
    const fetchMock = vi.fn(() =>
      Promise.resolve({
        ok: true,
        json: () =>
          Promise.resolve({
            articles: [
              {
                slug: 'cards',
                title: 'Turn on card payments',
                summary: null,
                html: '<p>Go to Settings.</p>',
                video: null,
                poster: null,
                url: 'https://help/a/cards',
              },
            ],
            home: 'https://help/',
          }),
      }),
    );
    globalThis.fetch = fetchMock as never;
    render(
      <TooltipProvider>
        <HelpPanel />
      </TooltipProvider>,
    ); // as AppShell provides
    fireEvent.click(screen.getByRole('button', { name: 'Help for this page' }));
    await waitFor(() => expect(screen.getByText('Turn on card payments')).toBeTruthy());
    expect(String((fetchMock.mock.calls[0] as unknown[])[0])).toBe(
      '/_help?screen=Payments%3A%3ASettings',
    );
  });

  it('opens and closes a field’s explanation, telling screen readers', () => {
    render(<HelpTip>We email you each time.</HelpTip>);
    const button = screen.getByRole('button', { name: 'What’s this?' });
    expect(button.getAttribute('aria-expanded')).toBe('false');
    fireEvent.click(button);
    expect(button.getAttribute('aria-expanded')).toBe('true');
    expect(screen.getByRole('note').hidden).toBe(false);
  });
});
