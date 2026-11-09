import { Head, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Button, Card, CardContent, Field, Input, PageHeader } from '@/design-system';
import AppShell from '@/layouts/AppShell';

interface Draft {
  name: string;
  channel: string;
  segment: string;
  subject: string;
  body: string;
}
interface Estimate {
  recipients: number;
  credits: number;
  spendable: number | null;
  enough: boolean;
}

/** A new campaign: who, what, and what it will cost before anything is sent. */
export default function Compose({
  segments,
  branches,
  draft,
}: {
  segments: Record<string, string>;
  branches: { id: string; name: string }[];
  draft: Draft | null;
}) {
  const base = window.location.pathname.replace(/\/new$/, '');
  const form = useForm({
    name: draft?.name ?? '',
    channel: draft?.channel ?? 'email',
    segment: draft?.segment ?? 'all',
    branch_id: '',
    subject: draft?.subject ?? '',
    body: draft?.body ?? '',
  });
  const [estimate, setEstimate] = useState<Estimate | null>(null);
  useEffect(() => {
    if (form.data.segment === 'branch' && !form.data.branch_id) return;
    const q = new URLSearchParams({
      channel: form.data.channel,
      segment: form.data.segment,
      branch_id: form.data.branch_id,
    });
    fetch(`${base}/estimate?${q}`, { headers: { Accept: 'application/json' } })
      .then((r) => (r.ok ? r.json() : null))
      .then(setEstimate)
      .catch(() => setEstimate(null));
  }, [base, form.data.channel, form.data.segment, form.data.branch_id]);
  const sms = form.data.channel === 'sms';
  return (
    <AppShell title="New campaign">
      <Head title="New campaign" />
      <div className="grid max-w-3xl gap-6">
        <PageHeader
          title="New campaign"
          description="Only customers who agreed to hear from you receive it. {first_name} is replaced with each customer’s first name."
        />
        <Card>
          <CardContent className="pt-6">
            <form
              aria-label="New campaign"
              className="grid gap-3 text-sm"
              onSubmit={(e) => {
                e.preventDefault();
                form.post(`${base}/new`);
              }}
            >
              <Field label="Name (for you)" error={form.errors.name}>
                <Input
                  value={form.data.name}
                  onChange={(e) => form.setData('name', e.target.value)}
                />
              </Field>
              <Field label="Send by">
                <select
                  className="border-border bg-bg h-10 w-full rounded-md border px-3"
                  value={form.data.channel}
                  onChange={(e) => form.setData('channel', e.target.value)}
                >
                  <option value="email">Email (free)</option>
                  <option value="sms">SMS (uses credits)</option>
                </select>
              </Field>
              <Field label="Who" error={form.errors.segment}>
                <select
                  className="border-border bg-bg h-10 w-full rounded-md border px-3"
                  value={form.data.segment}
                  onChange={(e) => form.setData('segment', e.target.value)}
                >
                  {Object.entries(segments)
                    .filter(([k]) => k !== 'branch' || branches.length > 0)
                    .map(([k, label]) => (
                      <option key={k} value={k}>
                        {label}
                      </option>
                    ))}
                </select>
              </Field>
              {form.data.segment === 'branch' && (
                <Field label="Branch" error={form.errors.branch_id}>
                  <select
                    className="border-border bg-bg h-10 w-full rounded-md border px-3"
                    value={form.data.branch_id}
                    onChange={(e) => form.setData('branch_id', e.target.value)}
                  >
                    <option value="">Choose a branch</option>
                    {branches.map((b) => (
                      <option key={b.id} value={b.id}>
                        {b.name}
                      </option>
                    ))}
                  </select>
                </Field>
              )}
              {!sms && (
                <Field label="Subject" error={form.errors.subject}>
                  <Input
                    value={form.data.subject}
                    onChange={(e) => form.setData('subject', e.target.value)}
                  />
                </Field>
              )}
              <Field
                label={sms ? `Message (${form.data.body.length}/300)` : 'Message'}
                error={form.errors.body}
              >
                <textarea
                  className="border-border bg-bg min-h-40 w-full rounded-md border p-3"
                  value={form.data.body}
                  onChange={(e) => form.setData('body', e.target.value)}
                />
              </Field>
              <p
                role="status"
                className={estimate && !estimate.enough ? 'text-danger' : 'text-fg-muted'}
              >
                {estimate === null
                  ? ''
                  : `${estimate.recipients} ${estimate.recipients === 1 ? 'customer' : 'customers'}${sms ? ` · about ${estimate.credits} credits${estimate.spendable !== null ? ` · you have ${estimate.spendable}` : ''}` : ''}${estimate.enough ? '' : ' — not enough credits'}`}
              </p>
              <Button type="submit" className="justify-self-start" loading={form.processing}>
                Save and preview
              </Button>
            </form>
          </CardContent>
        </Card>
      </div>
    </AppShell>
  );
}
