import { useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Button, Field, Input } from '@/design-system';
import { PortalLayout } from '../components/PortalLayout';

export default function SignIn({
  business,
  sent,
  passwords,
}: {
  business: string;
  sent: string | null;
  passwords: boolean;
}) {
  const [withPassword, setWithPassword] = useState(false);
  const pwd = useForm({ identifier: '', password: '' });
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
      {passwords && !sent && (
        <>
          {!withPassword ? (
            <Button
              variant="ghost"
              size="sm"
              className="justify-self-start"
              onClick={() => setWithPassword(true)}
            >
              Sign in with a password instead
            </Button>
          ) : (
            <form
              aria-label="Sign in with a password"
              className="grid gap-3"
              onSubmit={(e) => {
                e.preventDefault();
                pwd.post('/account/password');
              }}
            >
              <Field label="Phone number or email" error={pwd.errors.identifier}>
                <Input
                  autoComplete="username"
                  value={pwd.data.identifier}
                  onChange={(e) => pwd.setData('identifier', e.target.value)}
                />
              </Field>
              <Field label="Password" error={pwd.errors.password}>
                <Input
                  type="password"
                  autoComplete="current-password"
                  value={pwd.data.password}
                  onChange={(e) => pwd.setData('password', e.target.value)}
                />
              </Field>
              <Button type="submit" className="justify-self-start" loading={pwd.processing}>
                Sign in
              </Button>
            </form>
          )}
        </>
      )}
    </PortalLayout>
  );
}
