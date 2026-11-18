import { Link, router, useForm } from '@inertiajs/react';
import { useEffect, useState, type FormEvent } from 'react';
import { Button } from '@/design-system/components/button';
import { OtpInput } from '@/design-system/components/otp-input';
import AuthLayout from '@/layouts/AuthLayout';
import { __ } from '@/i18n';

interface Props {
  destination: string;
  purpose: 'login' | 'reset';
  verifyUrl: string;
  resendUrl: string;
  identifier: string;
  resendAfter: number;
}

export default function Code({
  destination,
  purpose,
  verifyUrl,
  resendUrl,
  identifier,
  resendAfter,
}: Props) {
  const form = useForm({ code: '' });
  const [wait, setWait] = useState(resendAfter);

  useEffect(() => {
    if (wait <= 0) return;
    const t = setTimeout(() => setWait((w) => w - 1), 1000);
    return () => clearTimeout(t);
  }, [wait]);

  const verify = (code = form.data.code) => {
    if (code.length !== 6) {
      return;
    }
    form.transform(() => ({ code }));
    form.post(verifyUrl, { preserveScroll: true });
  };

  const submit = (e: FormEvent) => {
    e.preventDefault();
    verify();
  };

  const resend = () => {
    router.post(resendUrl, { identifier }, { onSuccess: () => setWait(resendAfter) });
    form.setData('code', '');
  };

  const back = purpose === 'reset' ? '/forgot-password' : '/sign-in';

  return (
    <AuthLayout
      title={__('Enter your code')}
      description={
        <>
          {__('We sent a 6-digit code to')}{' '}
          <strong className="text-fg font-semibold">{destination}</strong>
          {__('. It expires in 5 minutes.')}
        </>
      }
      footer={
        <Link href={back} className="text-accent-fg font-medium hover:underline">
          {__('Use a different email or number')}
        </Link>
      }
    >
      <form onSubmit={submit} className="grid gap-5" noValidate>
        <OtpInput
          value={form.data.code}
          onChange={(v) => form.setData('code', v)}
          onComplete={verify}
          invalid={Boolean(form.errors.code)}
          describedBy={form.errors.code ? 'code-error' : undefined}
          disabled={form.processing}
          autoFocus
        />
        {form.errors.code && (
          <p id="code-error" className="text-danger-11 text-sm" role="alert">
            {form.errors.code}
          </p>
        )}
        <Button
          type="submit"
          size="lg"
          loading={form.processing}
          disabled={form.data.code.length !== 6}
          className="w-full"
        >
          {purpose === 'reset' ? 'Continue' : 'Sign in'}
        </Button>
        <p className="text-fg-muted text-center text-sm" aria-live="polite">
          {__('Didn’t get it?')}{' '}
          {wait > 0 ? (
            <span>
              {__('You can send a new code in')} {wait}
              {__('s')}
            </span>
          ) : (
            <button
              type="button"
              onClick={resend}
              className="text-accent-fg cursor-pointer font-medium hover:underline"
            >
              {__('Send a new code')}
            </button>
          )}
        </p>
      </form>
    </AuthLayout>
  );
}
