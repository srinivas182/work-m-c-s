import { useForm, usePage } from '@inertiajs/react';
import { Button, Field, Input } from '@/design-system';
import { PortalLayout } from '../components/PortalLayout';

export default function SignIn({ business, sent }: { business: string; sent: string | null }) {
  const errors = (usePage().props.errors ?? {}) as Record<string, string | undefined>;
  const ask = useForm({ contact: '' });
  const verify = useForm({ code: '', name: '' });
  return (
    <PortalLayout business={business} title="Your account">
      <p className="text-fg-muted text-sm">
        Sign in to see your rewards, referral link, orders and bookings with {business}.
      </p>
      {!sent ? (
        <form
          aria-label="Get a sign-in code"
          className="grid gap-3"
          onSubmit={(e) => {
            e.preventDefault();
            ask.post('/account/code');
          }}
        >
          <Field label="Phone number or email" error={ask.errors.contact}>
            <Input
              autoComplete="username"
              value={ask.data.contact}
              onChange={(e) => ask.setData('contact', e.target.value)}
            />
          </Field>
          <Button type="submit" className="justify-self-start" loading={ask.processing}>
            Send me a code
          </Button>
        </form>
      ) : (
        <form
          aria-label="Enter your code"
          className="grid gap-3"
          onSubmit={(e) => {
            e.preventDefault();
            verify.post('/account/verify');
          }}
        >
          <Field label={`Code sent to ${sent}`} error={verify.errors.code ?? errors.code}>
            <Input
              inputMode="numeric"
              autoComplete="one-time-code"
              value={verify.data.code}
              onChange={(e) => verify.setData('code', e.target.value)}
            />
          </Field>
          {(errors.name || verify.errors.name) && (
            <Field label="Your name" error={verify.errors.name ?? errors.name}>
              <Input
                autoComplete="name"
                value={verify.data.name}
                onChange={(e) => verify.setData('name', e.target.value)}
              />
            </Field>
          )}
          <Button type="submit" className="justify-self-start" loading={verify.processing}>
            Sign in
          </Button>
        </form>
      )}
    </PortalLayout>
  );
}
