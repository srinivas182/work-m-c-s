import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Badge } from '@/design-system/components/badge';
import { Button } from '@/design-system/components/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/design-system/components/card';
import { PageHeader } from '@/design-system/components/feedback';
import { Field } from '@/design-system/components/field';
import { Input } from '@/design-system/components/input';
import AppShell from '@/layouts/AppShell';
import { __ } from '@/i18n';

interface Props {
  tenant: string;
  business: string;
  handle: string | null;
  identity: { status: string; submitted: string | null; reason: string | null };
  registry: { status: string; number: string | null; reason: string | null };
  documents: string[];
  badge: { verified: boolean; registered: boolean; url: string };
}

function Decide({ url, what }: { url: string; what: string }) {
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const errors = usePage().props.errors as Record<string, string>;
  const send = (approve: boolean) => {
    setBusy(true);
    router.post(url, { approve, reason }, { preserveScroll: true, onFinish: () => setBusy(false) });
  };
  return (
    <div className="grid gap-3">
      <Field label={__('Reason, if you refuse (the owner sees it)')} error={errors.reason}>
        <Input value={reason} maxLength={300} onChange={(e) => setReason(e.target.value)} />
      </Field>
      <div className="flex gap-2">
        <Button onClick={() => send(true)} loading={busy}>
          {__('Approve :what', { what })}
        </Button>
        <Button variant="secondary" onClick={() => send(false)} disabled={busy}>
          {__('Refuse')}
        </Button>
      </div>
    </div>
  );
}

/** Admin → Verification → a business (Sprint 25.4). Every document view is logged. */
export default function Check({ tenant, business, identity, registry, documents, badge }: Props) {
  const base = `/verification/${tenant}`;
  return (
    <AppShell title={business}>
      <Head title={business} />
      <div className="grid max-w-3xl gap-6">
        <PageHeader
          eyebrow={__('Verification')}
          title={business}
          actions={
            <Link className="underline" href="/verification">
              {__('Back to the queue')}
            </Link>
          }
        />
        <p>
          {badge.verified ? (
            <Badge tone="success">{__('Verified')}</Badge>
          ) : (
            <Badge>{__('Not verified')}</Badge>
          )}
        </p>
        <Card>
          <CardHeader>
            <CardTitle>{__('Identity')}</CardTitle>
          </CardHeader>
          <CardContent className="grid gap-4">
            <p>
              {__('Status')}: <strong>{identity.status}</strong>
              {identity.submitted && <> · {__('sent :date', { date: identity.submitted })}</>}
            </p>
            {documents.length > 0 && (
              <p className="flex flex-wrap gap-4">
                {documents.map((d) => (
                  <a
                    key={d}
                    className="underline"
                    href={`${base}/documents/${d}`}
                    target="_blank"
                    rel="noreferrer"
                  >
                    {d === 'id' ? __('Open the ID document') : __('Open the selfie')}
                  </a>
                ))}
              </p>
            )}
            <p className="text-fg-muted text-sm">
              {__(
                'Check the name matches the owner, the document is current, and the face matches the selfie.',
              )}
            </p>
            {identity.status === 'pending' && (
              <Decide url={`${base}/identity`} what={__('identity')} />
            )}
          </CardContent>
        </Card>
        <Card>
          <CardHeader>
            <CardTitle>{__('Registration')}</CardTitle>
          </CardHeader>
          <CardContent className="grid gap-4">
            <p>
              {__('Status')}: <strong>{registry.status}</strong>
              {registry.number && (
                <>
                  {' '}
                  · <span className="tabular-nums">{registry.number}</span>
                </>
              )}
            </p>
            <p className="text-fg-muted text-sm">
              {__(
                'Look the number up in the company register (CIPC in South Africa) and check the business name matches.',
              )}
            </p>
            {registry.status === 'pending' && (
              <Decide url={`${base}/registry`} what={__('registration')} />
            )}
          </CardContent>
        </Card>
      </div>
    </AppShell>
  );
}
