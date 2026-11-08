import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Users } from 'lucide-react';
import { useState } from 'react';
import {
  Badge,
  Button,
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  EmptyState,
  Field,
  Input,
  PageHeader,
} from '@/design-system';
import AppShell from '@/layouts/AppShell';
import { rand } from '@/lib/money';

interface Agent {
  id: string;
  name: string;
  code: string;
  status: 'active' | 'suspended';
  months: number | null;
  sponsor_id: string | null;
  sponsor: string | null;
  direct: number;
  team: number;
  businesses: number;
  link: string;
  commission: { pending: number; available: number; paid_out: number };
  owes_since: string | null;
  owes_flag: boolean;
}

/** Admin → Agents: the sponsor hierarchy. */
export default function Agents({ agents, canManage }: { agents: Agent[]; canManage: boolean }) {
  const errors = (usePage().props.errors ?? {}) as Record<string, string | undefined>;
  const add = useForm({ first_name: '', last_name: '', contact: '', sponsor_id: '', months: '' });
  return (
    <AppShell title="Agents">
      <Head title="Agents" />
      <div className="grid max-w-5xl gap-6">
        <PageHeader
          title="Agents"
          description="People who sell WURK packages, in their sponsor hierarchy. Commission runs for life, or for a set number of months from each business’s start."
        />
        {errors.agent && (
          <p role="alert" className="text-danger text-sm">
            {errors.agent}
          </p>
        )}
        {canManage && (
          <Card>
            <CardHeader>
              <CardTitle>Add an agent</CardTitle>
            </CardHeader>
            <CardContent>
              <form
                aria-label="Add an agent"
                className="grid gap-3 sm:grid-cols-2"
                onSubmit={(e) => {
                  e.preventDefault();
                  add.post('/agents', { preserveScroll: true, onSuccess: () => add.reset() });
                }}
              >
                <Field label="First name" error={add.errors.first_name}>
                  <Input
                    value={add.data.first_name}
                    onChange={(e) => add.setData('first_name', e.target.value)}
                  />
                </Field>
                <Field label="Last name" error={add.errors.last_name}>
                  <Input
                    value={add.data.last_name}
                    onChange={(e) => add.setData('last_name', e.target.value)}
                  />
                </Field>
                <Field
                  label="Phone number or email (they sign in with it)"
                  error={add.errors.contact}
                >
                  <Input
                    value={add.data.contact}
                    onChange={(e) => add.setData('contact', e.target.value)}
                  />
                </Field>
                <SponsorSelect
                  agents={agents}
                  value={add.data.sponsor_id}
                  onChange={(v) => add.setData('sponsor_id', v)}
                />
                <Field label="Commission for (months; empty = lifetime)" error={add.errors.months}>
                  <Input
                    type="number"
                    inputMode="numeric"
                    min="1"
                    value={add.data.months}
                    onChange={(e) => add.setData('months', e.target.value)}
                  />
                </Field>
                <Button
                  type="submit"
                  className="self-end justify-self-start"
                  loading={add.processing}
                >
                  Add agent
                </Button>
              </form>
            </CardContent>
          </Card>
        )}
        {agents.length === 0 ? (
          <EmptyState
            icon={Users}
            title="No agents yet"
            description="Add your first agent above."
          />
        ) : (
          <ul aria-label="Agents" className="grid gap-3">
            {agents.map((a) => (
              <AgentRow key={a.id} a={a} agents={agents} canManage={canManage} />
            ))}
          </ul>
        )}
      </div>
    </AppShell>
  );
}

function SponsorSelect({
  agents,
  value,
  onChange,
  except,
}: {
  agents: Agent[];
  value: string;
  onChange: (v: string) => void;
  except?: string;
}) {
  return (
    <Field label="Sponsor (the agent above them)">
      <select
        className="border-border bg-bg h-10 w-full rounded-md border px-3 text-sm"
        value={value}
        onChange={(e) => onChange(e.target.value)}
      >
        <option value="">No sponsor (top of a team)</option>
        {agents
          .filter((x) => x.id !== except)
          .map((x) => (
            <option key={x.id} value={x.id}>
              {x.name} ({x.code})
            </option>
          ))}
      </select>
    </Field>
  );
}

function AgentRow({ a, agents, canManage }: { a: Agent; agents: Agent[]; canManage: boolean }) {
  const [editing, setEditing] = useState(false);
  const [form, setForm] = useState({
    sponsor_id: a.sponsor_id ?? '',
    status: a.status,
    months: a.months === null ? '' : String(a.months),
  });
  return (
    <li className="border-border grid gap-2 rounded-lg border p-4 text-sm">
      <div className="flex flex-wrap items-center gap-2">
        <strong>{a.name}</strong>
        <span className="font-mono">{a.code}</span>
        {a.status === 'suspended' && <Badge tone="warning">Suspended</Badge>}
        {a.owes_since && (
          <Badge tone={a.owes_flag ? 'danger' : 'warning'}>
            {a.owes_flag
              ? `Owes WURK for 90+ days (since ${a.owes_since})`
              : `Owes WURK since ${a.owes_since}`}
          </Badge>
        )}
      </div>
      <p className="text-fg-muted">
        {a.sponsor ? `Sponsor: ${a.sponsor}` : 'No sponsor'} · {a.direct} direct · {a.team} in their
        team · {a.businesses === 1 ? '1 business' : `${a.businesses} businesses`} · commission{' '}
        {a.months === null ? 'for life' : `for ${a.months} months`}
      </p>
      <p>
        Commission: {rand(a.commission.available)} available · {rand(a.commission.pending)} pending
        · {rand(a.commission.paid_out)} paid out
      </p>
      <p className="break-all">
        <span className="font-mono">{a.link}</span> ·{' '}
        <a className="underline" href={`/agents/${a.id}/qr.svg`} download>
          QR code
        </a>
      </p>
      {canManage &&
        (editing ? (
          <form
            aria-label={`Change ${a.name}`}
            className="grid gap-3 sm:grid-cols-3"
            onSubmit={(e) => {
              e.preventDefault();
              router.put(`/agents/${a.id}`, form, {
                preserveScroll: true,
                onSuccess: () => setEditing(false),
              });
            }}
          >
            <SponsorSelect
              agents={agents}
              value={form.sponsor_id}
              onChange={(v) => setForm({ ...form, sponsor_id: v })}
              except={a.id}
            />
            <Field label="Status">
              <select
                className="border-border bg-bg h-10 w-full rounded-md border px-3"
                value={form.status}
                onChange={(e) => setForm({ ...form, status: e.target.value as Agent['status'] })}
              >
                <option value="active">Active</option>
                <option value="suspended">Suspended</option>
              </select>
            </Field>
            <Field label="Commission for (months; empty = lifetime)">
              <Input
                type="number"
                inputMode="numeric"
                min="1"
                value={form.months}
                onChange={(e) => setForm({ ...form, months: e.target.value })}
              />
            </Field>
            <Button size="sm" type="submit" className="justify-self-start">
              Save
            </Button>
          </form>
        ) : (
          <Button
            size="sm"
            variant="ghost"
            className="justify-self-start"
            onClick={() => setEditing(true)}
          >
            Change…
          </Button>
        ))}
    </li>
  );
}
