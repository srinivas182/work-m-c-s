import { Head, router, useForm } from '@inertiajs/react';
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

interface Account {
  active: boolean;
  mode: 'test' | 'live';
  label: string | null;
  has_test: boolean;
  has_live: boolean;
  flat_rate_cents: number | null;
}
interface Provider {
  provider: 'bobgo' | 'pargo';
  name: string;
  account: Account | null;
  branches: Record<string, Account | null>;
}

const FIELDS: Record<Provider['provider'], [string, string, string][]> = {
  bobgo: [
    ['api_key', 'API key', 'password'],
    ['webhook_secret', 'Webhook secret (optional)', 'password'],
  ],
  pargo: [
    ['username', 'Username (email)', 'email'],
    ['password', 'Password', 'password'],
    ['map_token', 'Map token (optional)', 'password'],
  ],
};

function Details({
  p,
  mode,
  base,
  q,
  done,
}: {
  p: Provider;
  mode: 'test' | 'live';
  base: string;
  q: string;
  done: () => void;
}) {
  const form = useForm<Record<string, string>>(
    Object.fromEntries(FIELDS[p.provider].map(([k]) => [k, ''])),
  );
  const errors = form.errors as Record<string, string | undefined>;
  return (
    <form
      aria-label={`${p.name} ${mode} details`}
      className="grid gap-3"
      onSubmit={(e) => {
        e.preventDefault();
        form.put(`${base}/${p.provider}/keys/${mode}${q}`, {
          preserveScroll: true,
          onSuccess: done,
        });
      }}
    >
      {FIELDS[p.provider].map(([key, label, type]) => (
        <Field key={key} label={label} error={errors[key]}>
          <Input
            type={type}
            autoComplete="off"
            value={form.data[key]}
            onChange={(e) => form.setData(key, e.target.value)}
          />
        </Field>
      ))}
      <Button type="submit" className="justify-self-start" loading={form.processing}>
        Save {mode} details
      </Button>
    </form>
  );
}

function ProviderCard({ p, base, branch }: { p: Provider; base: string; branch: string | null }) {
  const a = branch ? (p.branches[branch] ?? null) : p.account;
  const q = branch ? `?branch=${encodeURIComponent(branch)}` : '';
  const [editing, setEditing] = useState<'test' | 'live' | null>(null);
  const [rate, setRate] = useState(
    a?.flat_rate_cents != null ? String(a.flat_rate_cents / 100) : '',
  );
  const save = (active: boolean, mode: 'test' | 'live') =>
    router.put(
      `${base}/${p.provider}${q}`,
      {
        active,
        mode,
        ...(p.provider === 'pargo' && rate !== ''
          ? { flat_rate_cents: Math.round(Number(rate) * 100) }
          : {}),
      },
      { preserveScroll: true },
    );
  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex flex-wrap items-center gap-2">
          {p.name}
          {a?.active ? (
            <Badge tone={a.mode === 'live' ? 'success' : 'warning'}>
              {a.mode === 'live' ? 'On' : 'On — test mode'}
            </Badge>
          ) : (
            <Badge tone="neutral">Off</Badge>
          )}
        </CardTitle>
      </CardHeader>
      <CardContent className="grid gap-4 text-sm">
        {a?.label && <p className="text-fg-muted">Connected: {a.label}</p>}
        <div className="flex flex-wrap gap-2">
          <Button
            variant="secondary"
            size="sm"
            onClick={() => setEditing(editing === 'test' ? null : 'test')}
          >
            {a?.has_test ? 'Change test details' : 'Add test details'}
          </Button>
          <Button
            variant="secondary"
            size="sm"
            onClick={() => setEditing(editing === 'live' ? null : 'live')}
          >
            {a?.has_live ? 'Change live details' : 'Add live details'}
          </Button>
        </div>
        {editing && (
          <Details p={p} mode={editing} base={base} q={q} done={() => setEditing(null)} />
        )}
        {a && p.provider === 'pargo' && (
          <Field label="What customers pay for a pickup point (R)">
            <Input
              type="number"
              min={0}
              step={0.01}
              className="w-32"
              value={rate}
              onChange={(e) => setRate(e.target.value)}
            />
          </Field>
        )}
        {a && (
          <div className="flex flex-wrap gap-2">
            {a.has_test && (
              <Button
                size="sm"
                variant={a.active && a.mode === 'test' ? 'primary' : 'secondary'}
                onClick={() => save(true, 'test')}
              >
                Use test mode
              </Button>
            )}
            {a.has_live && (
              <Button
                size="sm"
                variant={a.active && a.mode === 'live' ? 'primary' : 'secondary'}
                onClick={() => save(true, 'live')}
              >
                Go live
              </Button>
            )}
            {a.active && (
              <Button size="sm" variant="ghost" onClick={() => save(false, a.mode)}>
                Switch off
              </Button>
            )}
            <Button
              size="sm"
              variant="ghost"
              aria-label={`Disconnect ${p.name}`}
              onClick={() => router.delete(`${base}/${p.provider}${q}`, { preserveScroll: true })}
            >
              Disconnect
            </Button>
          </div>
        )}
      </CardContent>
    </Card>
  );
}

export default function ShippingSettings({
  providers,
  branches,
}: {
  providers: Provider[];
  branches: { id: string; name: string }[];
}) {
  const base = window.location.pathname.replace(/\/$/, '');
  const [branch, setBranch] = useState('');
  return (
    <AppShell title="Shipping">
      <Head title="Shipping" />
      <div className="grid max-w-3xl gap-6">
        <PageHeader
          title="Shipping"
          description="Connect your own courier accounts. Bob Go gives customers live courier rates at checkout; Pargo lets them collect from a pickup point near them. You pay the couriers directly — WURK never handles the money."
        />
        {branches.length > 1 && (
          <div className="grid gap-2">
            <label className="flex flex-wrap items-center gap-2 text-sm font-medium">
              Accounts for
              <select
                className="border-border bg-bg h-10 rounded-md border px-3"
                value={branch}
                onChange={(e) => setBranch(e.target.value)}
              >
                <option value="">The whole business</option>
                {branches.map((b) => (
                  <option key={b.id} value={b.id}>
                    {b.name}
                  </option>
                ))}
              </select>
            </label>
            {branch && (
              <p className="text-fg-muted text-sm">
                Without its own account, this branch uses the business’s.
              </p>
            )}
          </div>
        )}
        {providers.map((p) => (
          <ProviderCard key={`${p.provider}-${branch}`} p={p} base={base} branch={branch || null} />
        ))}
      </div>
    </AppShell>
  );
}
