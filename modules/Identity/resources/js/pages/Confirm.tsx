import { Link, router, useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';
import { Button, Field, Input, OtpInput } from '@/design-system';
import AuthLayout from '@/layouts/AuthLayout';

interface Props {
  to: string;
  hasPassword: boolean;
  destination: string | null;
  codeSent: boolean;
}

/**
 * "Confirm it's you" (Sprint 18.2b): before something sensitive — like downloading all of a business's data —
 * the person confirms with their password or a fresh code. Valid for 15 minutes.
 */
export default function Confirm({ to, hasPassword, destination, codeSent }: Props) {
  const password = useForm({ password: '', to });
  const code = useForm({ code: '', to });

  const submitPassword = (e: FormEvent) => {
    e.preventDefault();
    password.post('/account/confirm/password', { onFinish: () => password.reset('password') });
  };
  const verify = (value = code.data.code) => {
    if (value.length !== 6) return;
    code.transform(() => ({ code: value, to }));
    code.post('/account/confirm/code/verify', { preserveScroll: true });
  };
  const send = () => router.post('/account/confirm/code', {}, { preserveScroll: true });

  return (
    <AuthLayout
      title="Confirm it’s you"
      description="This needs a quick check that it’s really you. It lasts 15 minutes."
      footer={
        <Link href={to} className="text-accent-fg font-medium hover:underline">
          Go back
        </Link>
      }
    >
      <div className="grid gap-8">
        {hasPassword && (
          <form
            onSubmit={submitPassword}
            className="grid gap-4"
            aria-label="Confirm with your password"
            noValidate
          >
            <Field label="Your password" error={password.errors.password} required>
              <Input
                type="password"
                value={password.data.password}
                onChange={(e) => password.setData('password', e.target.value)}
                autoComplete="current-password"
                autoFocus
                className="h-12 text-base"
              />
            </Field>
            <Button type="submit" size="lg" loading={password.processing} className="w-full">
              Confirm
            </Button>
          </form>
        )}
        {destination && (
          <div className="grid gap-4">
            {!codeSent ? (
              <Button
                type="button"
                variant={hasPassword ? 'secondary' : 'primary'}
                size="lg"
                onClick={send}
              >
                {hasPassword ? 'Or send me a code' : 'Send me a code'}
              </Button>
            ) : (
              <form
                onSubmit={(e) => {
                  e.preventDefault();
                  verify();
                }}
                className="grid gap-4"
                aria-label="Confirm with a code"
                noValidate
              >
                <p className="text-fg-muted text-sm">
                  We sent a 6-digit code to <strong className="text-fg">{destination}</strong>.
                </p>
                <OtpInput
                  value={code.data.code}
                  onChange={(v) => code.setData('code', v)}
                  onComplete={verify}
                  invalid={Boolean(code.errors.code)}
                  describedBy={code.errors.code ? 'confirm-code-error' : undefined}
                  disabled={code.processing}
                  autoFocus={!hasPassword}
                />
                {code.errors.code && (
                  <p id="confirm-code-error" className="text-danger-11 text-sm" role="alert">
                    {code.errors.code}
                  </p>
                )}
                <Button
                  type="submit"
                  size="lg"
                  loading={code.processing}
                  disabled={code.data.code.length !== 6}
                >
                  Confirm
                </Button>
                <button
                  type="button"
                  onClick={send}
                  className="text-accent-fg cursor-pointer text-sm font-medium hover:underline"
                >
                  Send a new code
                </button>
              </form>
            )}
          </div>
        )}
      </div>
    </AuthLayout>
  );
}
