import { Head, useForm } from '@inertiajs/react';
import { CheckCircle2, CircleAlert, Send } from 'lucide-react';
import type { FormEvent } from 'react';
import { Badge } from '@/design-system/components/badge';
import { Button } from '@/design-system/components/button';
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/design-system/components/card';
import { Field, Label } from '@/design-system/components/field';
import { Input } from '@/design-system/components/input';
import { PageHeader } from '@/design-system/components/feedback';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/design-system/components/select';
import { Switch } from '@/design-system/components/choice';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/design-system/components/table';
import AppShell from '@/layouts/AppShell';
import { __ } from '@/i18n';

interface Driver {
  key: string;
  name: string;
  fields: Record<string, { label: string; secret: boolean }>;
  settings: Record<string, string>;
}

interface Current {
  driver: string;
  active: boolean;
  settings: Record<string, string>;
  credentials: Record<string, { set: boolean; hint: string | null }>;
  last_test: { at: string; ok: boolean; message: string | null } | null;
}

interface Channel {
  channel: string;
  drivers: Driver[];
  current: Current | null;
}

const TITLES: Record<string, [string, string]> = {
  sms: [
    'SMS',
    'Sign-in codes when WhatsApp isn’t available, invitations by mobile, and business messages.',
  ],
  whatsapp: ['WhatsApp', 'Sign-in codes and business messages through approved templates.'],
  email: ['Email', 'Sign-in codes, invitations and receipts.'],
  'errors.sentry': [
    'Error tracking',
    'Server errors also go to Sentry, already masked by Watchtower (no personal data or keys). Optional.',
  ],
};

function ChannelCard({ channel }: { channel: Channel }) {
  const initialDriver = channel.current?.driver ?? channel.drivers[0]?.key ?? '';
  const form = useForm({
    driver: initialDriver,
    credentials: {} as Record<string, string>,
    settings: (channel.current?.settings ?? {}) as Record<string, string>,
    active: channel.current?.active ?? false,
  });
  const test = useForm({ to: '' });
  // Message providers send a real test; the others (AI, Paystack, meetings) check the keys (19.6).
  const sends = ['sms', 'whatsapp', 'email'].includes(channel.channel);
  const driver = channel.drivers.find((d) => d.key === form.data.driver) ?? channel.drivers[0];
  const sameDriver = channel.current?.driver === form.data.driver;
  const [title, description] = channel.channel.startsWith('payments.')
    ? [
        driver?.name ?? channel.channel,
        'Stored securely now; used to collect WURK subscriptions from Sprint 3.',
      ]
    : (TITLES[channel.channel] ?? [channel.channel, '']);

  const save = (e: FormEvent) => {
    e.preventDefault();
    form.put(`/providers/${channel.channel}`, {
      preserveScroll: true,
      onSuccess: () => form.setData('credentials', {}),
    });
  };

  if (!driver) return null;

  return (
    <Card>
      <CardHeader>
        <div className="flex items-start justify-between gap-3">
          <div>
            <CardTitle>{title}</CardTitle>
            <CardDescription>{description}</CardDescription>
          </div>
          {channel.current?.active ? (
            <Badge tone="success" dot>
              {__('On')}
            </Badge>
          ) : (
            <Badge>{__('Off')}</Badge>
          )}
        </div>
      </CardHeader>
      <CardContent className="grid gap-6">
        <form onSubmit={save} className="grid gap-5" noValidate>
          {channel.drivers.length > 1 && (
            <Field label={__('Provider')} className="sm:max-w-xs">
              {(control) => (
                <Select
                  value={form.data.driver}
                  onValueChange={(v) =>
                    form.setData((d) => ({ ...d, driver: v, credentials: {}, settings: {} }))
                  }
                >
                  <SelectTrigger {...control}>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {channel.drivers.map((d) => (
                      <SelectItem key={d.key} value={d.key}>
                        {d.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              )}
            </Field>
          )}
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            {Object.entries(driver.fields).map(([key, f]) => {
              const saved = sameDriver ? channel.current?.credentials[key] : undefined;
              return (
                <Field
                  key={key}
                  label={f.label}
                  error={form.errors[`credentials.${key}` as keyof typeof form.errors]}
                  hint={
                    saved?.set
                      ? f.secret
                        ? `Saved (${saved.hint}) — leave blank to keep`
                        : undefined
                      : undefined
                  }
                >
                  <Input
                    type={f.secret ? 'password' : 'text'}
                    autoComplete="off"
                    value={
                      form.data.credentials[key] ?? (!f.secret && saved?.hint ? saved.hint : '')
                    }
                    onChange={(e) =>
                      form.setData('credentials', {
                        ...form.data.credentials,
                        [key]: e.target.value,
                      })
                    }
                  />
                </Field>
              );
            })}
            {Object.entries(driver.settings).map(([key, label]) => (
              <Field key={key} label={label}>
                <Input
                  value={form.data.settings[key] ?? ''}
                  onChange={(e) =>
                    form.setData('settings', { ...form.data.settings, [key]: e.target.value })
                  }
                />
              </Field>
            ))}
          </div>
          <div className="flex flex-wrap items-center justify-between gap-4">
            <div className="flex items-center gap-3">
              <Switch
                id={`active-${channel.channel}`}
                checked={form.data.active}
                onCheckedChange={(v) => form.setData('active', v)}
              />
              <Label htmlFor={`active-${channel.channel}`}>{__('Use this provider')}</Label>
            </div>
            <Button type="submit" loading={form.processing}>
              {__('Save')}
            </Button>
          </div>
        </form>
        {channel.current && (
          <form
            onSubmit={(e) => {
              e.preventDefault();
              test.post(`/providers/${channel.channel}/test`, { preserveScroll: true });
            }}
            className="border-border flex flex-col gap-3 border-t pt-5 sm:flex-row sm:items-end"
          >
            {sends && (
              <Field
                label={
                  channel.channel === 'email' ? 'Send a test to (email)' : 'Send a test to (mobile)'
                }
                className="sm:w-72"
              >
                <Input value={test.data.to} onChange={(e) => test.setData('to', e.target.value)} />
              </Field>
            )}
            <Button
              type="submit"
              variant="secondary"
              loading={test.processing}
              disabled={sends && !test.data.to}
            >
              <Send /> {sends ? 'Send test' : 'Test connection'}
            </Button>
            {channel.current.last_test && (
              <p
                className={`flex items-center gap-1.5 text-sm ${channel.current.last_test.ok ? 'text-success-11' : 'text-danger-11'}`}
              >
                {channel.current.last_test.ok ? (
                  <CheckCircle2 className="size-4" aria-hidden />
                ) : (
                  <CircleAlert className="size-4" aria-hidden />
                )}
                {channel.current.last_test.ok
                  ? sends
                    ? 'Last test sent'
                    : 'Connected'
                  : channel.current.last_test.message}
              </p>
            )}
          </form>
        )}
      </CardContent>
    </Card>
  );
}

interface Props {
  channels: Channel[];
  recent: {
    channel: string;
    driver: string;
    to_masked: string;
    purpose: string;
    status: string;
    error: string | null;
    at: string;
  }[];
}

export default function Providers({ channels, recent }: Props) {
  const messaging = channels.filter((c) => !c.channel.startsWith('payments.'));
  const payments = channels.filter((c) => c.channel.startsWith('payments.'));

  return (
    <AppShell title={__('Providers')}>
      <Head title={__('Providers')} />
      <div className="grid max-w-4xl gap-8">
        <PageHeader
          title={__('Providers')}
          description={__(
            'The services WURK uses to send messages and collect payments. Secrets are stored encrypted and never shown again.',
          )}
        />
        {messaging.map((c) => (
          <ChannelCard key={c.channel} channel={c} />
        ))}
        <h2 className="font-display mt-4 text-xl font-semibold tracking-tight">
          {__('Payment gateways')}
        </h2>
        {payments.map((c) => (
          <ChannelCard key={c.channel} channel={c} />
        ))}
        {recent.length > 0 && (
          <Table label={__('Recent platform messages')}>
            <TableHeader>
              <TableRow>
                <TableHead>{__('When')}</TableHead>
                <TableHead>{__('Channel')}</TableHead>
                <TableHead>{__('To')}</TableHead>
                <TableHead>{__('Purpose')}</TableHead>
                <TableHead>{__('Status')}</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {recent.map((r, i) => (
                <TableRow key={i}>
                  <TableCell className="text-fg-muted">{new Date(r.at).toLocaleString()}</TableCell>
                  <TableCell>
                    {r.channel} · {r.driver}
                  </TableCell>
                  <TableCell className="font-mono text-xs">{r.to_masked}</TableCell>
                  <TableCell>{r.purpose.replace(/_/g, ' ')}</TableCell>
                  <TableCell>
                    <Badge
                      tone={
                        r.status === 'failed'
                          ? 'danger'
                          : r.status === 'delivered'
                            ? 'success'
                            : 'neutral'
                      }
                      title={r.error ?? undefined}
                    >
                      {r.status}
                    </Badge>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
      </div>
    </AppShell>
  );
}
