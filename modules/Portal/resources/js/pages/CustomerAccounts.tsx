import { Head, useForm } from '@inertiajs/react';
import {
  Button,
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  Checkbox,
  Field,
  Input,
  PageHeader,
} from '@/design-system';
import AppShell from '@/layouts/AppShell';

/** Settings → Customer accounts: how customers sign in to their account, and sending someone their access. */
export default function CustomerAccounts({
  passwords,
  link,
}: {
  passwords: boolean;
  link: string;
}) {
  const base = window.location.pathname.replace(/\/$/, '');
  const mode = useForm({ passwords });
  const send = useForm({ customer: '', channel: 'email' });
  return (
    <AppShell title="Customer accounts">
      <Head title="Customer accounts" />
      <div className="grid max-w-3xl gap-6">
        <PageHeader
          title="Customer accounts"
          description={`Customers see their rewards, orders and bookings at ${link}.`}
        />
        <Card>
          <CardHeader>
            <CardTitle>How customers sign in</CardTitle>
          </CardHeader>
          <CardContent>
            <form
              aria-label="How customers sign in"
              className="grid gap-3 text-sm"
              onSubmit={(e) => {
                e.preventDefault();
                mode.put(base, { preserveScroll: true });
              }}
            >
              <p>A one-time code to their phone or email always works.</p>
              <label className="flex items-start gap-2">
                <Checkbox
                  checked={mode.data.passwords}
                  onCheckedChange={(on) => mode.setData('passwords', on === true)}
                />
                Also allow a password. New customers who give an email get one by email; they choose
                their own the first time they sign in.
              </label>
              <Button type="submit" className="justify-self-start" loading={mode.processing}>
                Save
              </Button>
            </form>
          </CardContent>
        </Card>
        {passwords && (
          <Card>
            <CardHeader>
              <CardTitle>Send someone their access</CardTitle>
            </CardHeader>
            <CardContent>
              <form
                aria-label="Send portal access"
                className="grid gap-3 text-sm"
                onSubmit={(e) => {
                  e.preventDefault();
                  send.post(`${base}/send`, {
                    preserveScroll: true,
                    onSuccess: () => send.reset('customer'),
                  });
                }}
              >
                <Field label="Customer’s phone number or email" error={send.errors.customer}>
                  <Input
                    value={send.data.customer}
                    onChange={(e) => send.setData('customer', e.target.value)}
                  />
                </Field>
                <fieldset className="grid gap-1">
                  <legend className="font-medium">Send by</legend>
                  <label className="flex items-center gap-2">
                    <input
                      type="radio"
                      name="channel"
                      checked={send.data.channel === 'email'}
                      onChange={() => send.setData('channel', 'email')}
                    />
                    Email
                  </label>
                  <label className="flex items-center gap-2">
                    <input
                      type="radio"
                      name="channel"
                      checked={send.data.channel === 'sms'}
                      onChange={() => send.setData('channel', 'sms')}
                    />
                    SMS (uses your SMS credits)
                  </label>
                </fieldset>
                <p className="text-fg-muted">
                  WURK makes a password that works once, for 7 days. You never see it.
                </p>
                <Button type="submit" className="justify-self-start" loading={send.processing}>
                  Send
                </Button>
              </form>
            </CardContent>
          </Card>
        )}
      </div>
    </AppShell>
  );
}
