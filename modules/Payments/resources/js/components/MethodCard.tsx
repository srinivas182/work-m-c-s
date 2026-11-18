import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Badge } from '@/design-system/components/badge';
import { Button } from '@/design-system/components/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/design-system/components/card';
import { Checkbox } from '@/design-system/components/choice';
import { Field } from '@/design-system/components/field';
import { Input } from '@/design-system/components/input';
import { __ } from '@/i18n';

export interface Method {
  provider:
    | 'yoco'
    | 'payfast'
    | 'ozow'
    | 'peach'
    | 'paystack'
    | 'mpesa'
    | 'mtn_momo'
    | 'airtel_money'
    | 'flutterwave'
    | 'eft';
  name: string;
  allowed?: boolean; // business: has WURK switched it on?
  available?: boolean; // admin: may businesses offer it?
  account?: Account | null;
  branches?: Record<string, Account | null>; // several branches: each branch's own account
  wurk?: Account | null;
  channels?: { key: string; label: string }[] | null; // Paystack: the ways to pay it offers here
  webhook?: string | null; // Paystack: the address to paste into Paystack
  wurk_account?: boolean; // admin: can WURK itself take payments with it?
  countries?: string[] | null; // admin: where it works (mobile money)
  business_offer?: boolean; // admin: can businesses offer it (not WURK-only gateways)?
}
interface Account {
  active: boolean;
  mode: 'test' | 'live';
  label: string | null;
  has_test: boolean;
  has_live: boolean;
  channels?: string[] | null; // Paystack: the owner's choice (empty = all)
}

/** Each provider's keys: label, and whether it's secret. */
const FIELDS: Record<Method['provider'], [string, string, boolean][]> = {
  yoco: [['secret_key', 'Secret key', true]],
  payfast: [
    ['merchant_id', 'Merchant ID', false],
    ['merchant_key', 'Merchant key', true],
    ['passphrase', 'Passphrase', true],
  ],
  ozow: [
    ['site_code', 'Site code', false],
    ['private_key', 'Private key', true],
    ['api_key', 'API key', true],
  ],
  peach: [
    ['entity_id', 'Entity ID', false],
    ['client_id', 'Client ID', false],
    ['client_secret', 'Client secret', true],
    ['merchant_id', 'Merchant ID', false],
    ['secret_token', 'Webhook secret token', true],
  ],
  paystack: [['secret_key', 'Secret key', true]],
  flutterwave: [
    ['secret_key', 'Secret key', true],
    ['secret_hash', 'Webhook secret hash', true],
  ],
  mpesa: [
    ['shortcode', 'Paybill or store number (shortcode)', false],
    ['till', 'Till number (Buy Goods only)', false],
    ['passkey', 'Lipa na M-Pesa passkey', true],
    ['consumer_key', 'Daraja consumer key', false],
    ['consumer_secret', 'Daraja consumer secret', true],
  ],
  mtn_momo: [
    ['api_user', 'API user', false],
    ['api_key', 'API key', true],
    ['subscription_key', 'Collections subscription key', true],
    ['environment', 'Market (live only, e.g. mtnghana)', false],
  ],
  airtel_money: [
    ['client_id', 'Client ID', false],
    ['client_secret', 'Client secret', true],
  ],
  eft: [['instructions', 'How to pay (bank, account number, branch code)', false]],
};

function KeysForm({
  m,
  mode,
  base,
  q,
  done,
}: {
  m: Method;
  mode: 'test' | 'live';
  base: string;
  q: string;
  done: () => void;
}) {
  const form = useForm<Record<string, string>>(
    Object.fromEntries(FIELDS[m.provider].map(([k]) => [k, ''])),
  );
  const errors = form.errors as Record<string, string | undefined>;
  return (
    <form
      aria-label={`${m.name} ${mode} keys`}
      className="grid gap-3 sm:grid-cols-2"
      onSubmit={(e) => {
        e.preventDefault();
        form.put(`${base}/${m.provider}/keys/${mode}${q}`, {
          preserveScroll: true,
          onSuccess: done,
        });
      }}
    >
      {FIELDS[m.provider].map(([key, label, secret]) =>
        m.provider === 'eft' ? (
          <Field key={key} label={label} error={errors[key]}>
            <textarea
              className="border-border bg-bg min-h-24 w-full rounded-md border p-3 text-sm"
              value={form.data[key]}
              onChange={(e) => form.setData(key, e.target.value)}
            />
          </Field>
        ) : (
          <Field key={key} label={label} error={errors[key]}>
            <Input
              type={secret ? 'password' : 'text'}
              autoComplete="off"
              value={form.data[key]}
              onChange={(e) => form.setData(key, e.target.value)}
            />
          </Field>
        ),
      )}
      <Button type="submit" className="justify-self-start" loading={form.processing}>
        {__('Save')} {mode} {m.provider === 'eft' ? 'details' : 'keys'}
      </Button>
    </form>
  );
}

/** Paystack: which ways to pay customers see, and the webhook address to paste into Paystack. */
function PaystackOptions({ m, a, base, q }: { m: Method; a: Account; base: string; q: string }) {
  const form = useForm<{ channels: string[] }>({ channels: a.channels ?? [] });
  const toggle = (key: string, on: boolean) =>
    form.setData(
      'channels',
      on ? [...form.data.channels, key] : form.data.channels.filter((c) => c !== key),
    );
  return (
    <div className="border-border grid gap-3 rounded-md border p-3">
      <form
        aria-label={__('Paystack ways to pay')}
        className="grid gap-2"
        onSubmit={(e) => {
          e.preventDefault();
          form.put(`${base}/paystack/channels${q}`, { preserveScroll: true });
        }}
      >
        <p className="font-medium">{__('Ways customers can pay')}</p>
        <p className="text-fg-muted">
          {__('Leave all unticked to offer every way your Paystack account allows.')}
        </p>
        <div className="flex flex-wrap gap-4">
          {(m.channels ?? []).map((c) => (
            <label key={c.key} className="flex items-center gap-2">
              <Checkbox
                checked={form.data.channels.includes(c.key)}
                onCheckedChange={(on) => toggle(c.key, on === true)}
              />
              {c.label}
            </label>
          ))}
        </div>
        {form.errors.channels && (
          <p role="alert" className="text-danger">
            {form.errors.channels}
          </p>
        )}
        <Button
          type="submit"
          size="sm"
          variant="secondary"
          className="justify-self-start"
          loading={form.processing}
        >
          {__('Save ways to pay')}
        </Button>
      </form>
      {m.webhook && (
        <div className="grid gap-1">
          <p className="font-medium">{__('Webhook address')}</p>
          <p className="text-fg-muted">
            In Paystack, open Settings → API Keys &amp; Webhooks and paste this as the webhook URL,
            so payments are confirmed even if a customer closes the page.
          </p>
          <code className="bg-surface rounded px-2 py-1 text-xs break-all">{m.webhook}</code>
        </div>
      )}
    </div>
  );
}

export function MethodCard({
  m,
  base,
  admin = false,
  branch = null,
}: {
  m: Method;
  base: string;
  admin?: boolean;
  /** Managing a branch's own account (else the business's). */
  branch?: string | null;
}) {
  const a = admin ? m.wurk : branch ? (m.branches?.[branch] ?? null) : m.account;
  const q = branch ? `?branch=${encodeURIComponent(branch)}` : '';
  const [editing, setEditing] = useState<'test' | 'live' | null>(null);
  const [error, setError] = useState<string | null>(null);
  const blocked = !admin && m.allowed === false;
  const save = (active: boolean, mode: 'test' | 'live') =>
    router.put(
      `${base}/${m.provider}${q}`,
      { active, mode },
      {
        preserveScroll: true,
        onError: (e) => setError(Object.values(e)[0] ?? null),
        onSuccess: () => setError(null),
      },
    );
  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex flex-wrap items-center gap-2">
          {m.name}
          {a?.active ? (
            <Badge tone={a.mode === 'live' ? 'success' : 'warning'}>
              {a.mode === 'live' ? 'Live' : 'Test mode — no real money moves'}
            </Badge>
          ) : (
            <Badge tone="neutral">{__('Off')}</Badge>
          )}
        </CardTitle>
      </CardHeader>
      <CardContent className="grid gap-3 text-sm">
        {admin && m.business_offer !== false && (
          <label className="flex items-center gap-2 font-medium">
            <Checkbox
              checked={m.available ?? true}
              onCheckedChange={(on) =>
                router.put(
                  `${base}/${m.provider}/available`,
                  { available: on === true },
                  { preserveScroll: true },
                )
              }
            />
            {__('Businesses may offer')} {m.name}
          </label>
        )}
        {admin && m.countries && (
          <p className="text-fg-muted">
            {__('For businesses in')} {m.countries.join(', ')}
            {__(', with their own')} {m.name}{' '}
            {__('account. Switched on per country with the country packs.')}
          </p>
        )}
        {admin && m.wurk_account === false ? null : blocked ? (
          <p className="text-fg-muted">
            {__('WURK has switched')} {m.name} {__('off for now — customers can’t pay with it.')}
          </p>
        ) : (
          <>
            {a?.label && <p>{a.label}</p>}
            <div className="flex flex-wrap items-center gap-3">
              <label className="flex items-center gap-2 font-medium">
                <Checkbox
                  checked={a?.active ?? false}
                  onCheckedChange={(on) => save(on === true, a?.mode ?? 'test')}
                />
                {admin ? `WURK accepts ${m.name}` : `Accept ${m.name}`}
              </label>
              {m.provider !== 'eft' && (
                <label className="flex items-center gap-2">
                  {__('Mode')}
                  <select
                    className="border-border bg-bg h-9 rounded-md border px-2"
                    value={a?.mode ?? 'test'}
                    onChange={(e) => save(a?.active ?? false, e.target.value as 'test' | 'live')}
                  >
                    <option value="test">{__('Test (no real money)')}</option>
                    <option value="live">{__('Live')}</option>
                  </select>
                </label>
              )}
            </div>
            {error && (
              <p role="alert" className="text-danger">
                {error}
              </p>
            )}
            <div className="flex flex-wrap gap-2">
              {(m.provider === 'eft' ? (['live'] as const) : (['test', 'live'] as const)).map(
                (mode) => (
                  <Button
                    key={mode}
                    variant="secondary"
                    size="sm"
                    onClick={() => setEditing(editing === mode ? null : mode)}
                  >
                    {(mode === 'test' ? a?.has_test : a?.has_live) ? 'Change' : 'Add'}{' '}
                    {m.provider === 'eft' ? 'bank details' : `${mode} keys`}
                  </Button>
                ),
              )}
              {!admin && a && (
                <Button
                  variant="ghost"
                  size="sm"
                  aria-label={`Disconnect ${m.name}`}
                  onClick={() =>
                    router.delete(`${base}/${m.provider}${q}`, { preserveScroll: true })
                  }
                >
                  {__('Disconnect')}
                </Button>
              )}
            </div>
            {editing && (
              <KeysForm m={m} mode={editing} base={base} q={q} done={() => setEditing(null)} />
            )}
            {!admin && m.provider === 'paystack' && a && (
              <PaystackOptions m={m} a={a} base={base} q={q} />
            )}
          </>
        )}
      </CardContent>
    </Card>
  );
}
