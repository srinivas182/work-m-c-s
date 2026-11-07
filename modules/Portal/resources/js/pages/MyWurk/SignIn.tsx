import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Button, Field, Input } from '@/design-system';
import { MyWurkLayout } from '../../components/MyWurkLayout';

export default function MyWurkSignIn({ sent }: { sent: string | null }) {
  const ask = useForm({ contact: '' });
  const verify = useForm({ code: '' });
  const pwd = useForm({ identifier: '', password: '' });
  const [withPassword, setWithPassword] = useState(false);
  return (
    <MyWurkLayout title="Your businesses, in one place">
      <p className="text-fg-muted text-sm">
        Sign in with your WURK account to see your rewards, orders and bookings with every business
        you use on WURK.
      </p>
      {withPassword ? (
        <form
          aria-label="Sign in with your password"
          className="grid gap-3"
          onSubmit={(e) => {
            e.preventDefault();
            pwd.post('/password');
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
      ) : !sent ? (
        <form
          aria-label="Get a sign-in code"
          className="grid gap-3"
          onSubmit={(e) => {
            e.preventDefault();
            ask.post('/code');
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
            verify.post('/verify');
          }}
        >
          <Field label={`Code sent to ${sent}`} error={verify.errors.code}>
            <Input
              inputMode="numeric"
              autoComplete="one-time-code"
              value={verify.data.code}
              onChange={(e) => verify.setData('code', e.target.value)}
            />
          </Field>
          <Button type="submit" className="justify-self-start" loading={verify.processing}>
            Sign in
          </Button>
        </form>
      )}
      <Button
        variant="ghost"
        size="sm"
        className="justify-self-start"
        onClick={() => setWithPassword(!withPassword)}
      >
        {withPassword ? 'Use a code instead' : 'Use my password instead'}
      </Button>
    </MyWurkLayout>
  );
}
