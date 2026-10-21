import { Link, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Button, Field, Input, OtpInput } from '@/design-system';
import AuthLayout from '@/layouts/AuthLayout';

export default function MfaChallenge() {
  const [recovery, setRecovery] = useState(false);
  const form = useForm({ code: '', recovery: false });

  const send = (code = form.data.code) => {
    form.transform(() => ({ code, recovery }));
    form.post('/sign-in/mfa', { onError: () => form.setData('code', '') });
  };

  const submit = (e: FormEvent) => {
    e.preventDefault();
    send();
  };

  return (
    <AuthLayout
      title="Two-step verification"
      description={
        recovery
          ? 'Enter one of the recovery codes you saved when you set up your authenticator app.'
          : 'Open your authenticator app and enter the 6-digit code for WURK.'
      }
      footer={
        <Link href="/sign-in" className="text-accent-fg font-medium hover:underline">
          Start again
        </Link>
      }
    >
      <form onSubmit={submit} className="grid gap-5" noValidate>
        {recovery ? (
          <Field label="Recovery code" error={form.errors.code}>
            <Input
              value={form.data.code}
              onChange={(e) => form.setData('code', e.target.value)}
              placeholder="xxxxx-xxxxx"
              autoComplete="off"
              autoFocus
              className="h-12 font-mono text-base"
            />
          </Field>
        ) : (
          <>
            <OtpInput
              value={form.data.code}
              onChange={(v) => form.setData('code', v)}
              onComplete={(v) => send(v)}
              invalid={Boolean(form.errors.code)}
              describedBy={form.errors.code ? 'mfa-error' : undefined}
              disabled={form.processing}
              autoFocus
            />
            {form.errors.code && (
              <p id="mfa-error" role="alert" className="text-danger-11 text-sm">
                {form.errors.code}
              </p>
            )}
          </>
        )}
        <Button type="submit" size="lg" loading={form.processing} className="w-full">
          Verify
        </Button>
        <button
          type="button"
          onClick={() => {
            setRecovery((r) => !r);
            form.setData('code', '');
            form.clearErrors();
          }}
          className="text-accent-fg cursor-pointer text-sm font-medium hover:underline"
        >
          {recovery ? 'Use my authenticator app' : 'Lost your phone? Use a recovery code'}
        </button>
      </form>
    </AuthLayout>
  );
}
