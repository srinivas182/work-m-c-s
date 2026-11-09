import { router, useForm } from '@inertiajs/react';
import { Button, Card, CardContent, CardHeader, CardTitle, Field, Input } from '@/design-system';
import { AgentLayout } from '../../components/AgentLayout';

interface Category {
  key: string;
  name: string;
  templates: { key: string; name: string; description: string | null }[];
}
interface Demo {
  business: string;
  site: string;
  backOffice: string;
  expires_at: string;
  days_left: number;
}

/** The agent's demo site: make one from an industry template, share it, delete it. */
export default function AgentDemo({
  demo,
  catalogue,
  days,
}: {
  demo: Demo | null;
  catalogue: Category[];
  days: number;
}) {
  const form = useForm({ business: '', category: '', template: '' });
  const category = catalogue.find((c) => c.key === form.data.category);
  return (
    <AgentLayout title="Demo site">
      {demo ? (
        <Card>
          <CardHeader>
            <CardTitle>{demo.business}</CardTitle>
          </CardHeader>
          <CardContent className="grid gap-3 text-sm">
            <p>
              <a
                className="font-mono break-all underline"
                href={demo.site}
                target="_blank"
                rel="noopener noreferrer"
              >
                {demo.site}
              </a>
            </p>
            <p>
              Closes on {demo.expires_at} (
              {demo.days_left === 1 ? '1 day left' : `${demo.days_left} days left`}). Payments and
              messages are switched off, and search engines don’t list it.
            </p>
            <div className="flex flex-wrap gap-2">
              <Button size="sm" asChild>
                <a href={demo.backOffice}>Edit it in the back office</a>
              </Button>
              <Button size="sm" variant="secondary" asChild>
                <a href="/agent/demo/qr.svg" target="_blank" rel="noopener noreferrer">
                  QR code for their phone
                </a>
              </Button>
              <Button
                size="sm"
                variant="ghost"
                onClick={() => {
                  if (window.confirm(`Delete the ${demo.business} demo? The site goes offline.`)) {
                    router.post('/agent/demo/delete');
                  }
                }}
              >
                Delete demo
              </Button>
            </div>
          </CardContent>
        </Card>
      ) : (
        <form
          aria-label="Make a demo site"
          className="grid gap-3"
          onSubmit={(e) => {
            e.preventDefault();
            form.post('/agent/demo');
          }}
        >
          <p className="text-fg-muted text-sm">
            Build a prospect a real site in seconds from a template for their trade. It stays up for{' '}
            {days} days.
          </p>
          <Field label="Business name" error={form.errors.business}>
            <Input
              value={form.data.business}
              onChange={(e) => form.setData('business', e.target.value)}
            />
          </Field>
          <Field label="Industry">
            <select
              className="border-border bg-bg h-10 w-full rounded-md border px-3"
              value={form.data.category}
              onChange={(e) =>
                form.setData((d) => ({ ...d, category: e.target.value, template: '' }))
              }
            >
              <option value="">Choose an industry</option>
              {catalogue.map((c) => (
                <option key={c.key} value={c.key}>
                  {c.name}
                </option>
              ))}
            </select>
          </Field>
          <Field label="Template" error={form.errors.template}>
            <select
              className="border-border bg-bg h-10 w-full rounded-md border px-3"
              value={form.data.template}
              disabled={!category}
              onChange={(e) => form.setData('template', e.target.value)}
            >
              <option value="">Choose a template</option>
              {category?.templates.map((t) => (
                <option key={t.key} value={t.key}>
                  {t.name}
                </option>
              ))}
            </select>
          </Field>
          <Button type="submit" className="justify-self-start" loading={form.processing}>
            Make the demo
          </Button>
        </form>
      )}
    </AgentLayout>
  );
}
