import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import {
  Button,
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
  Field,
  Input,
  PageHeader,
  Textarea,
} from '@/design-system';
import AppShell from '@/layouts/AppShell';
import { forgetRecentError, recentError } from '@/lib/recent-error';

interface Props {
  target: string;
  from: string;
}

interface Suggestion {
  answer: string | null;
  sources: { title: string; url: string }[];
}

function csrf(): string {
  return decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/)?.[1] ?? '');
}

/**
 * Contact support (Sprint 16.3): help first — matching articles and an answer from them — then the ticket, with the
 * page and the last error included (shown, and removable).
 */
export default function New({ target, from }: Props) {
  const base = window.location.pathname.replace(/\/new\/?$/, '');
  const [error] = useState(() => recentError());
  const form = useForm<{
    subject: string;
    body: string;
    page: string;
    last_error: string;
    screenshots: File[];
  }>({
    subject: '',
    body: '',
    page: from || error?.page || '',
    last_error: error ? error.message : '',
    screenshots: [],
  });
  const [step, setStep] = useState<'describe' | 'suggest' | 'send'>('describe');
  const [suggestion, setSuggestion] = useState<Suggestion | null>(null);
  const [checking, setChecking] = useState(false);

  const check = async () => {
    setChecking(true);
    const r = await fetch(`${base}/suggest`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-XSRF-TOKEN': csrf(),
      },
      body: JSON.stringify({ question: `${form.data.subject}\n\n${form.data.body}` }),
    }).catch(() => null);
    const s = (r?.ok ? await r.json() : { answer: null, sources: [] }) as Suggestion;
    setChecking(false);
    setSuggestion(s);
    setStep(s.answer || s.sources.length > 0 ? 'suggest' : 'send');
  };

  return (
    <AppShell title="Contact support">
      <Head title="Contact support" />
      <div className="grid max-w-3xl gap-6">
        <PageHeader title="Contact support" description={`We aim to reply within ${target}.`} />
        <form
          className="grid gap-6"
          onSubmit={(e) => {
            e.preventDefault();
            if (step === 'describe') {
              void check();
              return;
            }
            form.post(base, { forceFormData: true, onSuccess: () => forgetRecentError() });
          }}
        >
          <Card>
            <CardContent className="grid gap-4 pt-6">
              <Field label="What do you need help with?" error={form.errors.subject}>
                <Input
                  maxLength={150}
                  value={form.data.subject}
                  onChange={(e) => form.setData('subject', e.target.value)}
                />
              </Field>
              <Field
                label="Tell us more"
                hint="What were you trying to do, and what happened?"
                error={form.errors.body}
              >
                <Textarea
                  rows={6}
                  maxLength={5000}
                  value={form.data.body}
                  onChange={(e) => form.setData('body', e.target.value)}
                />
              </Field>
            </CardContent>
          </Card>

          {step === 'suggest' && suggestion && (
            <Card aria-label="This might help">
              <CardHeader>
                <CardTitle>This might help</CardTitle>
                <CardDescription>
                  From our help centre — if it answers your question, you’re done.
                </CardDescription>
              </CardHeader>
              <CardContent className="grid gap-3 text-sm">
                {suggestion.answer && <p className="whitespace-pre-line">{suggestion.answer}</p>}
                {suggestion.sources.length > 0 && (
                  <ul aria-label="Help articles" className="grid gap-1">
                    {suggestion.sources.map((s) => (
                      <li key={s.url}>
                        <a className="underline" href={s.url} target="_blank" rel="noreferrer">
                          {s.title}
                        </a>
                      </li>
                    ))}
                  </ul>
                )}
                <div className="flex flex-wrap gap-2">
                  <Button type="button" variant="secondary" onClick={() => window.history.back()}>
                    That answered it
                  </Button>
                  <Button type="button" onClick={() => setStep('send')}>
                    I still need help
                  </Button>
                </div>
              </CardContent>
            </Card>
          )}

          {step === 'send' && (
            <Card>
              <CardHeader>
                <CardTitle>Send to WURK</CardTitle>
                <CardDescription>
                  We include these to save you explaining. Remove anything you’d rather not send.
                </CardDescription>
              </CardHeader>
              <CardContent className="grid gap-4">
                <Field label="Page you were on" error={form.errors.page}>
                  <Input
                    value={form.data.page}
                    onChange={(e) => form.setData('page', e.target.value)}
                  />
                </Field>
                {form.data.last_error && (
                  <div className="grid gap-1 text-sm">
                    <span className="font-medium">What went wrong last</span>
                    <p className="text-fg-muted">{form.data.last_error}</p>
                    <Button
                      type="button"
                      size="sm"
                      variant="ghost"
                      className="justify-self-start"
                      onClick={() => form.setData('last_error', '')}
                    >
                      Don’t include this
                    </Button>
                  </div>
                )}
                <p className="text-fg-muted text-sm">
                  Your browser’s name and version are included too.
                </p>
                <Field
                  label="Screenshots (optional)"
                  hint="Up to 3 — PNG, JPG or WebP, 5 MB each."
                  error={
                    form.errors.screenshots ??
                    Object.entries(form.errors).find(([k]) => k.startsWith('screenshots.'))?.[1]
                  }
                >
                  <Input
                    type="file"
                    multiple
                    accept="image/png,image/jpeg,image/webp"
                    onChange={(e) =>
                      form.setData('screenshots', Array.from(e.target.files ?? []).slice(0, 3))
                    }
                  />
                </Field>
              </CardContent>
            </Card>
          )}

          {step !== 'suggest' && (
            <Button
              type="submit"
              className="justify-self-start"
              loading={checking || form.processing}
            >
              {step === 'describe' ? 'Next' : 'Send to WURK'}
            </Button>
          )}
        </form>
      </div>
    </AppShell>
  );
}
