import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/design-system/components/button';
import { Field } from '@/design-system/components/field';
import { Input } from '@/design-system/components/input';
import { MyWurkLayout } from '../../components/MyWurkLayout';
import { __ } from '@/i18n';

export default function MyWurkSignIn({ sent }: { sent: string | null }) {
  const ask = useForm({ contact: '' });
  const verify = useForm({ code: '' });
  const pwd = useForm({ identifier: '', password: '' });
  const [withPassword, setWithPassword] = useState(false);
  return (
    <MyWurkLayout title={__('Your businesses, in one place')}>
      <p className="text-fg-muted text-sm">
        {__(
          'Sign in with your WURK account to see your rewards, orders and bookings with every business you use on WURK.',
        )}
      </p>
      {withPassword ? (
        <form
          aria-label={__('Sign in with your password')}
          className="grid gap-3"
          onSubmit={(e) => {
            e.preventDefault();
            pwd.post('/password');
          }}
        >
          <Field label={__('Phone number or email')} error={pwd.errors.identifier}>
            <Input
              autoComplete="username"
              value={pwd.data.identifier}
              onChange={(e) => pwd.setData('identifier', e.target.value)}
            />
          </Field>
          <Field label={__('Password')} error={pwd.errors.password}>
            <Input
              type="password"
              autoComplete="current-password"
              value={pwd.data.password}
              onChange={(e) => pwd.setData('password', e.target.value)}
            />
          </Field>
          <Button type="submit" className="justify-self-start" loading={pwd.processing}>
            {__('Sign in')}
          </Button>
        </form>
      ) : !sent ? (
        <form
          aria-label={__('Get a sign-in code')}
          className="grid gap-3"
          onSubmit={(e) => {
            e.preventDefault();
            ask.post('/code');
          }}
        >
          <Field label={__('Phone number or email')} error={ask.errors.contact}>
            <Input
              autoComplete="username"
              value={ask.data.contact}
              onChange={(e) => ask.setData('contact', e.target.value)}
            />
          </Field>
          <Button type="submit" className="justify-self-start" loading={ask.processing}>
            {__('Send me a code')}
          </Button>
        </form>
      ) : (
        <form
          aria-label={__('Enter your code')}
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
            {__('Sign in')}
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
