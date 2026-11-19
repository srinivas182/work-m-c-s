import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Badge } from '@/design-system/components/badge';
import { Button } from '@/design-system/components/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/design-system/components/card';
import { Checkbox, Switch } from '@/design-system/components/choice';
import { PageHeader } from '@/design-system/components/feedback';
import { Field } from '@/design-system/components/field';
import { Input } from '@/design-system/components/input';
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

interface Pack {
  code: string;
  name: string;
  enabled: boolean;
  currency: string;
  currency_symbol: string;
  currency_decimals: number;
  decimal_separator: string;
  thousands_separator: string;
  symbol_after: boolean;
  tax: { name: string; total_bp: number; confirmed: boolean };
  prices_include_tax: boolean;
  phone_prefix: string;
  phone_digits: number;
  timezone: string;
  week_starts_on: number;
  languages: string[];
  payment_providers: string[];
  sms_provider: string | null;
  ussd_provider: string | null;
  returns_template: string;
}

interface Rate {
  id: number;
  rate: number;
  levies: { name: string; rate: number }[];
  total: number;
  starts_on: string;
  note: string | null;
  confirmed: boolean;
  confirmed_at: string | null;
}

interface Props {
  country: Pack;
  rates: Rate[];
  providers: string[];
  returnsTemplates: Record<string, string>;
  languages: string[];
}

const selectClass = 'h-9 rounded-md border border-border bg-bg px-2 text-sm';

/** The example a business in this country sees, written the way the pack says. */
function example(p: {
  currency_symbol: string;
  currency_decimals: number;
  decimal_separator: string;
  thousands_separator: string;
  symbol_after: boolean;
}): string {
  const [whole = '0', part] = (1380.5).toFixed(p.currency_decimals).split('.');
  const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, p.thousands_separator);
  const n = part ? `${grouped}${p.decimal_separator}${part}` : grouped;
  return p.symbol_after ? `${n} ${p.currency_symbol}` : `${p.currency_symbol}${n}`;
}

function toggle(list: string[], value: string, on: boolean): string[] {
  return on ? [...new Set([...list, value])] : list.filter((v) => v !== value);
}

/** Admin → Countries → one country (Sprint 25.1): its pack, its tax rates by date, and sign-up. */
export default function Country({ country, rates, providers, returnsTemplates, languages }: Props) {
  const errors = usePage().props.errors as Record<string, string>;
  const pack = useForm({
    enabled: country.enabled,
    currency_symbol: country.currency_symbol,
    currency_decimals: country.currency_decimals,
    decimal_separator: country.decimal_separator,
    thousands_separator: country.thousands_separator,
    symbol_after: country.symbol_after,
    tax_name: country.tax.name,
    prices_include_tax: country.prices_include_tax,
    phone_digits: country.phone_digits,
    week_starts_on: country.week_starts_on,
    languages: country.languages,
    payment_providers: country.payment_providers,
    sms_provider: country.sms_provider ?? '',
    ussd_provider: country.ussd_provider ?? '',
    returns_template: country.returns_template,
  });
  const rate = useForm({
    rate: '',
    starts_on: '',
    note: '',
    levies: [] as { name: string; rate: string }[],
  });
  const url = `/countries/${country.code}`;

  return (
    <AppShell title={country.name}>
      <Head title={country.name} />
      <div className="grid gap-8">
        <PageHeader
          eyebrow={__('Countries')}
          title={`${country.name} · ${country.code}`}
          description={__(
            'What a business here starts with. Changes apply to new invoices and new businesses; earlier invoices keep what they were made with.',
          )}
          actions={
            <Link className="underline" href="/countries">
              {__('All countries')}
            </Link>
          }
        />

        <form
          className="grid gap-6"
          onSubmit={(e) => {
            e.preventDefault();
            pack.put(url, { preserveScroll: true });
          }}
        >
          <Card>
            <CardHeader>
              <CardTitle>{__('Money')}</CardTitle>
            </CardHeader>
            <CardContent className="grid gap-4 sm:grid-cols-3">
              <Field label={__('Currency')}>
                <Input value={country.currency} disabled />
              </Field>
              <Field label={__('Symbol')} error={pack.errors.currency_symbol}>
                <Input
                  value={pack.data.currency_symbol}
                  maxLength={8}
                  onChange={(e) => pack.setData('currency_symbol', e.target.value)}
                />
              </Field>
              <Field label={__('Decimals')}>
                <select
                  className={selectClass}
                  value={pack.data.currency_decimals}
                  onChange={(e) => pack.setData('currency_decimals', Number(e.target.value))}
                >
                  {[0, 2, 3].map((d) => (
                    <option key={d} value={d}>
                      {d}
                    </option>
                  ))}
                </select>
              </Field>
              <Field label={__('Decimal mark')}>
                <select
                  className={selectClass}
                  value={pack.data.decimal_separator}
                  onChange={(e) => pack.setData('decimal_separator', e.target.value)}
                >
                  <option value=".">{__('Point (1.50)')}</option>
                  <option value=",">{__('Comma (1,50)')}</option>
                </select>
              </Field>
              <Field label={__('Thousands')}>
                <select
                  className={selectClass}
                  value={pack.data.thousands_separator}
                  onChange={(e) => pack.setData('thousands_separator', e.target.value)}
                >
                  <option value=" ">{__('Space (1 000)')}</option>
                  <option value=",">{__('Comma (1,000)')}</option>
                  <option value=".">{__('Point (1.000)')}</option>
                </select>
              </Field>
              <label className="flex items-center gap-2 self-end text-sm">
                <Checkbox
                  checked={pack.data.symbol_after}
                  onCheckedChange={(v) => pack.setData('symbol_after', v === true)}
                />
                {__('Symbol after the amount')}
              </label>
              <p className="text-fg-muted text-sm sm:col-span-3">
                {__('Written as')} <strong className="tabular-nums">{example(pack.data)}</strong>
              </p>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>{__('Tax, phone and week')}</CardTitle>
            </CardHeader>
            <CardContent className="grid gap-4 sm:grid-cols-3">
              <Field label={__('Tax name')} error={pack.errors.tax_name}>
                <Input
                  value={pack.data.tax_name}
                  maxLength={20}
                  onChange={(e) => pack.setData('tax_name', e.target.value)}
                />
              </Field>
              <label className="flex items-center gap-2 self-end text-sm">
                <Checkbox
                  checked={pack.data.prices_include_tax}
                  onCheckedChange={(v) => pack.setData('prices_include_tax', v === true)}
                />
                {__('Prices include tax')}
              </label>
              <span />
              <Field label={__('Phone prefix')}>
                <Input value={`+${country.phone_prefix}`} disabled />
              </Field>
              <Field
                label={__('Digits after the prefix')}
                hint={__('Without the leading 0')}
                error={pack.errors.phone_digits}
              >
                <Input
                  type="number"
                  min={6}
                  max={12}
                  value={pack.data.phone_digits}
                  onChange={(e) => pack.setData('phone_digits', Number(e.target.value))}
                />
              </Field>
              <Field label={__('Week starts on')}>
                <select
                  className={selectClass}
                  value={pack.data.week_starts_on}
                  onChange={(e) => pack.setData('week_starts_on', Number(e.target.value))}
                >
                  <option value={1}>{__('Monday')}</option>
                  <option value={6}>{__('Saturday')}</option>
                  <option value={7}>{__('Sunday')}</option>
                </select>
              </Field>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>{__('Languages, providers and legal pages')}</CardTitle>
            </CardHeader>
            <CardContent className="grid gap-5">
              <fieldset className="grid gap-2">
                <legend className="mb-1 text-sm font-medium">
                  {__('Languages offered first')}
                </legend>
                <div className="flex flex-wrap gap-4">
                  {languages.map((l) => (
                    <label key={l} className="flex items-center gap-2 text-sm">
                      <Checkbox
                        checked={pack.data.languages.includes(l)}
                        onCheckedChange={(v) =>
                          pack.setData('languages', toggle(pack.data.languages, l, v === true))
                        }
                      />
                      {l}
                    </label>
                  ))}
                </div>
                {pack.errors.languages && (
                  <p className="text-danger-11 text-sm">{pack.errors.languages}</p>
                )}
              </fieldset>
              <fieldset className="grid gap-2">
                <legend className="mb-1 text-sm font-medium">{__('Payment providers')}</legend>
                <div className="flex flex-wrap gap-4">
                  {providers.map((p) => (
                    <label key={p} className="flex items-center gap-2 text-sm">
                      <Checkbox
                        checked={pack.data.payment_providers.includes(p)}
                        onCheckedChange={(v) =>
                          pack.setData(
                            'payment_providers',
                            toggle(pack.data.payment_providers, p, v === true),
                          )
                        }
                      />
                      {p}
                    </label>
                  ))}
                </div>
              </fieldset>
              <div className="grid gap-4 sm:grid-cols-3">
                <Field label={__('SMS provider')}>
                  <Input
                    value={pack.data.sms_provider}
                    onChange={(e) => pack.setData('sms_provider', e.target.value)}
                  />
                </Field>
                <Field label={__('USSD provider')} hint={__('Empty: no USSD here')}>
                  <Input
                    value={pack.data.ussd_provider}
                    onChange={(e) => pack.setData('ussd_provider', e.target.value)}
                  />
                </Field>
                <Field label={__('Returns policy template')}>
                  <select
                    className={selectClass}
                    value={pack.data.returns_template}
                    onChange={(e) => pack.setData('returns_template', e.target.value)}
                  >
                    {Object.entries(returnsTemplates).map(([key, name]) => (
                      <option key={key} value={key}>
                        {name}
                      </option>
                    ))}
                  </select>
                </Field>
              </div>
            </CardContent>
          </Card>

          <div className="flex flex-wrap items-center gap-4">
            <label className="flex items-center gap-2 text-sm">
              <Switch
                aria-label={__('Open for sign-up')}
                checked={pack.data.enabled}
                onCheckedChange={(v) => pack.setData('enabled', v)}
              />
              {__('Open for sign-up')}
            </label>
            <Button type="submit" loading={pack.processing}>
              {__('Save')}
            </Button>
            {(pack.errors.enabled ?? errors.enabled) && (
              <p role="alert" className="text-danger-11 text-sm">
                {pack.errors.enabled ?? errors.enabled}
              </p>
            )}
          </div>
        </form>

        <Card>
          <CardHeader>
            <CardTitle>{__('Tax rates')}</CardTitle>
          </CardHeader>
          <CardContent className="grid gap-5">
            <Table label={__('Tax rates')}>
              <TableHeader>
                <TableRow>
                  <TableHead>{__('From')}</TableHead>
                  <TableHead>{__('Rate')}</TableHead>
                  <TableHead>{__('Levies')}</TableHead>
                  <TableHead>{__('Total')}</TableHead>
                  <TableHead>{__('Status')}</TableHead>
                  <TableHead>
                    <span className="sr-only">{__('Actions')}</span>
                  </TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {rates.map((r) => (
                  <TableRow key={r.id}>
                    <TableCell className="tabular-nums">{r.starts_on}</TableCell>
                    <TableCell className="tabular-nums">{r.rate}%</TableCell>
                    <TableCell>
                      {r.levies.length === 0
                        ? '—'
                        : r.levies.map((l) => `${l.name} ${l.rate}%`).join(', ')}
                    </TableCell>
                    <TableCell className="tabular-nums">{r.total}%</TableCell>
                    <TableCell>
                      {r.confirmed ? (
                        <Badge tone="success">
                          {__('Confirmed :date', { date: r.confirmed_at ?? '' })}
                        </Badge>
                      ) : (
                        <Badge tone="warning">{__('To confirm')}</Badge>
                      )}
                      {r.note && <span className="text-fg-muted block text-xs">{r.note}</span>}
                    </TableCell>
                    <TableCell>
                      {!r.confirmed && (
                        <span className="flex gap-2">
                          <Button
                            size="sm"
                            onClick={() =>
                              router.post(
                                `${url}/rates/${r.id}/confirm`,
                                {},
                                { preserveScroll: true },
                              )
                            }
                          >
                            {__('Confirm')}
                          </Button>
                          <Button
                            size="sm"
                            variant="ghost"
                            onClick={() =>
                              router.delete(`${url}/rates/${r.id}`, { preserveScroll: true })
                            }
                          >
                            {__('Remove')}
                          </Button>
                        </span>
                      )}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>

            <form
              className="grid gap-4 sm:grid-cols-4"
              onSubmit={(e) => {
                e.preventDefault();
                rate.post(`${url}/rates`, { preserveScroll: true, onSuccess: () => rate.reset() });
              }}
            >
              <Field label={__('New rate (%)')} error={rate.errors.rate}>
                <Input
                  type="number"
                  step="0.25"
                  min={0}
                  max={50}
                  value={rate.data.rate}
                  onChange={(e) => rate.setData('rate', e.target.value)}
                />
              </Field>
              <Field label={__('From')} error={rate.errors.starts_on}>
                <Input
                  type="date"
                  value={rate.data.starts_on}
                  onChange={(e) => rate.setData('starts_on', e.target.value)}
                />
              </Field>
              <Field label={__('Note')} hint={__('e.g. Budget 2027')}>
                <Input
                  value={rate.data.note}
                  maxLength={200}
                  onChange={(e) => rate.setData('note', e.target.value)}
                />
              </Field>
              <div className="flex items-end gap-2">
                <Button
                  type="button"
                  variant="ghost"
                  onClick={() =>
                    rate.setData('levies', [...rate.data.levies, { name: '', rate: '' }])
                  }
                >
                  {__('Add a levy')}
                </Button>
                <Button type="submit" loading={rate.processing}>
                  {__('Add rate')}
                </Button>
              </div>
              {rate.data.levies.map((l, i) => (
                <div key={i} className="grid gap-4 sm:col-span-4 sm:grid-cols-4">
                  <Field label={__('Levy name')}>
                    <Input
                      value={l.name}
                      onChange={(e) =>
                        rate.setData(
                          'levies',
                          rate.data.levies.map((x, j) =>
                            j === i ? { ...x, name: e.target.value } : x,
                          ),
                        )
                      }
                    />
                  </Field>
                  <Field label={__('Levy (%)')}>
                    <Input
                      type="number"
                      step="0.25"
                      value={l.rate}
                      onChange={(e) =>
                        rate.setData(
                          'levies',
                          rate.data.levies.map((x, j) =>
                            j === i ? { ...x, rate: e.target.value } : x,
                          ),
                        )
                      }
                    />
                  </Field>
                </div>
              ))}
            </form>
          </CardContent>
        </Card>
      </div>
    </AppShell>
  );
}
