import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Badge } from '@/design-system/components/badge';
import { Button } from '@/design-system/components/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/design-system/components/card';
import { PageHeader } from '@/design-system/components/feedback';
import { Field } from '@/design-system/components/field';
import { Input } from '@/design-system/components/input';
import AppShell from '@/layouts/AppShell';
import { __ } from '@/i18n';

interface Domain {
  id: string;
  host: string;
  status: 'pending' | 'active' | 'lapsed';
  ssl: string | null;
  primary: boolean;
  problem: string | null;
  checked_at: string | null;
}

interface Props {
  allowed: boolean;
  available: boolean;
  wurk_address: string;
  target: string;
  max: number;
  domains: Domain[];
}

const base = () => '/' + window.location.pathname.split('/')[1] + '/website/domain';

/** The DNS record's name: "www" for www.…, else the whole domain (some DNS hosts want only the first part). */
const recordName = (host: string) => (host.startsWith('www.') ? 'www' : host);

/** A bare domain (glowsalon.co.za) rather than www.glowsalon.co.za: CNAMEs need a "flattening" DNS host there. */
const isBare = (host: string) => {
  const parts = host.split('.');
  const sld = ['co', 'org', 'net', 'ac', 'gov', 'web', 'com'];
  return parts.length === 2 || (parts.length === 3 && sld.includes(parts[1] ?? ''));
};

/**
 * Website → Your domain (Sprint 17.3): connect www.yourbusiness.co.za. One CNAME record where the domain is managed;
 * WURK gets the certificate; once it works it can be the site's main address (the WURK address then sends visitors
 * there). If it ever stops pointing at WURK, the site carries on at its WURK address.
 */
export default function Domain({ allowed, available, wurk_address, target, max, domains }: Props) {
  const add = useForm({ host: '' });
  const [removing, setRemoving] = useState<string | null>(null);

  return (
    <AppShell title={__('Your domain')}>
      <Head title={__('Your domain')} />
      <div className="grid max-w-3xl gap-6">
        <PageHeader
          title={__('Your domain')}
          description={`${__('Your site is always at')} ${wurk_address}. ${__('You can also show it on a domain you own.')}`}
        />

        {!allowed ? (
          <Card>
            <CardContent className="grid gap-3 pt-6 text-sm">
              <p>{__('Your own domain is part of the packages that include it.')}</p>
              <div>
                <Button asChild variant="secondary">
                  <Link href={'/' + window.location.pathname.split('/')[1] + '/billing'}>
                    {__('See packages')}
                  </Link>
                </Button>
              </div>
            </CardContent>
          </Card>
        ) : (
          <>
            {!available && (
              <p role="alert" className="text-warning-11 text-sm">
                {__('Connecting domains isn’t switched on yet. Please check again soon.')}
              </p>
            )}
            {domains.length < max && (
              <Card>
                <CardHeader>
                  <CardTitle>{__('Add your domain')}</CardTitle>
                </CardHeader>
                <CardContent>
                  <form
                    className="flex flex-wrap items-end gap-3"
                    onSubmit={(e) => {
                      e.preventDefault();
                      add.post(base(), { preserveScroll: true, onSuccess: () => add.reset() });
                    }}
                  >
                    <Field
                      label={__('Your domain')}
                      error={add.errors.host}
                      hint={__('For example: www.glowsalon.co.za')}
                      className="min-w-64 flex-1"
                    >
                      <Input
                        value={add.data.host}
                        autoComplete="off"
                        spellCheck={false}
                        inputMode="url"
                        onChange={(e) => add.setData('host', e.target.value)}
                      />
                    </Field>
                    <Button type="submit" disabled={add.processing || !available}>
                      {__('Add')}
                    </Button>
                  </form>
                </CardContent>
              </Card>
            )}

            {domains.map((d) => (
              <Card key={d.id}>
                <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-3">
                  <CardTitle className="break-all">{d.host}</CardTitle>
                  <div className="flex items-center gap-2">
                    {d.primary && <Badge tone="brand">{__('Main address')}</Badge>}
                    <Badge
                      tone={
                        d.status === 'active'
                          ? 'success'
                          : d.status === 'lapsed'
                            ? 'danger'
                            : 'warning'
                      }
                    >
                      {d.status === 'active'
                        ? __('Working')
                        : d.status === 'lapsed'
                          ? __('Stopped working')
                          : __('Waiting for your record')}
                    </Badge>
                  </div>
                </CardHeader>
                <CardContent className="grid gap-4 text-sm">
                  {d.status !== 'active' && (
                    <div className="grid gap-2">
                      <p>
                        {__(
                          'Where your domain is managed (the company you bought it from, or your DNS host), add this record:',
                        )}
                      </p>
                      <dl className="bg-surface border-border grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 rounded-md border p-3 font-mono">
                        <dt className="text-fg-muted font-sans">{__('Type')}</dt>
                        <dd>CNAME</dd>
                        <dt className="text-fg-muted font-sans">{__('Name')}</dt>
                        <dd className="break-all">{recordName(d.host)}</dd>
                        <dt className="text-fg-muted font-sans">{__('Value / points to')}</dt>
                        <dd className="break-all">{target}</dd>
                      </dl>
                      {isBare(d.host) && (
                        <p className="text-fg-muted">
                          {__(
                            'This is a domain without “www”. Many DNS hosts can’t point it with a CNAME: connect www.',
                          )}
                          {d.host}{' '}
                          {__(
                            'instead and redirect this one to it, or use a DNS host that offers “CNAME flattening” or “ALIAS” records (for example Cloudflare).',
                          )}
                        </p>
                      )}
                      <details className="text-fg-muted">
                        <summary className="text-fg cursor-pointer">
                          {__('Where do I add it?')}
                        </summary>
                        <ul className="mt-2 grid list-disc gap-1 pl-5">
                          <li>
                            {__(
                              'Afrihost, Xneelo, Domains.co.za, Hetzner and most South African hosts: sign in, open your domain, then “DNS”, “DNS management” or “Zone editor”, and add a CNAME record.',
                            )}
                          </li>
                          <li>
                            {__('GoDaddy: My Products → your domain → DNS → Add new record.')}
                          </li>
                          <li>
                            {__(
                              'Cloudflare: your domain → DNS → Records → Add record. Set the cloud to grey (DNS only).',
                            )}
                          </li>
                          <li>
                            {__(
                              'Remove any other record with the same name first (an A record or another CNAME).',
                            )}
                          </li>
                        </ul>
                      </details>
                    </div>
                  )}
                  {d.problem && (
                    <p
                      role={d.status === 'lapsed' ? 'alert' : undefined}
                      className={d.status === 'lapsed' ? 'text-danger-11' : 'text-fg-muted'}
                    >
                      {d.problem}
                    </p>
                  )}
                  <div className="flex flex-wrap gap-2">
                    {d.status !== 'active' && (
                      <Button
                        size="sm"
                        disabled={!available}
                        onClick={() =>
                          router.post(`${base()}/${d.id}/check`, {}, { preserveScroll: true })
                        }
                      >
                        {__('Check now')}
                      </Button>
                    )}
                    {d.status === 'active' && !d.primary && (
                      <Button
                        size="sm"
                        onClick={() =>
                          router.post(`${base()}/${d.id}/primary`, {}, { preserveScroll: true })
                        }
                      >
                        {__('Make it the main address')}
                      </Button>
                    )}
                    {removing === d.id ? (
                      <>
                        <Button
                          size="sm"
                          variant="danger"
                          onClick={() =>
                            router.delete(`${base()}/${d.id}`, {
                              preserveScroll: true,
                              onFinish: () => setRemoving(null),
                            })
                          }
                        >
                          {__('Yes, remove')} {d.host}
                        </Button>
                        <Button size="sm" variant="ghost" onClick={() => setRemoving(null)}>
                          {__('Keep it')}
                        </Button>
                      </>
                    ) : (
                      <Button size="sm" variant="ghost" onClick={() => setRemoving(d.id)}>
                        {__('Remove')}
                      </Button>
                    )}
                  </div>
                  {d.primary && (
                    <p className="text-fg-muted">
                      {__('Visitors to')} {wurk_address} {__('are sent to')} {d.host}.
                    </p>
                  )}
                </CardContent>
              </Card>
            ))}
          </>
        )}
      </div>
    </AppShell>
  );
}
