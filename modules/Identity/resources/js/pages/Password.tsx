import { Link, useForm } from '@inertiajs/react';
import { Eye, EyeOff } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { Button, Checkbox, Field, Input, Label } from '@/design-system';
import AuthLayout from '@/layouts/AuthLayout';

interface Props {
  identifier: string;
  surface: 'admin' | 'app';
}

export default function Password({ identifier, surface }: Props) {
  const form = useForm({ identifier, password: '', remember: true });
  const [show, setShow] = useState(false);

  const submit = (e: FormEvent) => {
    e.preventDefault();
    form.post('/sign-in/password', { onFinish: () => form.reset('password') });
  };

  return (
    <AuthLayout
      title="Sign in with password"
      footer={
        <>
          No password?{' '}
          <Link href="/sign-in" className="text-accent-fg font-medium hover:underline">
            Get a code instead
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
            autoFocus={!identifier}
            className="h-12 text-base"
          />
        </Field>
        <Field label="Password" error={form.errors.password} required>
          {(control) => (
            <div className="relative">
              <Input
                {...control}
                type={show ? 'text' : 'password'}
                value={form.data.password}
                onChange={(e) => form.setData('password', e.target.value)}
                autoComplete="current-password"
                autoFocus={Boolean(identifier)}
                className="h-12 pr-12 text-base"
              />
              <button
                type="button"
                onClick={() => setShow((s) => !s)}
                aria-label={show ? 'Hide password' : 'Show password'}
                className="text-fg-muted hover:text-fg absolute inset-y-0 right-0 grid w-12 cursor-pointer place-items-center"
              >
                {show ? (
                  <EyeOff className="size-5" aria-hidden />
                ) : (
                  <Eye className="size-5" aria-hidden />
                )}
              </button>
            </div>
          )}
        </Field>
        <div className="flex items-center justify-between gap-4">
          {surface === 'app' ? (
            <div className="flex items-center gap-3">
              <Checkbox
                id="remember"
                checked={form.data.remember}
                onCheckedChange={(v) => form.setData('remember', v === true)}
              />
              <Label htmlFor="remember" className="text-fg-muted font-normal">
                Keep me signed in
              </Label>
            </div>
          ) : (
            <span />
          )}
          <Link
            href="/forgot-password"
            className="text-accent-fg text-sm font-medium hover:underline"
          >
            Forgot password?
          </Link>
        </div>
        <Button type="submit" size="lg" loading={form.processing} className="w-full">
          Sign in
        </Button>
      </form>
    </AuthLayout>
  );
}
