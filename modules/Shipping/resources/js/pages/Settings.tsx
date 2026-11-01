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

interface Box {
  name: string;
  length_cm: number | null;
  width_cm: number | null;
  height_cm: number | null;
  max_kg: number | null;
}

/** The default weight for items without one, and the boxes the business ships in (Sprint 12.6). */
function Parcels({
  initial,
  base,
}: {
  initial: { default_kg: number; boxes: Box[] };
  base: string;
}) {
  const form = useForm<{ default_kg: number | null; boxes: Box[] }>({
    default_kg: initial.default_kg,
    boxes: initial.boxes,
  });
  const errors = form.errors as Record<string, string | undefined>;
  const num = (v: string) => (v === '' ? null : Number(v));
  const setBox = (i: number, patch: Partial<Box>) =>
    form.setData(
      'boxes',
      form.data.boxes.map((b, k) => (k === i ? { ...b, ...patch } : b)),
    );
  const cell = 'border-border bg-bg h-10 w-full rounded-md border px-2 text-sm';
  return (
    <section
      aria-labelledby="parcels-title"
      className="border-border grid gap-4 rounded-lg border p-4"
    >
      <h2 id="parcels-title" className="text-lg font-semibold">
        Parcels
      </h2>
      <p className="text-fg-muted text-sm">
        Courier prices depend on weight and size. Products without a weight use the default; each
        order goes in the smallest of your boxes that fits.
      </p>
      <label className="grid max-w-xs gap-1 text-sm font-medium" htmlFor="default-kg">
        Default weight per item (kg)
        <input
          id="default-kg"
          className={cell}
          type="number"
          inputMode="decimal"
          min="0.001"
          step="0.001"
          value={form.data.default_kg ?? ''}
          onChange={(e) => form.setData('default_kg', num(e.target.value))}
        />
        {errors.default_kg && <span className="text-danger">{errors.default_kg}</span>}
      </label>
      <ol aria-label="Boxes" className="grid gap-3">
        {form.data.boxes.map((b, i) => (
          <li key={i} className="border-border grid gap-2 rounded-md border p-3 sm:grid-cols-6">
            {(
              [
                ['name', 'Name', 'text'],
                ['length_cm', 'Length (cm)', 'number'],
                ['width_cm', 'Width (cm)', 'number'],
                ['height_cm', 'Height (cm)', 'number'],
                ['max_kg', 'Up to (kg)', 'number'],
              ] as const
            ).map(([key, label, type]) => (
              <label
                key={key}
                className={`grid gap-1 text-sm ${key === 'name' ? 'sm:col-span-2' : ''}`}
                htmlFor={`box-${i}-${key}`}
              >
                {label}
                <input
                  id={`box-${i}-${key}`}
                  className={cell}
                  type={type}
                  inputMode={type === 'number' ? 'decimal' : undefined}
                  min={type === 'number' ? '0' : undefined}
                  step={type === 'number' ? '0.1' : undefined}
                  maxLength={key === 'name' ? 40 : undefined}
                  value={b[key] ?? ''}
                  onChange={(e) =>
                    setBox(i, {
                      [key]: key === 'name' ? e.target.value : num(e.target.value),
                    } as Partial<Box>)
                  }
                />
                {errors[`boxes.${i}.${key}`] && (
                  <span className="text-danger">{errors[`boxes.${i}.${key}`]}</span>
                )}
              </label>
            ))}
            <div className="flex flex-wrap gap-2 sm:col-span-6">
              <Button
                size="sm"
                variant="ghost"
                aria-label={`Remove ${b.name || 'box'}`}
                onClick={() =>
                  form.setData(
                    'boxes',
                    form.data.boxes.filter((_, k) => k !== i),
                  )
                }
              >
                Remove
              </Button>
            </div>
          </li>
        ))}
      </ol>
      <div className="flex flex-wrap gap-2">
        <Button
          variant="secondary"
          onClick={() =>
            form.setData('boxes', [
              ...form.data.boxes,
              { name: '', length_cm: null, width_cm: null, height_cm: null, max_kg: null },
            ])
          }
        >
          Add a box
        </Button>
        <Button
          loading={form.processing}
          onClick={() => form.put(`${base}/parcels`, { preserveScroll: true })}
        >
          Save parcels
        </Button>
      </div>
    </section>
  );
}

export default function ShippingSettings({
  providers,
  branches,
  parcels,
}: {
  providers: Provider[];
  branches: { id: string; name: string }[];
  parcels: { default_kg: number; boxes: Box[] };
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
        <Parcels initial={parcels} base={base} />
      </div>
    </AppShell>
  );
}
