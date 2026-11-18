import { router, useForm } from '@inertiajs/react';
import { Button, Card, CardContent, CardHeader, CardTitle, Field, Input } from '@/design-system';
import { AgentLayout } from '../../components/AgentLayout';
import { __ } from '@/i18n';

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
  claim: string | null;
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
    <AgentLayout title={__('Demo site')}>
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
            {demo.claim && (
              <p>
                {__('Their “Make it yours” link:')}{' '}
                <span className="font-mono break-all">{demo.claim}</span>
              </p>
            )}
            <p>
              {__('Closes on')} {demo.expires_at} (
              {demo.days_left === 1 ? '1 day left' : `${demo.days_left} days left`}
              {__('). Payments and messages are switched off, and search engines don’t list it.')}
            </p>
            <div className="flex flex-wrap gap-2">
              <Button size="sm" asChild>
                <a href={demo.backOffice}>{__('Edit it in the back office')}</a>
              </Button>
              <Button size="sm" variant="secondary" asChild>
                <a href="/agent/demo/qr.svg" target="_blank" rel="noopener noreferrer">
                  {__('QR code for their phone')}
                </a>
              </Button>
              {demo.claim && (
                <Button size="sm" variant="secondary" asChild>
                  <a
                    href={`https://wa.me/?text=${encodeURIComponent(`Here’s the website I made for ${demo.business}. Make it yours — sign up here and it’s live straight away: ${demo.claim}`)}`}
                    target="_blank"
                    rel="noopener noreferrer"
                  >
                    {__('Send “Make it yours” on WhatsApp')}
                  </a>
                </Button>
              )}
              <Button
                size="sm"
                variant="ghost"
                onClick={() => {
                  if (window.confirm(`Delete the ${demo.business} demo? The site goes offline.`)) {
                    router.post('/agent/demo/delete');
                  }
                }}
              >
                {__('Delete demo')}
              </Button>
            </div>
          </CardContent>
        </Card>
      ) : (
        <form
          aria-label={__('Make a demo site')}
          className="grid gap-3"
          onSubmit={(e) => {
            e.preventDefault();
            form.post('/agent/demo');
          }}
        >
          <p className="text-fg-muted text-sm">
            {__(
              'Build a prospect a real site in seconds from a template for their trade. It stays up for',
            )}{' '}
            {days} {__('days.')}
          </p>
          <Field label={__('Business name')} error={form.errors.business}>
            <Input
              value={form.data.business}
              onChange={(e) => form.setData('business', e.target.value)}
            />
          </Field>
          <Field label={__('Industry')}>
            <select
              className="border-border bg-bg h-10 w-full rounded-md border px-3"
              value={form.data.category}
              onChange={(e) =>
                form.setData((d) => ({ ...d, category: e.target.value, template: '' }))
              }
            >
              <option value="">{__('Choose an industry')}</option>
              {catalogue.map((c) => (
                <option key={c.key} value={c.key}>
                  {c.name}
                </option>
              ))}
            </select>
          </Field>
          <Field label={__('Template')} error={form.errors.template}>
            <select
              className="border-border bg-bg h-10 w-full rounded-md border px-3"
              value={form.data.template}
              disabled={!category}
              onChange={(e) => form.setData('template', e.target.value)}
            >
              <option value="">{__('Choose a template')}</option>
              {category?.templates.map((t) => (
                <option key={t.key} value={t.key}>
                  {t.name}
                </option>
              ))}
            </select>
          </Field>
          <Button type="submit" className="justify-self-start" loading={form.processing}>
            {__('Make the demo')}
          </Button>
        </form>
      )}
    </AgentLayout>
  );
}
