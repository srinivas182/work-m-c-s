import { Head, Link } from '@inertiajs/react';
import { Button } from '@/design-system';
import { __ } from '@/i18n';

/**
 * Errors met while moving around WURK (Sprint 18.6a): the same plain words as the error pages a browser gets
 * (resources/views/errors), shown in place instead of a raw error box.
 */
const UNKNOWN = {
  title: 'Something went wrong on our side',
  text: ['Please try again in a few minutes.'],
};

const MESSAGES: Record<number, { title: string; text: string[] }> = {
  403: {
    title: 'You can’t open this page',
    text: [
      'Your account doesn’t have access to it. If you think it should, ask the person who manages your team to change your role.',
    ],
  },
  404: {
    title: 'We can’t find that page',
    text: ['It may have moved, or the link may have a typing mistake in it.'],
  },
  419: {
    title: 'This page timed out',
    text: [
      'For your safety, forms stop working after a while. Refresh the page and try again — nothing has been saved.',
    ],
  },
  500: {
    title: 'Something went wrong on our side',
    text: [
      'It isn’t anything you did. We’ve been told about it automatically and we’re looking at it.',
      'Please try again in a few minutes.',
    ],
  },
  503: {
    title: 'We’ll be back in a few minutes',
    text: ['We’re making WURK better. Nothing you’ve saved is affected.'],
  },
};

export default function Error({ status, message }: { status: number; message?: string | null }) {
  const m = MESSAGES[status] ?? UNKNOWN;
  const text = status === 503 && message ? [message] : m.text;
  return (
    <main className="bg-canvas text-fg grid min-h-dvh place-items-center px-4 py-6">
      <Head title={m.title} />
      <div className="border-border bg-surface w-full max-w-lg rounded-2xl border p-7 shadow-sm">
        <p className="text-fg-muted text-sm font-semibold tracking-wide">
          {__('Error')} {status}
        </p>
        <h1 className="font-display mt-2 text-2xl font-semibold tracking-tight">{m.title}</h1>
        {text.map((t) => (
          <p key={t} className="text-fg-muted mt-3">
            {t}
          </p>
        ))}
        <div className="mt-6 flex flex-wrap gap-3">
          {status === 419 || status === 503 ? (
            <Button onClick={() => window.location.reload()}>{__('Try again')}</Button>
          ) : (
            <Button asChild>
              <Link href="/">{__('Go to the home page')}</Link>
            </Button>
          )}
          <Button variant="secondary" onClick={() => window.history.back()}>
            {__('Go back')}
          </Button>
        </div>
      </div>
    </main>
  );
}
