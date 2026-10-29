import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import {
  Badge,
  Button,
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  Checkbox,
  Field,
  Input,
} from '@/design-system';

export interface Method {
  provider: 'yoco' | 'payfast' | 'ozow' | 'peach' | 'eft';
  name: string;
  allowed?: boolean; // business: has WURK switched it on?
  available?: boolean; // admin: may businesses offer it?
  account?: Account | null;
  branches?: Record<string, Account | null>; // several branches: each branch's own account
  wurk?: Account | null;
}
interface Account {
  active: boolean;
  mode: 'test' | 'live';
  label: string | null;
  has_test: boolean;
  has_live: boolean;
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
        Save {mode} {m.provider === 'eft' ? 'details' : 'keys'}
      </Button>
    </form>
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
            <Badge tone="neutral">Off</Badge>
          )}
        </CardTitle>
      </CardHeader>
      <CardContent className="grid gap-3 text-sm">
        {admin && (
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
            Businesses may offer {m.name}
          </label>
        )}
        {blocked ? (
          <p className="text-fg-muted">
            WURK has switched {m.name} off for now — customers can’t pay with it.
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
                  Mode
                  <select
                    className="border-border bg-bg h-9 rounded-md border px-2"
                    value={a?.mode ?? 'test'}
                    onChange={(e) => save(a?.active ?? false, e.target.value as 'test' | 'live')}
                  >
                    <option value="test">Test (no real money)</option>
                    <option value="live">Live</option>
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
                  Disconnect
                </Button>
              )}
            </div>
            {editing && (
              <KeysForm m={m} mode={editing} base={base} q={q} done={() => setEditing(null)} />
            )}
          </>
        )}
      </CardContent>
    </Card>
  );
}
