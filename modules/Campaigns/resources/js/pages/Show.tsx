import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Button, Card, CardContent, Field, Input, PageHeader } from '@/design-system';
import AppShell from '@/layouts/AppShell';

interface Props {
  campaign: {
    id: string;
    name: string;
    channel: 'email' | 'sms';
    status: string;
    subject: string | null;
    body: string;
    scheduled_at: string | null;
    sent_at: string | null;
  };
  segment: string;
  estimate: {
    recipients: number;
    credits: number;
    spendable: number | null;
    enough: boolean;
  } | null;
}

/** A campaign: preview, test, then send now or schedule. */
export default function Show({ campaign: c, segment, estimate }: Props) {
  const errors = (usePage().props.errors ?? {}) as Record<string, string | undefined>;
  const schedule = useForm({ when: 'later', at: '' });
  const base = window.location.pathname;
  const open = c.status === 'draft' || c.status === 'scheduled';
  return (
    <AppShell title={c.name}>
      <Head title={c.name} />
      <div className="grid max-w-3xl gap-6">
        <PageHeader
          title={c.name}
          description={`${c.channel === 'sms' ? 'SMS' : 'Email'} to: ${segment}`}
        />
        {errors.send && (
          <p role="alert" className="text-danger text-sm">
            {errors.send}
          </p>
        )}
        <Card>
          <CardContent className="grid gap-2 pt-6 text-sm">
            {c.subject && (
              <p>
                <strong>Subject:</strong> {c.subject}
              </p>
            )}
            <p className="whitespace-pre-wrap">{c.body}</p>
          </CardContent>
        </Card>
        {open && estimate && (
          <Card>
            <CardContent className="grid gap-3 pt-6 text-sm">
              <p className={estimate.enough ? '' : 'text-danger'}>
                Goes to {estimate.recipients} {estimate.recipients === 1 ? 'customer' : 'customers'}
                {c.channel === 'sms'
                  ? ` · about ${estimate.credits} credits${estimate.spendable !== null ? ` · you have ${estimate.spendable}` : ''}`
                  : ' · free'}
                {estimate.enough ? '' : ' — not enough credits'}
              </p>
              {c.status === 'scheduled' && <p>Scheduled for {c.scheduled_at}.</p>}
              <div className="flex flex-wrap gap-2">
                <Button
                  variant="secondary"
                  onClick={() => router.post(`${base}/test`, {}, { preserveScroll: true })}
                >
                  Send me a test
                </Button>
                <Button
                  disabled={!estimate.enough || estimate.recipients === 0}
                  onClick={() => {
                    if (window.confirm(`Send to ${estimate.recipients} customers now?`))
                      router.post(`${base}/send`, { when: 'now' }, { preserveScroll: true });
                  }}
                >
                  Send now
                </Button>
              </div>
              <form
                aria-label="Schedule"
                className="flex flex-wrap items-end gap-2"
                onSubmit={(e) => {
                  e.preventDefault();
                  schedule.post(`${base}/send`, { preserveScroll: true });
                }}
              >
                <Field label="Or send at" error={schedule.errors.at}>
                  <Input
                    type="datetime-local"
                    value={schedule.data.at}
                    onChange={(e) => schedule.setData('at', e.target.value)}
                  />
                </Field>
                <Button type="submit" variant="secondary" loading={schedule.processing}>
                  Schedule
                </Button>
              </form>
            </CardContent>
          </Card>
        )}
        {c.status === 'sent' && (
          <p className="text-sm">Sent {c.sent_at}. Results are on the Campaigns page.</p>
        )}
      </div>
    </AppShell>
  );
}
