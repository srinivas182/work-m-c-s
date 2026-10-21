import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button, Field, Input } from '@/design-system';
import AuthLayout from '@/layouts/AuthLayout';

export default function NewPassword() {
  const form = useForm({ password: '', password_confirmation: '' });
  const submit = (e: FormEvent) => {
    e.preventDefault();
    form.post('/forgot-password/new');
  };

  return (
    <AuthLayout
      title="Choose a new password"
      description="At least 10 characters. A short phrase is easy to remember and hard to guess."
    >
      <form onSubmit={submit} className="grid gap-5" noValidate>
        <Field label="New password" error={form.errors.password} required>
          <Input
            type="password"
            value={form.data.password}
            onChange={(e) => form.setData('password', e.target.value)}
            autoComplete="new-password"
            autoFocus
            className="h-12 text-base"
          />
        </Field>
        <Field label="Confirm new password" required>
          <Input
            type="password"
            value={form.data.password_confirmation}
            onChange={(e) => form.setData('password_confirmation', e.target.value)}
            autoComplete="new-password"
            className="h-12 text-base"
          />
        </Field>
        <Button type="submit" size="lg" loading={form.processing} className="w-full">
          Save password
        </Button>
      </form>
    </AuthLayout>
  );
}
