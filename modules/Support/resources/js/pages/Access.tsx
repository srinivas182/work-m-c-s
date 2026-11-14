import { Head, router } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import { useState } from 'react';
import {
  Badge,
  Button,
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
  EmptyState,
  PageHeader,
} from '@/design-system';
import AppShell from '@/layouts/AppShell';

interface Grant {
  id: string;
  staff: string;
  ticket: number | null;
  reason: string;
  requested_level: string;
  level: string | null;
  minutes: number | null;
  status: 'requested' | 'active' | 'declined' | 'ended';
  ends_at: string | null;
  asked_at: string | null;
}

interface Props {
  grants: Grant[];
  minutes: number[];
}

const when = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' });
const time = new Intl.DateTimeFormat(undefined, { timeStyle: 'short' });

function Request({ grant, minutes, base }: { grant: Grant; minutes: number[]; base: string }) {
  const [level, setLevel] = useState<'view' | 'full'>('view');
  const [mins, setMins] = useState(60);
  return (
    <Card aria-label="Access request">
      <CardHeader>
        <CardTitle>
          {grant.staff} asks for {grant.requested_level === 'full' ? 'full' : 'view-only'} access
        </CardTitle>
        <CardDescription>
          {grant.ticket ? `For ticket #${grant.ticket}: ` : ''}
          {grant.reason}
        </CardDescription>
      </CardHeader>
      <CardContent className="grid gap-4 text-sm">
        <fieldset className="grid gap-2">
          <legend className="mb-1 font-medium">How much access?</legend>
          <label className="flex items-start gap-2">
            <input
              type="radio"
              name="level"
              checked={level === 'view'}
              onChange={() => setLevel('view')}
            />
            <span>
              <strong>View only</strong> (recommended) — they can look, but can’t change anything.
            </span>
          </label>
          <label className="flex items-start gap-2">
            <input
              type="radio"
              name="level"
              checked={level === 'full'}
              onChange={() => setLevel('full')}
            />
            <span>
              <strong>Full</strong> — they can make changes to fix things. Payouts, bank and payment
              details, billing, your team and security settings are always off-limits.
            </span>
          </label>
        </fieldset>
        <fieldset className="flex flex-wrap items-center gap-2">
          <legend className="mb-1 font-medium">For how long?</legend>
          {minutes.map((m) => (
            <Button
              key={m}
              type="button"
              size="sm"
              variant={m === mins ? 'primary' : 'secondary'}
              aria-pressed={m === mins}
              onClick={() => setMins(m)}
            >
              {m < 60 ? `${m} minutes` : m === 60 ? '1 hour' : `${m / 60} hours`}
            </Button>
          ))}
        </fieldset>
        <p className="text-fg-muted">
          Everything they do is recorded in your audit log as WURK staff. You can end access at any
          time.
        </p>
        <div className="flex flex-wrap gap-2">
          <Button
            onClick={() =>
              router.post(
                `${base}/${grant.id}/approve`,
                { level, minutes: mins },
                { preserveScroll: true },
              )
            }
          >
            Approve
          </Button>
          <Button
            variant="secondary"
            onClick={() => router.post(`${base}/${grant.id}/decline`, {}, { preserveScroll: true })}
          >
            Decline
          </Button>
        </div>
      </CardContent>
    </Card>
  );
}

/** Support → Access (Sprint 16.4): whether WURK support may see your back office, how far and for how long. */
export default function Access({ grants, minutes }: Props) {
  const base = window.location.pathname.replace(/\/$/, '');
  const open = grants.filter((g) => g.status === 'requested' || g.status === 'active');
  const past = grants.filter((g) => g.status !== 'requested' && g.status !== 'active');
  return (
    <AppShell title="Support access">
      <Head title="Support access" />
      <div className="grid max-w-3xl gap-6">
        <PageHeader
          title="Support access"
          description="WURK support can only see your back office if you say so — view-only unless you choose more, for a set time."
        />
        {open.length === 0 && (
          <EmptyState
            icon={ShieldCheck}
            title="No one from WURK has access"
            description="If support needs to look, they’ll ask here first."
          />
        )}
        {open.map((g) =>
          g.status === 'requested' ? (
            <Request key={g.id} grant={g} minutes={minutes} base={base} />
          ) : (
            <Card key={g.id} aria-label="Access now">
              <CardHeader>
                <CardTitle>
                  {g.staff} has {g.level === 'full' ? 'full' : 'view-only'} access
                </CardTitle>
                <CardDescription>
                  Until {g.ends_at ? time.format(new Date(g.ends_at)) : '—'}
                  {g.ticket ? ` · ticket #${g.ticket}` : ''}
                </CardDescription>
              </CardHeader>
              <CardContent>
                <Button
                  variant="danger"
                  onClick={() => router.post(`${base}/${g.id}/end`, {}, { preserveScroll: true })}
                >
                  End access now
                </Button>
              </CardContent>
            </Card>
          ),
        )}
        {past.length > 0 && (
          <Card>
            <CardHeader>
              <CardTitle>Earlier</CardTitle>
            </CardHeader>
            <CardContent>
              <ul aria-label="Earlier access" className="grid gap-2 text-sm">
                {past.map((g) => (
                  <li key={g.id} className="flex flex-wrap items-center gap-2">
                    <span>{g.asked_at ? when.format(new Date(g.asked_at)) : ''}</span>
                    <span>{g.staff}</span>
                    <Badge tone={g.status === 'declined' ? 'neutral' : 'info'}>
                      {g.status === 'declined'
                        ? 'Declined'
                        : `${g.level === 'full' ? 'Full' : 'View-only'}, ${g.minutes ?? 0} min — ended`}
                    </Badge>
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
