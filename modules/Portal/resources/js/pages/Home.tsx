import { useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import {
  Button,
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  Checkbox,
  Field,
  Input,
} from '@/design-system';
import { rand } from '@/lib/money';
import { PortalLayout } from '../components/PortalLayout';
import { __ } from '@/i18n';

interface Rewards {
  code: string;
  link: string;
  referred: { name: string; since: string }[];
  pending_cents: number;
  wallet_cents: number;
  expiry_months: number;
}
interface Props {
  payouts: {
    allowed: boolean;
    min_cents: number;
    wallet_cents: number;
    latest: {
      cents: number;
      status: 'requested' | 'paid' | 'declined';
      last4: string;
      reason: string | null;
      asked: string;
    } | null;
  };
  deletion: {
    status: 'open' | 'completed' | 'declined';
    reason: string | null;
    asked: string;
  } | null;
  business: string;
  name: string;
  rewards: Rewards | null;
  history: { type: string; summary: string; at: string }[];
}

const KIND: Record<string, string> = {
  order: 'Order',
  booking: 'Booking',
  class: 'Class',
  sale: 'In-store',
};

export default function Home({ business, name, rewards, history, deletion, payouts }: Props) {
  const [copied, setCopied] = useState(false);
  const [idVersion, setIdVersion] = useState(0);
  useEffect(() => {
    const t = window.setInterval(() => setIdVersion((v) => v + 1), 4 * 60 * 1000); // codes last 5 minutes
    return () => window.clearInterval(t);
  }, []);
  return (
    <PortalLayout
      business={business}
      title={name ? `Hi ${name.split(' ')[0]}` : 'Your account'}
      signedIn
    >
      <Card>
        <CardHeader>
          <CardTitle>{__('Show this at the till')}</CardTitle>
        </CardHeader>
        <CardContent className="grid gap-2 text-sm">
          <p>
            {__('So')} {business}{' '}
            {__('can add your in-store purchases to your account. It changes every few minutes.')}
          </p>
          <img
            src={`/account/id-qr.svg?v=${idVersion}`}
            alt={__('Your code for the till')}
            width={200}
            height={200}
            className="rounded-md bg-white p-2"
          />
        </CardContent>
      </Card>
      {rewards && (
        <>
          <Card>
            <CardHeader>
              <CardTitle>{__('Your rewards')}</CardTitle>
            </CardHeader>
            <CardContent className="grid gap-2 text-sm">
              <p className="text-3xl font-semibold">{rand(rewards.wallet_cents)}</p>
              <p>
                {__('to spend with')} {business}.
              </p>
              {rewards.pending_cents > 0 && (
                <p className="text-fg-muted">
                  {rand(rewards.pending_cents)}{' '}
                  {__('more on its way — available once the return period ends.')}
                </p>
              )}
              {rewards.expiry_months > 0 && (
                <p className="text-fg-muted">
                  {__('Unused rewards expire')} {rewards.expiry_months}{' '}
                  {__('months after they become available.')}
                </p>
              )}
            </CardContent>
          </Card>
          <Card>
            <CardHeader>
              <CardTitle>{__('Share and earn')}</CardTitle>
            </CardHeader>
            <CardContent className="grid gap-3 text-sm">
              <p>
                {__('When someone you send buys from')} {business}
                {__(', you earn a reward too.')}
              </p>
              <p className="font-mono break-all">{rewards.link}</p>
              <div className="flex flex-wrap gap-2">
                <Button
                  size="sm"
                  onClick={() =>
                    void navigator.clipboard?.writeText(rewards.link).then(() => setCopied(true))
                  }
                >
                  {copied ? 'Copied' : 'Copy your link'}
                </Button>
                <Button size="sm" variant="secondary" asChild>
                  <a href="/account/referral-qr.svg" download={`referral-${rewards.code}.svg`}>
                    {__('Download your QR code')}
                  </a>
                </Button>
              </div>
              <img
                src="/account/referral-qr.svg"
                alt={__('Your referral QR code')}
                width={160}
                height={160}
                className="rounded-md bg-white p-2"
              />
              {rewards.referred.length > 0 && (
                <>
                  <h3 className="font-medium">{__('People you’ve referred')}</h3>
                  <ul aria-label={__('People you’ve referred')} className="grid gap-1">
                    {rewards.referred.map((r, i) => (
                      <li key={i}>
                        {r.name} {__('· since')} {r.since}
                      </li>
                    ))}
                  </ul>
                </>
              )}
            </CardContent>
          </Card>
        </>
      )}
      <Card>
        <CardHeader>
          <CardTitle>{__('Your history')}</CardTitle>
        </CardHeader>
        <CardContent>
          {history.length === 0 ? (
            <p className="text-fg-muted text-sm">{__('Nothing yet.')}</p>
          ) : (
            <ul aria-label={__('Your history')} className="grid gap-2 text-sm">
              {history.map((h, i) => (
                <li key={i}>
                  <strong>{KIND[h.type] ?? h.type}</strong> · {h.summary} · {h.at}
                </li>
              ))}
            </ul>
          )}
        </CardContent>
      </Card>
      {rewards && payouts.allowed && <PayoutCard business={business} payouts={payouts} />}
      <YourInformation business={business} deletion={deletion} />
    </PortalLayout>
  );
}

/** POPIA: a copy of what the business holds, and asking for it to be deleted (Sprint 13.6e). */
function YourInformation({
  business,
  deletion,
}: {
  business: string;
  deletion: Props['deletion'];
}) {
  const [asking, setAsking] = useState(false);
  const form = useForm({ confirm: false });
  return (
    <Card>
      <CardHeader>
        <CardTitle>{__('Your information')}</CardTitle>
      </CardHeader>
      <CardContent className="grid gap-3 text-sm">
        <a className="underline" href="/account/my-information.json" download>
          {__('Download a copy of your information')}
        </a>
        {deletion?.status === 'open' ? (
          <p role="status">
            {__('You asked')} {business} {__('to delete your information on')} {deletion.asked}
            {__('. We’ll email you when it’s done.')}
          </p>
        ) : deletion?.status === 'declined' && !asking ? (
          <p>
            {business} {__('couldn’t delete your information:')} {deletion.reason}
          </p>
        ) : !asking ? (
          <Button
            size="sm"
            variant="ghost"
            className="justify-self-start"
            onClick={() => setAsking(true)}
          >
            {__('Ask')} {business} {__('to delete my information')}
          </Button>
        ) : (
          <form
            aria-label={__('Ask for my information to be deleted')}
            className="grid gap-3"
            onSubmit={(e) => {
              e.preventDefault();
              form.post('/account/delete-request', { preserveScroll: true });
            }}
          >
            <p>
              {__('If')} {business} {__('approves it:')}
            </p>
            <ul className="grid list-disc gap-1 pl-5">
              <li>{__('any money in your wallet is paid out or refunded to you first;')}</li>
              <li>{__('rewards that aren’t available yet are cancelled;')}</li>
              <li>
                {__(
                  'your name and contact details are removed, and this account and your referral link stop working;',
                )}
              </li>
              <li>
                {__(
                  'records the law requires them to keep (such as invoices and patient records) are kept for as long as required.',
                )}
              </li>
            </ul>
            <label className="flex items-start gap-2">
              <Checkbox
                checked={form.data.confirm}
                onCheckedChange={(on) => form.setData('confirm', on === true)}
              />
              {__('I understand — please delete my information.')}
            </label>
            {form.errors.confirm && (
              <p role="alert" className="text-danger">
                {form.errors.confirm}
              </p>
            )}
            <div className="flex gap-2">
              <Button size="sm" type="submit" loading={form.processing}>
                {__('Send my request')}
              </Button>
              <Button size="sm" variant="ghost" onClick={() => setAsking(false)}>
                {__('Cancel')}
              </Button>
            </div>
          </form>
        )}
      </CardContent>
    </Card>
  );
}

/** Ask the business to pay out the wallet (Sprint 13.7). */
function PayoutCard({ business, payouts }: { business: string; payouts: Props['payouts'] }) {
  const form = useForm({
    amount: String(payouts.wallet_cents / 100),
    holder: '',
    bank: '',
    account: '',
    branch: '',
  });
  const waiting = payouts.latest?.status === 'requested';
  return (
    <Card>
      <CardHeader>
        <CardTitle>{__('Payouts')}</CardTitle>
      </CardHeader>
      <CardContent className="grid gap-3 text-sm">
        {payouts.latest && (
          <p role="status">
            {payouts.latest.status === 'requested' &&
              `You asked for ${rand(payouts.latest.cents)} on ${payouts.latest.asked} — ${business} will pay it into the account ending ${payouts.latest.last4}.`}
            {payouts.latest.status === 'paid' &&
              `${rand(payouts.latest.cents)} was paid into the account ending ${payouts.latest.last4}.`}
            {payouts.latest.status === 'declined' &&
              `${business} couldn’t pay out ${rand(payouts.latest.cents)}: ${payouts.latest.reason} It’s back in your wallet.`}
          </p>
        )}
        {!waiting &&
          (payouts.wallet_cents < payouts.min_cents ? (
            <p className="text-fg-muted">
              {__('Payouts start at')} {rand(payouts.min_cents)}.
            </p>
          ) : (
            <form
              aria-label={__('Ask for a payout')}
              className="grid gap-3"
              onSubmit={(e) => {
                e.preventDefault();
                form.post('/account/payout', { preserveScroll: true });
              }}
            >
              <Field
                label={`Amount (R; from ${rand(payouts.min_cents)} to ${rand(payouts.wallet_cents)})`}
                error={form.errors.amount}
              >
                <Input
                  type="number"
                  inputMode="decimal"
                  min="0.01"
                  step="0.01"
                  value={form.data.amount}
                  onChange={(e) => form.setData('amount', e.target.value)}
                />
              </Field>
              <Field label={__('Account holder')} error={form.errors.holder}>
                <Input
                  autoComplete="name"
                  value={form.data.holder}
                  onChange={(e) => form.setData('holder', e.target.value)}
                />
              </Field>
              <Field label={__('Bank')} error={form.errors.bank}>
                <Input
                  value={form.data.bank}
                  onChange={(e) => form.setData('bank', e.target.value)}
                />
              </Field>
              <Field label={__('Account number')} error={form.errors.account}>
                <Input
                  inputMode="numeric"
                  autoComplete="off"
                  value={form.data.account}
                  onChange={(e) => form.setData('account', e.target.value)}
                />
              </Field>
              <Field label={__('Branch code (optional)')} error={form.errors.branch}>
                <Input
                  inputMode="numeric"
                  value={form.data.branch}
                  onChange={(e) => form.setData('branch', e.target.value)}
                />
              </Field>
              <p className="text-fg-muted">
                {__(
                  'The amount is set aside straight away. Your account number is kept encrypted and deleted once the payout is done.',
                )}
              </p>
              <Button
                size="sm"
                type="submit"
                className="justify-self-start"
                loading={form.processing}
              >
                {__('Ask for a payout')}
              </Button>
            </form>
          ))}
      </CardContent>
    </Card>
  );
}
