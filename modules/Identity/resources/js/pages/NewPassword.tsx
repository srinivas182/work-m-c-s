import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button } from '@/design-system/components/button';
import { Field } from '@/design-system/components/field';
import { Input } from '@/design-system/components/input';
import AuthLayout from '@/layouts/AuthLayout';
import { __ } from '@/i18n';

export default function NewPassword() {
  const form = useForm({ password: '', password_confirmation: '' });
  const submit = (e: FormEvent) => {
    e.preventDefault();
    form.post('/forgot-password/new');
  };

  return (
    <AuthLayout
      title={__('Choose a new password')}
      description={__(
        'At least 10 characters. A short phrase is easy to remember and hard to guess.',
      )}
    >
      <form onSubmit={submit} className="grid gap-5" noValidate>
        <Field label={__('New password')} error={form.errors.password} required>
          <Input
            type="password"
            value={form.data.password}
            onChange={(e) => form.setData('password', e.target.value)}
            autoComplete="new-password"
            autoFocus
            className="h-12 text-base"
          />
        </Field>
        <Field label={__('Confirm new password')} required>
          <Input
            type="password"
            value={form.data.password_confirmation}
            onChange={(e) => form.setData('password_confirmation', e.target.value)}
            autoComplete="new-password"
            className="h-12 text-base"
          />
        </Field>
        <Button type="submit" size="lg" loading={form.processing} className="w-full">
          {__('Save password')}
        </Button>
      </form>
    </AuthLayout>
  );
}
