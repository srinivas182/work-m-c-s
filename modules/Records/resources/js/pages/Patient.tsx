import { Head, Link, router, useForm } from '@inertiajs/react';
import { Badge } from '@/design-system/components/badge';
import { Button } from '@/design-system/components/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/design-system/components/card';
import { Field } from '@/design-system/components/field';
import { Input } from '@/design-system/components/input';
import { PageHeader } from '@/design-system/components/feedback';
import AppShell from '@/layouts/AppShell';
import { __ } from '@/i18n';

interface Doc {
  id: string;
  title: string;
  version: number;
  original_id: string | null;
  note: string | null;
  by: string;
  size: number;
  date: string;
}

const ACTION: Record<string, string> = {
  upload: 'Document added',
  correct: 'Correction added',
  view: 'Opened',
  download: 'Downloaded',
  link_sent: 'Private link sent',
  code_sent: 'Code sent to the patient',
  code_ok: 'Patient confirmed the code',
  code_failed: 'Wrong code entered',
  flagged: 'Past the retention period',
  removed: 'Records removed',
};

export default function RecordsPatient({
  patient,
  documents,
  log,
  canRemove,
}: {
  patient: {
    id: string;
    name: string;
    email: string | null;
    phone: string | null;
    keptUntil: string | null;
    flagged: boolean;
  };
  documents: Doc[];
  log: { action: string; who: string; at: string }[];
  canRemove: boolean;
}) {
  const base = window.location.pathname.replace(/\/$/, '');
  const up = useForm<{ title: string; note: string; corrects: string; file: File | null }>({
    title: '',
    note: '',
    corrects: '',
    file: null,
  });
  const errors = up.errors as Record<string, string | undefined>;
  const originals = documents.filter((d) => d.original_id === null);
  const versionsOf = (id: string) => documents.filter((d) => d.original_id === id);
  return (
    <AppShell title={patient.name}>
      <Head title={patient.name} />
      <div className="grid max-w-4xl gap-6">
        <PageHeader
          title={patient.name}
          description={
            [patient.email, patient.phone].filter(Boolean).join(' · ') || 'No contact details'
          }
          actions={
            patient.flagged ? <Badge tone="warning">{__('Due for removal')}</Badge> : undefined
          }
        />
        <p>
          <Link href={base.replace(/\/[^/]+$/, '')} className="underline">
            {__('← All patients')}
          </Link>
        </p>
        <div className="flex flex-wrap gap-2">
          <Button
            variant="secondary"
            onClick={() => router.post(`${base}/link`, {}, { preserveScroll: true })}
          >
            {__('Send private link')}
          </Button>
          {canRemove && patient.flagged && (
            <Button
              variant="ghost"
              onClick={() => {
                if (
                  window.confirm(
                    `Remove all of ${patient.name}’s records? This can’t be undone. The access log keeps a note that it happened.`,
                  )
                )
                  router.delete(`${base}/remove`);
              }}
            >
              {__('Remove records')}
            </Button>
          )}
        </div>
        {patient.keptUntil && (
          <p className="text-fg-muted text-sm">
            {__('Kept until')} {patient.keptUntil} {__('(from the most recent document).')}
          </p>
        )}
        <Card>
          <CardHeader>
            <CardTitle>{__('Documents')}</CardTitle>
          </CardHeader>
          <CardContent>
            {originals.length === 0 ? (
              <p className="text-fg-muted text-sm">{__('No documents yet.')}</p>
            ) : (
              <ul className="grid gap-3" aria-label={__('Documents')}>
                {originals.map((d) => (
                  <li key={d.id} className="border-border grid gap-1 rounded-lg border p-3 text-sm">
                    <span>
                      <strong>{d.title}</strong> · {d.date} · {d.by} ·{' '}
                      <a
                        className="underline"
                        href={`${base}/documents/${d.id}`}
                        target="_blank"
                        rel="noopener noreferrer"
                      >
                        {__('Open')}
                      </a>
                    </span>
                    {versionsOf(d.id).map((v) => (
                      <span key={v.id} className="ml-4">
                        {__('Version')} {v.version} · {v.date} · {v.by}
                        {v.note ? ` — ${v.note}` : ''} ·{' '}
                        <a
                          className="underline"
                          href={`${base}/documents/${v.id}`}
                          target="_blank"
                          rel="noopener noreferrer"
                        >
                          {__('Open')}
                        </a>
                      </span>
                    ))}
                  </li>
                ))}
              </ul>
            )}
          </CardContent>
        </Card>
        <Card>
          <CardHeader>
            <CardTitle>{__('Add a document or a correction')}</CardTitle>
            <CardDescription>
              {__('A correction adds a new version; the original is always kept.')}
            </CardDescription>
          </CardHeader>
          <CardContent>
            <form
              aria-label={__('Add a document')}
              className="grid gap-3 sm:grid-cols-2"
              onSubmit={(e) => {
                e.preventDefault();
                up.post(`${base}/documents`, {
                  forceFormData: true,
                  preserveScroll: true,
                  onSuccess: () => up.reset(),
                });
              }}
            >
              <Field label={__('Title')} error={errors.title}>
                <Input
                  value={up.data.title}
                  onChange={(e) => up.setData('title', e.target.value)}
                />
              </Field>
              <Field label={__('This corrects (optional)')} error={errors.corrects}>
                <select
                  className="border-border bg-bg h-10 w-full rounded-md border px-3 text-sm"
                  value={up.data.corrects}
                  onChange={(e) => up.setData('corrects', e.target.value)}
                >
                  <option value="">{__('Nothing — a new document')}</option>
                  {originals.map((d) => (
                    <option key={d.id} value={d.id}>
                      {d.title} ({d.date})
                    </option>
                  ))}
                </select>
              </Field>
              <Field label={__('What changed (for corrections)')} error={errors.note}>
                <Input
                  value={up.data.note}
                  maxLength={500}
                  onChange={(e) => up.setData('note', e.target.value)}
                />
              </Field>
              <Field label={__('File (PDF, photo, Word or text, up to 20 MB)')} error={errors.file}>
                <input
                  type="file"
                  className="text-sm"
                  accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.txt"
                  onChange={(e) => up.setData('file', e.target.files?.[0] ?? null)}
                />
              </Field>
              <Button type="submit" className="justify-self-start" loading={up.processing}>
                {__('Add')}
              </Button>
            </form>
          </CardContent>
        </Card>
        <Card>
          <CardHeader>
            <CardTitle>{__('Access log')}</CardTitle>
            <CardDescription>
              {__('Every time these records were added to, opened, or shared.')}
            </CardDescription>
          </CardHeader>
          <CardContent>
            <ol className="grid gap-1 text-sm" aria-label={__('Access log')}>
              {log.map((l, k) => (
                <li key={k}>
                  <strong>{ACTION[l.action] ?? l.action}</strong> · {l.who} · {l.at}
                </li>
              ))}
            </ol>
          </CardContent>
        </Card>
      </div>
    </AppShell>
  );
}
