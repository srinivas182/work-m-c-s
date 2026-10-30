import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { Columns3 } from 'lucide-react';
import {
  Badge,
  Button,
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  EmptyState,
  PageHeader,
} from '@/design-system';
import AppShell from '@/layouts/AppShell';

type Stage = {
  id: string | null;
  name: string;
  kind: 'open' | 'won' | 'lost';
};
interface LeadRow {
  id: string;
  title: string;
  stage: string;
  contact: string;
  value_cents: number | null;
  assignee: string | null;
  source: string;
  follow_up: string | null;
  overdue: boolean;
}

const rand = (c: number) => `R${(c / 100).toLocaleString('en-ZA', { maximumFractionDigits: 0 })}`;
const selectClass = 'border-border bg-bg h-9 rounded-md border px-2 text-sm';

function StagesEditor({ stages, base, done }: { stages: Stage[]; base: string; done: () => void }) {
  const [rows, setRows] = useState<Stage[]>(stages);
  const [error, setError] = useState<string | null>(null);
  const set = (i: number, patch: Partial<Stage>) =>
    setRows(rows.map((r, k) => (k === i ? { ...r, ...patch } : r)));
  return (
    <form
      aria-label="Edit stages"
      className="grid gap-3"
      onSubmit={(e) => {
        e.preventDefault();
        router.put(
          `${base}/stages`,
          { stages: rows },
          {
            preserveScroll: true,
            onSuccess: done,
            onError: (er) => setError(Object.values(er)[0] ?? null),
          },
        );
      }}
    >
      {error && (
        <p role="alert" className="text-danger text-sm">
          {error}
        </p>
      )}
      {rows.map((r, i) => (
        <div key={i} className="flex flex-wrap items-center gap-2 text-sm">
          <label className="flex items-center gap-2">
            Stage {i + 1}
            <input
              className={`${selectClass} w-40`}
              value={r.name}
              onChange={(e) => set(i, { name: e.target.value })}
            />
          </label>
          <label className="flex items-center gap-2">
            Type
            <select
              className={selectClass}
              value={r.kind}
              onChange={(e) => set(i, { kind: e.target.value as Stage['kind'] })}
            >
              <option value="open">Open</option>
              <option value="won">Won</option>
              <option value="lost">Lost</option>
            </select>
          </label>
          <Button
            type="button"
            size="sm"
            variant="ghost"
            aria-label={`Remove ${r.name || `stage ${i + 1}`}`}
            onClick={() => setRows(rows.filter((_, k) => k !== i))}
          >
            Remove
          </Button>
        </div>
      ))}
      <div className="flex flex-wrap gap-2">
        <Button
          type="button"
          variant="secondary"
          size="sm"
          onClick={() => setRows([...rows, { id: null, name: '', kind: 'open' }])}
        >
          Add a stage
        </Button>
        <Button type="submit" size="sm">
          Save stages
        </Button>
      </div>
    </form>
  );
}

export default function Leads({
  pipeline,
  stages,
  leads,
  view,
  closed,
  canManage,
}: {
  pipeline: { id: string; name: string };
  stages: Stage[];
  leads: LeadRow[];
  view: 'board' | 'list';
  closed: boolean;
  canManage: boolean;
}) {
  const base = window.location.pathname.replace(/\/$/, '');
  const [editing, setEditing] = useState(false);
  const move = (lead: string, stage: string) =>
    router.post(`${base}/${lead}/move`, { stage }, { preserveScroll: true });
  const card = (l: LeadRow) => (
    <li key={l.id} className="border-border bg-bg grid gap-1 rounded-md border p-3 text-sm">
      <Link className="font-medium underline" href={`${base}/${l.id}`}>
        {l.title}
      </Link>
      <span>{l.contact}</span>
      <span className="text-fg-muted flex flex-wrap gap-2">
        {l.value_cents != null && <span>{rand(l.value_cents)}</span>}
        {l.assignee && <span>· {l.assignee}</span>}
        {l.follow_up && <span>· follow up {l.follow_up}</span>}
      </span>
      {l.overdue && <Badge tone="warning">Overdue</Badge>}
      {canManage && (
        <label className="flex items-center gap-2">
          <span className="sr-only">Move {l.title} to</span>
          <select
            className={selectClass}
            value={l.stage}
            onChange={(e) => move(l.id, e.target.value)}
          >
            {stages.map((s) => (
              <option key={s.id} value={s.id ?? ''}>
                {s.name}
              </option>
            ))}
          </select>
        </label>
      )}
    </li>
  );
  return (
    <AppShell title="Leads">
      <Head title="Leads" />
      <div className="grid gap-6">
        <PageHeader
          title="Leads"
          description={`${pipeline.name} — follow up enquiries and quotes. Add a lead from a contact’s page.`}
          actions={
            <span className="flex gap-3 text-sm">
              <Link href={base} className={view === 'board' ? 'font-semibold' : 'underline'}>
                Board
              </Link>
              <Link
                href={`${base}?view=list`}
                className={view === 'list' ? 'font-semibold' : 'underline'}
              >
                List
              </Link>
              <Link
                href={closed ? `${base}?view=${view}` : `${base}?view=${view}&show=closed`}
                className="underline"
              >
                {closed ? 'Show open leads' : 'Show won and lost'}
              </Link>
              {canManage && (
                <button type="button" className="underline" onClick={() => setEditing(!editing)}>
                  Edit stages
                </button>
              )}
            </span>
          }
        />
        {editing && (
          <Card>
            <CardHeader>
              <CardTitle>Stages</CardTitle>
            </CardHeader>
            <CardContent>
              <StagesEditor stages={stages} base={base} done={() => setEditing(false)} />
            </CardContent>
          </Card>
        )}
        {leads.length === 0 ? (
          <EmptyState
            icon={Columns3}
            title="No leads yet"
            description="Open a lead from a contact’s page; forms and the chatbot will add them too."
          />
        ) : view === 'board' ? (
          <div className="grid gap-4 overflow-x-auto md:auto-cols-[minmax(14rem,1fr)] md:grid-flow-col">
            {stages.map((s) => (
              <section
                key={s.id}
                aria-label={s.name}
                className="bg-surface grid content-start gap-2 rounded-lg p-3"
              >
                <h2 className="text-sm font-semibold">
                  {s.name}{' '}
                  <span className="text-fg-muted">
                    ({leads.filter((l) => l.stage === s.id).length})
                  </span>
                </h2>
                <ul className="grid gap-2">{leads.filter((l) => l.stage === s.id).map(card)}</ul>
              </section>
            ))}
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <caption className="sr-only">Leads</caption>
              <thead>
                <tr className="border-border border-b">
                  {['Lead', 'Contact', 'Stage', 'Value', 'Assigned to', 'Follow up'].map((h) => (
                    <th key={h} scope="col" className="py-2">
                      {h}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {leads.map((l) => (
                  <tr key={l.id} className="border-border border-b">
                    <td className="py-2">
                      <Link className="underline" href={`${base}/${l.id}`}>
                        {l.title}
                      </Link>
                    </td>
                    <td className="py-2">{l.contact}</td>
                    <td className="py-2">{stages.find((s) => s.id === l.stage)?.name}</td>
                    <td className="py-2">{l.value_cents != null ? rand(l.value_cents) : '—'}</td>
                    <td className="py-2">{l.assignee ?? '—'}</td>
                    <td className="py-2">{l.follow_up ?? '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </AppShell>
  );
}
