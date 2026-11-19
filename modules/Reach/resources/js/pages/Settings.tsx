import { Head, useForm } from '@inertiajs/react';
import { Badge } from '@/design-system/components/badge';
import { Button } from '@/design-system/components/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/design-system/components/card';
import { Switch } from '@/design-system/components/choice';
import { PageHeader } from '@/design-system/components/feedback';
import { Field } from '@/design-system/components/field';
import { Input } from '@/design-system/components/input';
import AppShell from '@/layouts/AppShell';
import { __ } from '@/i18n';

interface Props {
  reach: { number: string; ussd_on: boolean; sms_on: boolean; sms_keyword: string | null };
  how: { dial: string | null; sms: string | null };
  live: { ussd: boolean; sms: boolean };
  month: { ussd: number; sms: number };
}

const tenantBase = () => '/' + window.location.pathname.split('/')[1];

/**
 * Settings → Bookings by phone (Sprint 25.2b): customers without data or a smartphone book by dialling WURK's USSD
 * code with the business's number, or by texting its word to WURK's SMS number.
 */
export default function Settings({ reach, how, live, month }: Props) {
  const form = useForm({
    ussd_on: reach.ussd_on,
    sms_on: reach.sms_on,
    sms_keyword: reach.sms_keyword ?? '',
  });
  const save = () => form.put(`${tenantBase()}/settings/reach`, { preserveScroll: true });

  return (
    <AppShell title={__('Bookings by phone')}>
      <Head title={__('Bookings by phone')} />
      <div className="grid max-w-3xl gap-6">
        <PageHeader
          title={__('Bookings by phone')}
          description={__(
            'Customers without data or a smartphone can book, cancel or move a booking from any phone. A USSD session costs a few credits; each SMS reply costs one SMS.',
          )}
        />

        <Card>
          <CardHeader>
            <CardTitle>{__('How customers book')}</CardTitle>
          </CardHeader>
          <CardContent className="grid gap-4">
            <p>
              {__('Your number')}: <strong className="text-lg tabular-nums">{reach.number}</strong>
            </p>
            {how.dial && (
              <p>
                {__('Dial')} <strong className="tabular-nums">{how.dial}</strong>
              </p>
            )}
            {how.sms && <p>{how.sms}</p>}
            {(!live.ussd || !live.sms) && (
              <p className="text-fg-muted text-sm">
                {!live.ussd && !live.sms
                  ? __(
                      'WURK is still setting up its USSD code and SMS number. Your number is ready for when they are.',
                    )
                  : !live.ussd
                    ? __('WURK’s USSD code is still being set up.')
                    : __('WURK’s SMS number is still being set up.')}
              </p>
            )}
            <p className="text-fg-muted text-sm">
              {__('This month: :ussd USSD sessions, :sms SMS conversations.', {
                ussd: month.ussd,
                sms: month.sms,
              })}
            </p>
            <p>
              <a
                className="underline"
                href={`${tenantBase()}/settings/reach/card`}
                target="_blank"
                rel="noreferrer"
              >
                {__('Open the counter cards')}
              </a>{' '}
              <span className="text-fg-muted text-sm">
                {__('(four to an A4 page — print them from your browser)')}
              </span>
            </p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>{__('Settings')}</CardTitle>
          </CardHeader>
          <CardContent className="grid gap-5">
            <label className="flex items-center gap-3">
              <Switch
                checked={form.data.ussd_on}
                onCheckedChange={(v) => form.setData('ussd_on', v)}
                aria-label={__('Take bookings by USSD')}
              />
              {__('Take bookings by USSD')}
              {form.data.ussd_on ? (
                <Badge tone="success">{__('On')}</Badge>
              ) : (
                <Badge>{__('Off')}</Badge>
              )}
            </label>
            <label className="flex items-center gap-3">
              <Switch
                checked={form.data.sms_on}
                onCheckedChange={(v) => form.setData('sms_on', v)}
                aria-label={__('Take bookings by SMS')}
              />
              {__('Take bookings by SMS')}
              {form.data.sms_on ? (
                <Badge tone="success">{__('On')}</Badge>
              ) : (
                <Badge>{__('Off')}</Badge>
              )}
            </label>
            <Field
              label={__('Your SMS word (optional)')}
              hint={__('Customers can text this instead of BOOK and your number, e.g. GLOW.')}
              error={form.errors.sms_keyword}
            >
              <Input
                value={form.data.sms_keyword}
                maxLength={20}
                className="max-w-xs uppercase"
                onChange={(e) => form.setData('sms_keyword', e.target.value.toUpperCase())}
              />
            </Field>
            <div>
              <Button onClick={save} loading={form.processing}>
                {__('Save')}
              </Button>
            </div>
          </CardContent>
        </Card>
      </div>
    </AppShell>
  );
}
