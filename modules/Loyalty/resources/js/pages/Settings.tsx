import { Head, useForm } from '@inertiajs/react';
import { Button } from '@/design-system/components/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/design-system/components/card';
import { PageHeader } from '@/design-system/components/feedback';
import { Field } from '@/design-system/components/field';
import { Input } from '@/design-system/components/input';
import AppShell from '@/layouts/AppShell';
import { __ } from '@/i18n';

interface Props {
  settings: {
    on: boolean;
    reward: string;
    kind: 'percent' | 'amount';
    value: number;
    max_cents: number | null;
    stamps: number;
    min_spend_cents: number;
    counts: ('booking' | 'order' | 'sale')[];
  };
  stats: { cards: number; ready: number; used: number };
  example: string;
}

const COUNTS = [
  ['booking', 'Bookings and classes'],
  ['order', 'Online shop orders'],
  ['sale', 'In-store sales'],
] as const;

/**
 * Customers → Loyalty card (Sprint 26.5): a stamp after each paid visit, order or sale; a full card gives one reward,
 * used once at the next booking or checkout. The customer follows it on WhatsApp and their account page — no app.
 */
export default function Settings({ settings, stats, example }: Props) {
  const form = useForm({
    on: settings.on,
    reward: settings.reward,
    kind: settings.kind,
    value: String(settings.value / 100),
    max: settings.max_cents === null ? '' : String(settings.max_cents / 100),
    stamps: settings.stamps,
    min_spend: settings.min_spend_cents === 0 ? '' : String(settings.min_spend_cents / 100),
    counts: settings.counts as string[],
  });

  return (
    <AppShell title={__('Loyalty card')}>
      <Head title={__('Loyalty card')} />
      <div className="grid gap-6">
        <PageHeader
          title={__('Loyalty card')}
          description={__(
            'A stamp after each paid visit. Customers see their card on WhatsApp — no app to download.',
          )}
        />
        <Card>
          <CardContent className="grid gap-1 pt-6 text-sm">
            <p>
              {stats.cards} {__('customers have a card')} · {stats.ready} {__('rewards ready')} ·{' '}
              {stats.used} {__('used')}
            </p>
            <p className="text-fg-muted">
              {__('What customers see:')} “{example}”
            </p>
          </CardContent>
        </Card>
        <Card>
          <CardHeader>
            <CardTitle>{__('How it works')}</CardTitle>
          </CardHeader>
          <CardContent>
            <form
              className="grid gap-4 text-sm"
              onSubmit={(e) => {
                e.preventDefault();
                form.put(window.location.pathname, { preserveScroll: true });
              }}
            >
              <label className="flex items-center gap-2">
                <input
                  type="checkbox"
                  checked={form.data.on}
                  onChange={(e) => form.setData('on', e.target.checked)}
                />
                {__('Give customers a loyalty card')}
              </label>
              <div className="grid gap-3 sm:grid-cols-2">
                <Field
                  label={__('Stamps for a reward')}
                  error={form.errors.stamps}
                  hint={__('From 2 to 30.')}
                >
                  <Input
                    type="number"
                    min={2}
                    max={30}
                    value={form.data.stamps}
                    onChange={(e) => form.setData('stamps', Number(e.target.value))}
                  />
                </Field>
                <Field
                  label={__('The reward, in your words')}
                  error={form.errors.reward}
                  hint={__('For example: your 8th haircut free')}
                >
                  <Input
                    value={form.data.reward}
                    maxLength={80}
                    onChange={(e) => form.setData('reward', e.target.value)}
                  />
                </Field>
              </div>
              <fieldset className="grid gap-2">
                <legend className="font-medium">{__('What the reward takes off')}</legend>
                <label className="flex items-center gap-2">
                  <input
                    type="radio"
                    name="kind"
                    checked={form.data.kind === 'percent'}
                    onChange={() => form.setData('kind', 'percent')}
                  />
                  {__('A percentage (100% = free)')}
                </label>
                <label className="flex items-center gap-2">
                  <input
                    type="radio"
                    name="kind"
                    checked={form.data.kind === 'amount'}
                    onChange={() => form.setData('kind', 'amount')}
                  />
                  {__('An amount')}
                </label>
              </fieldset>
              <div className="grid gap-3 sm:grid-cols-3">
                <Field
                  label={form.data.kind === 'percent' ? __('Percentage') : __('Amount')}
                  error={form.errors.value}
                >
                  <Input
                    inputMode="decimal"
                    value={form.data.value}
                    onChange={(e) => form.setData('value', e.target.value)}
                  />
                </Field>
                <Field
                  label={__('At most (optional)')}
                  error={form.errors.max}
                  hint={__('The most the reward can take off.')}
                >
                  <Input
                    inputMode="decimal"
                    value={form.data.max}
                    onChange={(e) => form.setData('max', e.target.value)}
                  />
                </Field>
                <Field
                  label={__('Least spend for a stamp (optional)')}
                  error={form.errors.min_spend}
                >
                  <Input
                    inputMode="decimal"
                    value={form.data.min_spend}
                    onChange={(e) => form.setData('min_spend', e.target.value)}
                  />
                </Field>
              </div>
              <fieldset className="grid gap-2">
                <legend className="font-medium">{__('What earns a stamp')}</legend>
                {COUNTS.map(([key, label]) => (
                  <label key={key} className="flex items-center gap-2">
                    <input
                      type="checkbox"
                      checked={form.data.counts.includes(key)}
                      onChange={(e) =>
                        form.setData(
                          'counts',
                          e.target.checked
                            ? [...form.data.counts, key]
                            : form.data.counts.filter((c) => c !== key),
                        )
                      }
                    />
                    {__(label)}
                  </label>
                ))}
                {form.errors.counts && (
                  <p role="alert" className="text-danger-11">
                    {form.errors.counts}
                  </p>
                )}
              </fieldset>
              <p className="text-fg-muted">
                {__(
                  'The reward never adds to a coupon: the customer gets whichever takes off more. It’s used once.',
                )}
              </p>
              <div>
                <Button type="submit" disabled={form.processing}>
                  {__('Save')}
                </Button>
              </div>
            </form>
          </CardContent>
        </Card>
      </div>
    </AppShell>
  );
}
