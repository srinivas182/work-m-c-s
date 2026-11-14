import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
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
import { at, type IncidentRow, severityTone } from './Incidents';

interface Props {
  incident: IncidentRow & {
    message: string | null;
    file: string | null;
    line: number | null;
    trace: string | null;
    context: Record<string, unknown> | null;
    first_release: string | null;
    fixed_in: string | null;
    regressed_at: string | null;
    resolved_at: string | null;
  };
  days: { day: string; count: number }[];
  businesses: { id: string; name: string; handle: string }[];
  fix_request: string;
  release: string;
  statuses: Record<string, string>;
  can_manage: boolean;
}

/** One problem (Sprint 18.0): what, where, how often, who — and the fix request to paste to engineering. */
export default function Incident({
  incident: i,
  days,
  businesses,
  fix_request,
  release,
  statuses,
  can_manage,
}: Props) {
  const back = window.location.pathname.replace(/\/[^/]+\/?$/, '');
  const form = useForm<{ action: string; fixed_in: string }>({ action: '', fixed_in: release });
  const [copied, setCopied] = useState(false);
  const act = (action: string) => {
    form.transform((d) => ({ ...d, action }));
    form.put(window.location.pathname, { preserveScroll: true });
  };
  const copy = async () => {
    try {
      await navigator.clipboard.writeText(fix_request);
      setCopied(true);
    } catch {
      setCopied(false);
    }
  };
  const max = Math.max(1, ...days.map((d) => d.count));
  const open = i.status === 'new' || i.status === 'acknowledged';
  return (
    <AppShell title="Watchtower">
      <Head title={`${i.reference} · Watchtower`} />
      <div className="grid max-w-5xl gap-6">
        <p>
          <Link href={back} className="text-sm underline-offset-2 hover:underline">
            ← All problems
          </Link>
        </p>
        <PageHeader eyebrow={i.reference} title={i.title} />
        <div className="flex flex-wrap items-center gap-2 text-sm">
          <Badge tone={severityTone[i.severity]}>{i.severity}</Badge>
          <Badge>{statuses[i.status] ?? i.status}</Badge>
          {i.regressed && (
            <Badge tone="danger">
              Came back after being fixed{i.fixed_in ? ` in ${i.fixed_in}` : ''}
            </Badge>
          )}
          {i.ongoing && <Badge tone="danger">Still failing</Badge>}
          <span className="text-fg-muted">
            {i.occurrences}× since {at(i.first_seen_at)} · {i.last_hour} in the last hour · last{' '}
            {at(i.last_seen_at)} · releases {i.first_release ?? '—'} → {i.last_release ?? '—'}
          </span>
        </div>

        {can_manage && (
          <Card>
            <CardContent className="flex flex-wrap items-end gap-2 pt-5">
              {open && i.status !== 'acknowledged' && (
                <Button
                  variant="secondary"
                  onClick={() => act('acknowledge')}
                  disabled={form.processing}
                >
                  Acknowledge
                </Button>
              )}
              {i.status !== 'fixed' && (
                <>
                  <Field label="Fixed in release" error={form.errors.fixed_in}>
                    <Input
                      className="w-32"
                      value={form.data.fixed_in}
                      onChange={(e) => form.setData('fixed_in', e.target.value)}
                    />
                  </Field>
                  <Button onClick={() => act('fixed')} disabled={form.processing}>
                    Mark fixed
                  </Button>
                </>
              )}
              {i.status !== 'ignored' && (
                <Button variant="ghost" onClick={() => act('ignore')} disabled={form.processing}>
                  Ignore
                </Button>
              )}
              {!open && (
                <Button
                  variant="secondary"
                  onClick={() => act('reopen')}
                  disabled={form.processing}
                >
                  Reopen
                </Button>
              )}
            </CardContent>
          </Card>
        )}

        <Card>
          <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-2">
            <CardTitle>Fix request</CardTitle>
            <Button size="sm" onClick={() => void copy()}>
              {copied ? 'Copied' : 'Copy fix request'}
            </Button>
          </CardHeader>
          <CardContent className="grid gap-2">
            <p className="text-fg-muted text-sm">
              Paste this into your engineering chat to get it fixed. Personal data has been removed.
            </p>
            <pre
              aria-label="Fix request"
              className="bg-neutral-2 max-h-96 overflow-auto rounded-md p-3 text-xs whitespace-pre-wrap"
            >
              {fix_request}
            </pre>
          </CardContent>
        </Card>

        {days.length > 0 && (
          <Card>
            <CardHeader>
              <CardTitle>Last 14 days</CardTitle>
            </CardHeader>
            <CardContent>
              <ul aria-label="Occurrences per day" className="grid gap-1 text-sm">
                {days.map((d) => (
                  <li key={d.day} className="grid grid-cols-[7rem_1fr_3rem] items-center gap-2">
                    <span>{d.day}</span>
                    <span
                      className="bg-brand-9 h-2 rounded"
                      style={{ width: `${Math.max(4, (d.count / max) * 100)}%` }}
                    />
                    <span className="text-right tabular-nums">{d.count}</span>
                  </li>
                ))}
              </ul>
            </CardContent>
          </Card>
        )}

        {businesses.length > 0 && (
          <Card>
            <CardHeader>
              <CardTitle>
                Businesses affected ({i.businesses}
                {i.businesses > businesses.length ? `, first ${businesses.length} shown` : ''})
              </CardTitle>
            </CardHeader>
            <CardContent>
              <ul className="grid gap-1 text-sm sm:grid-cols-2">
                {businesses.map((b) => (
                  <li key={b.id}>
                    {b.name} <span className="text-fg-muted">({b.handle})</span>
                  </li>
                ))}
              </ul>
            </CardContent>
          </Card>
        )}

        {i.trace && (
          <Card>
            <CardHeader>
              <CardTitle>Trace</CardTitle>
            </CardHeader>
            <CardContent>
              <pre className="bg-neutral-2 max-h-96 overflow-auto rounded-md p-3 text-xs">
                {i.trace}
              </pre>
            </CardContent>
          </Card>
        )}
      </div>
    </AppShell>
  );
}
