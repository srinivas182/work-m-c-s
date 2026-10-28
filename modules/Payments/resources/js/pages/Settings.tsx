import { Head, router, useForm } from '@inertiajs/react';
import {
  Badge,
  Button,
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
  Field,
  Input,
  PageHeader,
} from '@/design-system';
import AppShell from '@/layouts/AppShell';

interface Account {
  provider: 'payfast' | 'yoco';
  mode: 'test' | 'live';
  label: string | null;
  active: boolean;
}

const NAME = { payfast: 'PayFast', yoco: 'Yoco' };
const base = () => window.location.pathname.replace(/\/$/, '');
const selectClass = 'h-10 w-full rounded-md border border-border bg-bg px-3 text-sm';

function Connected({ a }: { a: Account }) {
  return (
    <div className="border-border flex flex-wrap items-center justify-between gap-3 rounded-lg border p-3">
      <p>
        <strong>{NAME[a.provider]}</strong> {a.label ? `— ${a.label}` : ''}{' '}
        <Badge tone={a.mode === 'live' ? 'success' : 'warning'}>
          {a.mode === 'live' ? 'Live' : 'Test mode — no real money moves'}
        </Badge>
      </p>
      <Button
        variant="ghost"
        size="sm"
        aria-label={`Disconnect ${NAME[a.provider]}`}
        onClick={() => router.delete(`${base()}/${a.provider}`, { preserveScroll: true })}
      >
        Disconnect
      </Button>
    </div>
  );
}

export default function PaymentSettings({ accounts }: { accounts: Account[] }) {
  const payfast = useForm({ merchant_id: '', merchant_key: '', passphrase: '', mode: 'test' });
  const yoco = useForm({ secret_key: '' });
  const has = (p: Account['provider']) => accounts.find((a) => a.provider === p);
  return (
    <AppShell title="Payments">
      <Head title="Payments" />
      <div className="grid max-w-3xl gap-6">
        <PageHeader
          title="Payments"
          description="Customers pay you by card through your own PayFast or Yoco account — for orders, deposits and classes. The money goes straight to you; WURK takes nothing."
        />
        {accounts.length > 0 && (
          <section aria-label="Connected" className="grid gap-2">
            {accounts.map((a) => (
              <Connected key={a.provider} a={a} />
            ))}
          </section>
        )}
        <Card>
          <CardHeader>
            <CardTitle>{has('yoco') ? 'Change your Yoco key' : 'Connect Yoco'}</CardTitle>
            <CardDescription>
              In the Yoco portal: Sales → Payment gateway → your secret key. A test key (sk_test_…)
              lets you try it without real money.
            </CardDescription>
          </CardHeader>
          <CardContent>
            <form
              aria-label="Connect Yoco"
              className="grid gap-3 sm:grid-cols-[1fr_auto] sm:items-end"
              onSubmit={(e) => {
                e.preventDefault();
                yoco.put(`${base()}/yoco`, { preserveScroll: true, onSuccess: () => yoco.reset() });
              }}
            >
              <Field label="Secret key" error={yoco.errors.secret_key}>
                <Input
                  type="password"
                  autoComplete="off"
                  value={yoco.data.secret_key}
                  onChange={(e) => yoco.setData('secret_key', e.target.value)}
                />
              </Field>
              <Button type="submit" loading={yoco.processing}>
                Connect Yoco
              </Button>
            </form>
          </CardContent>
        </Card>
        <Card>
          <CardHeader>
            <CardTitle>
              {has('payfast') ? 'Change your PayFast details' : 'Connect PayFast'}
            </CardTitle>
            <CardDescription>
              In PayFast: Settings → Integration — your merchant ID, merchant key and passphrase
              (set one there if you haven’t).
            </CardDescription>
          </CardHeader>
          <CardContent>
            <form
              aria-label="Connect PayFast"
              className="grid gap-3 sm:grid-cols-2"
              onSubmit={(e) => {
                e.preventDefault();
                payfast.put(`${base()}/payfast`, {
                  preserveScroll: true,
                  onSuccess: () => payfast.reset(),
                });
              }}
            >
              <Field label="Merchant ID" error={payfast.errors.merchant_id}>
                <Input
                  inputMode="numeric"
                  autoComplete="off"
                  value={payfast.data.merchant_id}
                  onChange={(e) => payfast.setData('merchant_id', e.target.value)}
                />
              </Field>
              <Field label="Merchant key" error={payfast.errors.merchant_key}>
                <Input
                  type="password"
                  autoComplete="off"
                  value={payfast.data.merchant_key}
                  onChange={(e) => payfast.setData('merchant_key', e.target.value)}
                />
              </Field>
              <Field label="Passphrase" error={payfast.errors.passphrase}>
                <Input
                  type="password"
                  autoComplete="off"
                  value={payfast.data.passphrase}
                  onChange={(e) => payfast.setData('passphrase', e.target.value)}
                />
              </Field>
              <Field label="Mode" error={payfast.errors.mode}>
                <select
                  className={selectClass}
                  value={payfast.data.mode}
                  onChange={(e) => payfast.setData('mode', e.target.value)}
                >
                  <option value="test">Test (sandbox) — no real money</option>
                  <option value="live">Live</option>
                </select>
              </Field>
              <Button type="submit" className="justify-self-start" loading={payfast.processing}>
                Connect PayFast
              </Button>
            </form>
          </CardContent>
        </Card>
      </div>
    </AppShell>
  );
}
