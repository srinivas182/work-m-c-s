import { Head, useForm } from '@inertiajs/react';
import { Badge } from '@/design-system/components/badge';
import { Button } from '@/design-system/components/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/design-system/components/card';
import { PageHeader } from '@/design-system/components/feedback';
import { Field } from '@/design-system/components/field';
import { Input } from '@/design-system/components/input';
import AppShell from '@/layouts/AppShell';
import { __ } from '@/i18n';

type Status = 'none' | 'pending' | 'approved' | 'refused';

interface Props {
  badge: { verified: boolean; registered: boolean; checked_on: string | null; url: string };
  checks: {
    phone: boolean;
    identity: { status: Status; reason: string | null; expires: string | null };
    registry: { status: Status; reason: string | null; number: string | null };
  };
}

const tenantBase = () => '/' + window.location.pathname.split('/')[1];

function StatusBadge({ status }: { status: Status }) {
  if (status === 'approved') return <Badge tone="success">{__('Checked')}</Badge>;
  if (status === 'pending') return <Badge tone="info">{__('WURK is checking')}</Badge>;
  if (status === 'refused') return <Badge tone="danger">{__('Not accepted')}</Badge>;
  return <Badge>{__('Not sent')}</Badge>;
}

/** Settings → Verified business (Sprint 25.4). */
export default function Verified({ badge, checks }: Props) {
  const identity = useForm<{ id_document: File | null; selfie: File | null }>({
    id_document: null,
    selfie: null,
  });
  const registry = useForm({ registry_number: checks.registry.number ?? '' });
  const canSendIdentity = checks.identity.status !== 'pending';

  return (
    <AppShell title={__('Verified business')}>
      <Head title={__('Verified business')} />
      <div className="grid max-w-3xl gap-6">
        <PageHeader
          title={__('Verified business')}
          description={__(
            '“Verified by WURK” shows on your website and invoices once WURK knows who runs the business. Sole traders qualify — no company needed.',
          )}
        />
        <Card>
          <CardContent className="grid gap-2 pt-5">
            {badge.verified ? (
              <p>
                <Badge tone="success">
                  {badge.registered
                    ? __('Verified by WURK · Registered business')
                    : __('Verified by WURK')}
                </Badge>{' '}
                <a className="underline" href={badge.url} target="_blank" rel="noreferrer">
                  {__('See what customers see')}
                </a>
              </p>
            ) : (
              <p className="text-fg-muted">{__('Not verified yet. Complete the checks below.')}</p>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>{__('1. The owner’s mobile number')}</CardTitle>
          </CardHeader>
          <CardContent>
            {checks.phone ? (
              <Badge tone="success">{__('Confirmed')}</Badge>
            ) : (
              <p>{__('Confirm your mobile number with a code in Account & security.')}</p>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>{__('2. The owner’s identity')}</CardTitle>
          </CardHeader>
          <CardContent className="grid gap-4">
            <p className="flex flex-wrap items-center gap-2">
              <StatusBadge status={checks.identity.status} />
              {checks.identity.status === 'approved' && checks.identity.expires && (
                <span className="text-fg-muted text-sm">
                  {__('Valid until :date', { date: checks.identity.expires })}
                </span>
              )}
            </p>
            {checks.identity.status === 'refused' && checks.identity.reason && (
              <p role="alert" className="text-danger-11">
                {checks.identity.reason}
              </p>
            )}
            {canSendIdentity && (
              <form
                className="grid gap-4"
                onSubmit={(e) => {
                  e.preventDefault();
                  identity.post(`${tenantBase()}/settings/verified/identity`, {
                    forceFormData: true,
                    preserveScroll: true,
                  });
                }}
              >
                <Field
                  label={__('Your ID document (ID card, ID book or passport)')}
                  hint={__('A clear photo or scan. JPG, PNG or PDF, up to 8 MB.')}
                  error={identity.errors.id_document}
                >
                  <Input
                    type="file"
                    accept="image/jpeg,image/png,application/pdf"
                    onChange={(e) => identity.setData('id_document', e.target.files?.[0] ?? null)}
                  />
                </Field>
                <Field
                  label={__('A photo of you holding the same ID')}
                  hint={__('Your face and the ID both clearly visible.')}
                  error={identity.errors.selfie}
                >
                  <Input
                    type="file"
                    accept="image/jpeg,image/png"
                    capture="user"
                    onChange={(e) => identity.setData('selfie', e.target.files?.[0] ?? null)}
                  />
                </Field>
                <p className="text-fg-muted text-sm">
                  {__(
                    'Only WURK’s checking team sees these. They’re stored encrypted and deleted 90 days after the check.',
                  )}
                </p>
                <div>
                  <Button type="submit" loading={identity.processing}>
                    {__('Send for checking')}
                  </Button>
                </div>
              </form>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>{__('3. Registered business (optional)')}</CardTitle>
          </CardHeader>
          <CardContent className="grid gap-4">
            <p className="flex flex-wrap items-center gap-2">
              <StatusBadge status={checks.registry.status} />
              {checks.registry.number && (
                <span className="tabular-nums">{checks.registry.number}</span>
              )}
            </p>
            {checks.registry.status === 'refused' && checks.registry.reason && (
              <p role="alert" className="text-danger-11">
                {checks.registry.reason}
              </p>
            )}
            {checks.registry.status !== 'pending' && (
              <form
                className="grid gap-3"
                onSubmit={(e) => {
                  e.preventDefault();
                  registry.post(`${tenantBase()}/settings/verified/registry`, {
                    preserveScroll: true,
                  });
                }}
              >
                <Field
                  label={__('Company or close-corporation registration number')}
                  hint={__('As on your registration certificate, e.g. 2019/123456/07.')}
                  error={registry.errors.registry_number}
                >
                  <Input
                    className="max-w-xs"
                    value={registry.data.registry_number}
                    onChange={(e) => registry.setData('registry_number', e.target.value)}
                  />
                </Field>
                <div>
                  <Button type="submit" variant="secondary" loading={registry.processing}>
                    {__('Send for checking')}
                  </Button>
                </div>
              </form>
            )}
          </CardContent>
        </Card>
      </div>
    </AppShell>
  );
}
