import { Link, useForm } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import type { FormEvent } from 'react';
import { Button, Checkbox, Field, Input, Label } from '@/design-system';
import AuthLayout from '@/layouts/AuthLayout';

interface Props {
  surface: 'admin' | 'app';
  identifier: string;
}

export default function SignIn({ surface, identifier }: Props) {
  const form = useForm({ identifier, remember: true });
  const staff = surface === 'admin';

  const submit = (e: FormEvent) => {
    e.preventDefault();
    form.post('/sign-in/code');
  };

  return (
    <AuthLayout
      title={staff ? 'WURK staff sign in' : 'Sign in to WURK'}
      description="We’ll send a 6-digit code to your email or phone. No password needed."
      footer={
        <>
          Prefer a password?{' '}
          <Link href="/sign-in/password" className="text-accent-fg font-medium hover:underline">
            Sign in with your password
          </Link>
        </>
      }
    >
      <form onSubmit={submit} className="grid gap-5" noValidate>
        <Field label="Email or mobile number" error={form.errors.identifier} required>
          <Input
            value={form.data.identifier}
            onChange={(e) => form.setData('identifier', e.target.value)}
            autoComplete="username"
            autoFocus
            placeholder="you@business.co.za or 082 123 4567"
            className="h-12 text-base"
          />
        </Field>
        {!staff && (
          <div className="flex items-center gap-3">
            <Checkbox
              id="remember"
              checked={form.data.remember}
              onCheckedChange={(v) => form.setData('remember', v === true)}
            />
            <Label htmlFor="remember" className="text-fg-muted font-normal">
              Keep me signed in on this device for 30 days
            </Label>
          </div>
        )}
        <Button type="submit" size="lg" loading={form.processing} className="w-full">
          Send me a code <ArrowRight />
        </Button>
      </form>
    </AuthLayout>
  );
}
