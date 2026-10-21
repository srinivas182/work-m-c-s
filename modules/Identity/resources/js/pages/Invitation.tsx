import { Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button, Field, Input } from '@/design-system';
import AuthLayout from '@/layouts/AuthLayout';

interface Props {
  valid: boolean;
  joining: string | null;
  role: string | null;
  email: string | null;
  needsName: boolean;
  firstName: string | null;
  lastName: string | null;
}

export default function Invitation({
  valid,
  joining,
  role,
  email,
  needsName,
  firstName,
  lastName,
}: Props) {
  const form = useForm({ first_name: firstName ?? '', last_name: lastName ?? '' });

  if (!valid) {
    return (
      <AuthLayout
        title="This invitation can’t be used"
        description="It may have expired, been used already, or been replaced by a newer invitation. Ask the person who invited you to send a new one."
        footer={
          <Link href="/sign-in" className="text-accent-fg font-medium hover:underline">
            Go to sign in
          </Link>
        }
      >
        <span />
      </AuthLayout>
    );
  }

  const submit = (e: FormEvent) => {
    e.preventDefault();
    form.post(window.location.pathname);
  };

  return (
    <AuthLayout
      title={`Join ${joining}`}
      description={
        <>
          You’re invited as{' '}
          <strong className="text-fg font-semibold">{role?.replace('-', ' ')}</strong> with{' '}
          <strong className="text-fg font-semibold">{email}</strong>.
        </>
      }
    >
      <form onSubmit={submit} className="grid gap-5" noValidate>
        {needsName && (
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Field label="First name" error={form.errors.first_name} required>
              <Input
                value={form.data.first_name}
                onChange={(e) => form.setData('first_name', e.target.value)}
                autoComplete="given-name"
                autoFocus
                className="h-12 text-base"
              />
            </Field>
            <Field label="Last name" error={form.errors.last_name}>
              <Input
                value={form.data.last_name}
                onChange={(e) => form.setData('last_name', e.target.value)}
                autoComplete="family-name"
                className="h-12 text-base"
              />
            </Field>
          </div>
        )}
        <Button type="submit" size="lg" loading={form.processing} className="w-full">
          Accept and continue
        </Button>
      </form>
    </AuthLayout>
  );
}
