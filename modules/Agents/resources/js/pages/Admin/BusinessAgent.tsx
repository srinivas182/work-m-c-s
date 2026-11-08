import { Head, Link, useForm } from '@inertiajs/react';
import { Button, Card, CardContent, Field, PageHeader } from '@/design-system';
import AppShell from '@/layouts/AppShell';

interface Props {
  business: { id: string; name: string };
  current: { agent_id: string; source: 'link' | 'staff'; at: string } | null;
  agents: { id: string; name: string }[];
  canManage: boolean;
}

/** Admin → Business → Agent: who brought this business, and correcting it. */
export default function BusinessAgent({ business, current, agents, canManage }: Props) {
  const form = useForm({ agent_id: current?.agent_id ?? '' });
  const name = agents.find((a) => a.id === current?.agent_id)?.name;
  return (
    <AppShell title={`${business.name} — agent`}>
      <Head title={`${business.name} — agent`} />
      <div className="grid max-w-2xl gap-6">
        <PageHeader title="Agent" description={business.name} />
        <Card>
          <CardContent className="grid gap-3 pt-6 text-sm">
            <p>
              {current
                ? `${name ?? 'An agent'} — ${current.source === 'link' ? 'signed up through their link' : 'set by WURK staff'} on ${current.at}.`
                : 'No agent brought this business.'}
            </p>
            {canManage && (
              <form
                aria-label="Change the agent"
                className="flex flex-wrap items-end gap-3"
                onSubmit={(e) => {
                  e.preventDefault();
                  form.transform((d) => ({ agent_id: d.agent_id === '' ? null : d.agent_id }));
                  form.put(`/businesses/${business.id}/agent`, { preserveScroll: true });
                }}
              >
                <Field label="Agent" error={form.errors.agent_id}>
                  <select
                    className="border-border bg-bg h-10 w-full rounded-md border px-3"
                    value={form.data.agent_id}
                    onChange={(e) => form.setData('agent_id', e.target.value)}
                  >
                    <option value="">No agent</option>
                    {agents.map((a) => (
                      <option key={a.id} value={a.id}>
                        {a.name}
                      </option>
                    ))}
                  </select>
                </Field>
                <Button type="submit" loading={form.processing}>
                  Save
                </Button>
              </form>
            )}
            <Link className="underline" href={`/businesses/${business.id}`}>
              Back to {business.name}
            </Link>
          </CardContent>
        </Card>
      </div>
    </AppShell>
  );
}
