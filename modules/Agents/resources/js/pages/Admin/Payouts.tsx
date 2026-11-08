import { Head, router, useForm, usePage } from '@inertiajs/react';
import {
  Badge,
  Button,
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  Field,
  Input,
  PageHeader,
} from '@/design-system';
import AppShell from '@/layouts/AppShell';
import { rand } from '@/lib/money';

interface Row {
  agent_id: string;
  name: string;
  code: string;
  cents: number;
  bank: string | null;
  reason?: string;
}
interface Batch {
  id: string;
  reference: string;
  status: 'ready' | 'paid' | 'cancelled';
  count: number;
  total_cents: number;
  created: string;
}
interface Props {
  minimum: number;
  preview: { included: Row[]; held: Row[]; skipped: Row[] };
  batches: Batch[];
}

/** Admin → Agent payouts: preview, make the EFT batch, download it, cancel it. */
export default function Payouts({ minimum, preview, batches }: Props) {
  const errors = (usePage().props.errors ?? {}) as Record<string, string | undefined>;
  const min = useForm({ minimum: String(minimum / 100) });
  const total = preview.included.reduce((a, r) => a + r.cents, 0);
  return (
    <AppShell title="Agent payouts">
      <Head title="Agent payouts" />
      <div className="grid max-w-4xl gap-6">
        <PageHeader
          title="Agent payouts"
          description="Pay agents’ available commission by EFT: check the preview, make the batch, pay it through your bank, then mark it paid."
        />
        {errors.batch && (
          <p role="alert" className="text-danger text-sm">
            {errors.batch}
          </p>
        )}
        <form
          aria-label="Minimum payout"
          className="flex flex-wrap items-end gap-3"
          onSubmit={(e) => {
            e.preventDefault();
            min.put('/agents/payouts/minimum', { preserveScroll: true });
          }}
        >
          <Field label="Minimum payout (R)" error={min.errors.minimum}>
            <Input
              type="number"
              inputMode="decimal"
              min="0"
              step="0.01"
              value={min.data.minimum}
              onChange={(e) => min.setData('minimum', e.target.value)}
            />
          </Field>
          <Button type="submit" variant="secondary" loading={min.processing}>
            Save
          </Button>
        </form>

        <Card>
          <CardHeader>
            <CardTitle>
              Next batch: {preview.included.length}{' '}
              {preview.included.length === 1 ? 'agent' : 'agents'}, {rand(total)}
            </CardTitle>
          </CardHeader>
          <CardContent className="grid gap-4 text-sm">
            <Group
              title="Included"
              rows={preview.included}
              empty="Nobody is due a payout right now."
            />
            <Group title="Held — check with the agent first" rows={preview.held} />
            <Group title="Not included" rows={preview.skipped} />
            {preview.included.length > 0 && (
              <Button
                className="justify-self-start"
                onClick={() => router.post('/agents/payouts', {}, { preserveScroll: true })}
              >
                Make the batch
              </Button>
            )}
          </CardContent>
        </Card>

        {batches.length > 0 && (
          <Card>
            <CardHeader>
              <CardTitle>Batches</CardTitle>
            </CardHeader>
            <CardContent>
              <ul aria-label="Batches" className="grid gap-3 text-sm">
                {batches.map((b) => (
                  <li key={b.id} className="flex flex-wrap items-center gap-3">
                    <span className="font-mono">{b.reference}</span>
                    <span>
                      {b.count} · {rand(b.total_cents)} · {b.created}
                    </span>
                    <Badge
                      tone={
                        b.status === 'paid'
                          ? 'success'
                          : b.status === 'cancelled'
                            ? 'neutral'
                            : 'warning'
                      }
                    >
                      {b.status === 'ready' ? 'To pay' : b.status === 'paid' ? 'Paid' : 'Cancelled'}
                    </Badge>
                    {b.status === 'ready' && (
                      <>
                        <a className="underline" href={`/agents/payouts/${b.id}/csv`}>
                          Download CSV
                        </a>
                        <Button
                          size="sm"
                          variant="ghost"
                          onClick={() => {
                            if (
                              window.confirm(
                                `Cancel ${b.reference}? The amounts go back to the agents’ balances.`,
                              )
                            ) {
                              router.post(
                                `/agents/payouts/${b.id}/cancel`,
                                {},
                                { preserveScroll: true },
                              );
                            }
                          }}
                        >
                          Cancel
                        </Button>
                      </>
                    )}
                  </li>
                ))}
              </ul>
            </CardContent>
          </Card>
        )}
      </div>
    </AppShell>
  );
}

function Group({ title, rows, empty }: { title: string; rows: Row[]; empty?: string }) {
  if (rows.length === 0) {
    return empty ? <p className="text-fg-muted">{empty}</p> : null;
  }
  return (
    <section aria-label={title} className="grid gap-1">
      <h2 className="font-medium">{title}</h2>
      <ul className="grid gap-1">
        {rows.map((r) => (
          <li key={r.agent_id}>
            {r.name} ({r.code}) — {rand(r.cents)}
            {r.bank ? ` · ${r.bank}` : ''}
            {r.reason ? ` · ${r.reason}` : ''}
          </li>
        ))}
      </ul>
    </section>
  );
}
