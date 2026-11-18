import { Head, useForm } from '@inertiajs/react';
import { Badge } from '@/design-system/components/badge';
import { Button } from '@/design-system/components/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/design-system/components/card';
import { Checkbox } from '@/design-system/components/choice';
import { Field } from '@/design-system/components/field';
import { Input } from '@/design-system/components/input';
import { PageHeader } from '@/design-system/components/feedback';
import AppShell from '@/layouts/AppShell';
import { __ } from '@/i18n';

interface Rules {
  enabled: boolean;
  self_bp: number;
  level_bp: number[];
  attribution: 'first' | 'last';
  link_days: number;
}
interface Props {
  rules: Rules;
  limits: { max_levels: number; max_bp: number };
  returnDays: number;
  wallet: Wallet;
}

interface Wallet {
  payouts_allowed: boolean;
  min_payout_cents: number;
  max_wallet_bp: number;
  expiry_months: number;
}

/** Basis points ↔ a percentage people type (1% = 100 bp; two decimals, no rounding drift). */
export const toPct = (bp: number) => String(Number((bp / 100).toFixed(2)));
export const toBp = (pct: string) => (pct.trim() === '' ? 0 : Math.round(Number(pct) * 100));

export default function ReferralSettings({ rules, limits, returnDays, wallet }: Props) {
  const form = useForm<Rules>(rules);
  const errors = form.errors as Record<string, string | undefined>;
  const total = form.data.self_bp + form.data.level_bp.reduce((a, b) => a + b, 0);
  const over = total > limits.max_bp;
  const base = window.location.pathname.replace(/\/$/, '');
  const setLevel = (i: number, bp: number) =>
    form.setData(
      'level_bp',
      form.data.level_bp.map((v, k) => (k === i ? bp : v)),
    );
  return (
    <AppShell title={__('Referrals')}>
      <Head title={__('Referrals')} />
      <div className="grid max-w-3xl gap-6">
        <PageHeader
          title={__('Referrals')}
          description={__(
            'Reward customers for buying and for bringing others: a percentage back on their own purchases, and on purchases by people they referred — up to several levels.',
          )}
        />
        <form
          aria-label={__('Referral programme')}
          className="grid gap-6"
          onSubmit={(e) => {
            e.preventDefault();
            form.put(base, { preserveScroll: true });
          }}
        >
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                {__('Programme')}{' '}
                {rules.enabled ? (
                  <Badge tone="success">{__('On')}</Badge>
                ) : (
                  <Badge tone="neutral">{__('Off')}</Badge>
                )}
              </CardTitle>
            </CardHeader>
            <CardContent className="grid gap-4">
              <label className="flex items-center gap-2 text-sm">
                <Checkbox
                  checked={form.data.enabled}
                  onCheckedChange={(on) => form.setData('enabled', on === true)}
                />
                {__('Run a referral programme')}
              </label>
              <p className="text-fg-muted text-sm">
                {__('Your package: up to')} {limits.max_levels}{' '}
                {limits.max_levels === 1 ? 'level' : 'levels'} {__('and')} {toPct(limits.max_bp)}
                {__('% in total.')}
              </p>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>{__('Percentages')}</CardTitle>
            </CardHeader>
            <CardContent className="grid gap-4">
              <Field label={__('Back on their own purchases (%)')} error={errors.self_bp}>
                <Input
                  type="number"
                  inputMode="decimal"
                  min="0"
                  max="100"
                  step="0.01"
                  value={toPct(form.data.self_bp)}
                  onChange={(e) => form.setData('self_bp', toBp(e.target.value))}
                />
              </Field>
              <ol aria-label={__('Levels')} className="grid gap-3">
                {form.data.level_bp.map((bp, i) => (
                  <li key={i} className="flex items-end gap-2">
                    <Field
                      label={i === 0 ? 'Level 1 — someone they referred (%)' : `Level ${i + 1} (%)`}
                      error={errors[`level_bp.${i}`]}
                    >
                      <Input
                        type="number"
                        inputMode="decimal"
                        min="0"
                        max="100"
                        step="0.01"
                        value={toPct(bp)}
                        onChange={(e) => setLevel(i, toBp(e.target.value))}
                      />
                    </Field>
                    {i === form.data.level_bp.length - 1 && (
                      <Button
                        variant="ghost"
                        size="sm"
                        aria-label={`Remove level ${i + 1}`}
                        onClick={() => form.setData('level_bp', form.data.level_bp.slice(0, -1))}
                      >
                        {__('Remove')}
                      </Button>
                    )}
                  </li>
                ))}
              </ol>
              {errors.level_bp && <p className="text-danger text-sm">{errors.level_bp}</p>}
              {form.data.level_bp.length < limits.max_levels && (
                <Button
                  variant="secondary"
                  className="justify-self-start"
                  onClick={() => form.setData('level_bp', [...form.data.level_bp, 0])}
                >
                  {__('Add level')} {form.data.level_bp.length + 1}
                </Button>
              )}
              <p
                aria-live="polite"
                className={over ? 'text-danger text-sm font-medium' : 'text-fg-muted text-sm'}
              >
                {__('Total:')} {toPct(total)}
                {__('% of')} {toPct(limits.max_bp)}
                {__('% allowed')}
              </p>
              {errors.total && (
                <p role="alert" className="text-danger text-sm">
                  {errors.total}
                </p>
              )}
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>{__('How referrals count')}</CardTitle>
            </CardHeader>
            <CardContent className="grid gap-4">
              <fieldset className="grid gap-3">
                <legend className="text-sm font-medium">{__('Who gets the credit')}</legend>
                {/* Radios at least 20 px with room around them: WCAG 2.2 target size. */}
                <label className="flex items-start gap-2 text-sm">
                  <input
                    type="radio"
                    name="attribution"
                    className="mt-0.5 size-5 shrink-0"
                    checked={form.data.attribution === 'first'}
                    onChange={() => form.setData('attribution', 'first')}
                  />
                  {__('The first person who referred them — for life')}
                </label>
                <label className="flex items-start gap-2 text-sm">
                  <input
                    type="radio"
                    name="attribution"
                    className="mt-0.5 size-5 shrink-0"
                    checked={form.data.attribution === 'last'}
                    onChange={() => form.setData('attribution', 'last')}
                  />
                  {__('The last person who referred them')}
                </label>
              </fieldset>
              <div className="grid gap-4 sm:grid-cols-2">
                <Field label={__('A referral link counts for (days)')} error={errors.link_days}>
                  <Input
                    type="number"
                    inputMode="numeric"
                    min="1"
                    max="365"
                    value={form.data.link_days}
                    onChange={(e) => form.setData('link_days', Number(e.target.value))}
                  />
                </Field>
              </div>
              <p className="text-fg-muted text-sm">
                {returnDays === 0
                  ? 'You don’t give refunds, so rewards are available straight away.'
                  : `Rewards become available when your refunds and returns window ends — ${returnDays} ${returnDays === 1 ? 'day' : 'days'} after payment (for bookings, after the appointment). A refund within it cancels them.`}{' '}
                <a
                  className="underline"
                  href={`${base.replace(/\/settings\/referrals$/, '')}/settings/payments`}
                >
                  {__('Change it in Settings → Payments')}
                </a>
                .
              </p>
            </CardContent>
          </Card>

          <Button type="submit" className="justify-self-start" loading={form.processing}>
            {__('Save')}
          </Button>
        </form>
        <WalletRules wallet={wallet} base={base} />
      </div>
    </AppShell>
  );
}

/** What customers can do with their rewards: spend (up to a share of an order), cash out (above a minimum), and when unused rewards expire. */
function WalletRules({ wallet, base }: { wallet: Wallet; base: string }) {
  const form = useForm<Wallet>(wallet);
  const errors = form.errors as Record<string, string | undefined>;
  return (
    <Card>
      <CardHeader>
        <CardTitle>{__('Wallets')}</CardTitle>
      </CardHeader>
      <CardContent>
        <form
          aria-label={__('Wallet rules')}
          className="grid gap-4"
          onSubmit={(e) => {
            e.preventDefault();
            form.put(`${base}/wallet`, { preserveScroll: true });
          }}
        >
          <p className="text-fg-muted text-sm">
            {__(
              'Customers spend their available rewards with you — and, if you allow it, ask you to pay them out.',
            )}
          </p>
          <Field
            label={__('The most of an order a wallet can pay (%)')}
            error={errors.max_wallet_bp}
          >
            <Input
              type="number"
              inputMode="decimal"
              min="0"
              max="100"
              step="1"
              value={toPct(form.data.max_wallet_bp)}
              onChange={(e) => form.setData('max_wallet_bp', toBp(e.target.value))}
            />
          </Field>
          <Field
            label={__('Unused rewards expire after (months; 0 = never)')}
            error={errors.expiry_months}
          >
            <Input
              type="number"
              inputMode="numeric"
              min="0"
              max="60"
              value={form.data.expiry_months}
              onChange={(e) => form.setData('expiry_months', Number(e.target.value))}
            />
          </Field>
          <label className="flex items-center gap-2 text-sm">
            <Checkbox
              checked={form.data.payouts_allowed}
              onCheckedChange={(on) => form.setData('payouts_allowed', on === true)}
            />
            {__('Customers can ask for their rewards to be paid out')}
          </label>
          {form.data.payouts_allowed && (
            <Field label={__('Smallest payout (R)')} error={errors.min_payout_cents}>
              <Input
                type="number"
                inputMode="decimal"
                min="0"
                step="0.01"
                value={String(form.data.min_payout_cents / 100)}
                onChange={(e) => form.setData('min_payout_cents', toBp(e.target.value))}
              />
            </Field>
          )}
          <Button type="submit" className="justify-self-start" loading={form.processing}>
            {__('Save wallet rules')}
          </Button>
        </form>
      </CardContent>
    </Card>
  );
}
