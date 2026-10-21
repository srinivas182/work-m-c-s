import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button, OtpInput } from '@/design-system';
import AuthLayout from '@/layouts/AuthLayout';

interface Props {
  qr: string;
  secret: string;
  account: string;
}

/** WURK staff must set up an authenticator app before their first sign-in completes. */
export default function MfaSetup({ qr, secret, account }: Props) {
  const form = useForm({ code: '' });
  const submit = (e: FormEvent) => {
    e.preventDefault();
    form.post('/sign-in/mfa/setup');
  };

  return (
    <AuthLayout
      title="Protect your account"
      description="WURK staff accounts need two-step verification. It takes about a minute."
    >
      <MfaEnrolment qr={qr} secret={secret} account={account} />
      <form onSubmit={submit} className="mt-6 grid gap-4" noValidate>
        <p className="text-sm font-medium">3. Enter the 6-digit code from the app</p>
        <OtpInput
          value={form.data.code}
          onChange={(v) => form.setData('code', v)}
          invalid={Boolean(form.errors.code)}
          describedBy={form.errors.code ? 'setup-error' : undefined}
        />
        {form.errors.code && (
          <p id="setup-error" role="alert" className="text-danger-11 text-sm">
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
          Turn on and continue
        </Button>
      </form>
    </AuthLayout>
  );
}

export function MfaEnrolment({ qr, secret, account }: Props) {
  return (
    <ol className="grid gap-5 text-sm">
      <li>
        <p className="font-medium">1. Install an authenticator app</p>
        <p className="text-fg-muted mt-1">
          Google Authenticator, Microsoft Authenticator or 1Password all work.
        </p>
      </li>
      <li>
        <p className="font-medium">2. Scan this QR code</p>
        <div className="border-border mt-3 flex flex-col items-center gap-3 rounded-2xl border bg-white p-5 sm:flex-row sm:items-start">
          {/* Server-generated SVG of the otpauth URI. */}
          <div
            className="size-44 shrink-0 [&_svg]:size-full"
            role="img"
            aria-label={`QR code for ${account}`}
            dangerouslySetInnerHTML={{ __html: qr }}
          />
          <div className="text-neutral-12">
            <p className="text-neutral-11 text-xs">Can’t scan? Enter this key instead:</p>
            <code className="mt-1 block font-mono text-sm font-semibold tracking-wider break-all">
              {secret}
            </code>
          </div>
        </div>
      </li>
    </ol>
  );
}
