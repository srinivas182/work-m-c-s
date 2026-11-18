import { Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button } from '@/design-system/components/button';
import { Field } from '@/design-system/components/field';
import { Input } from '@/design-system/components/input';
import AuthLayout from '@/layouts/AuthLayout';
import { __ } from '@/i18n';

export default function ForgotPassword({ identifier }: { identifier: string }) {
  const form = useForm({ identifier });
  const submit = (e: FormEvent) => {
    e.preventDefault();
    form.post('/forgot-password');
  };

  return (
    <AuthLayout
      title={__('Reset your password')}
      description={__('We’ll send a code to confirm it’s you, then you can choose a new password.')}
      footer={
        <Link href="/sign-in" className="text-accent-fg font-medium hover:underline">
          {__('Back to sign in')}
        </Link>
      }
    >
      <form onSubmit={submit} className="grid gap-5" noValidate>
        <Field label={__('Email or mobile number')} error={form.errors.identifier} required>
          <Input
            value={form.data.identifier}
            onChange={(e) => form.setData('identifier', e.target.value)}
            autoComplete="username"
            autoFocus
            className="h-12 text-base"
          />
        </Field>
        <Button type="submit" size="lg" loading={form.processing} className="w-full">
          {__('Send code')}
        </Button>
      </form>
    </AuthLayout>
  );
}
