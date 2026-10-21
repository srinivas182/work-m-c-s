import { Head, router, useForm } from '@inertiajs/react';
import { KeyRound, Laptop, ShieldCheck, Smartphone } from 'lucide-react';
import type { FormEvent } from 'react';
import {
  Badge,
  Button,
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
  Field,
  Input,
  OtpInput,
  PageHeader,
} from '@/design-system';
import AppShell from '@/layouts/AppShell';
import { MfaEnrolment } from './MfaSetup';
import { RecoveryCodeList } from './RecoveryCodes';

interface Session {
  id: string;
  surface: string;
  device: string;
  ip: string | null;
  last_active: string;
  current: boolean;
}

interface Props {
  profile: { first_name: string; last_name: string; email: string | null; phone: string | null };
  hasPassword: boolean;
  mfaEnabled: boolean;
  mfaRequired: boolean;
  enrolment: { qr: string; secret: string; account: string } | null;
  recoveryCodes: string[] | null;
  sessions: Session[];
}

function ProfileCard({ profile }: Pick<Props, 'profile'>) {
  const form = useForm({ first_name: profile.first_name, last_name: profile.last_name });
  const submit = (e: FormEvent) => {
    e.preventDefault();
    form.put('/account/profile', { preserveScroll: true });
  };
  return (
    <Card>
      <CardHeader>
        <CardTitle>Profile</CardTitle>
        <CardDescription>
          Signed in as {profile.email ?? profile.phone}. To change your email or number, contact
          support.
        </CardDescription>
      </CardHeader>
      <CardContent>
        <form onSubmit={submit} className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Field label="First name" error={form.errors.first_name} required>
            <Input
              value={form.data.first_name}
              onChange={(e) => form.setData('first_name', e.target.value)}
              autoComplete="given-name"
            />
          </Field>
          <Field label="Last name" error={form.errors.last_name}>
            <Input
              value={form.data.last_name}
              onChange={(e) => form.setData('last_name', e.target.value)}
              autoComplete="family-name"
            />
          </Field>
          <div className="sm:col-span-2">
            <Button type="submit" loading={form.processing} disabled={!form.isDirty}>
              Save profile
            </Button>
          </div>
        </form>
      </CardContent>
    </Card>
  );
}

function PasswordCard({ hasPassword }: Pick<Props, 'hasPassword'>) {
  const form = useForm({ current_password: '', password: '', password_confirmation: '' });
  const submit = (e: FormEvent) => {
    e.preventDefault();
    form.put('/account/password', { preserveScroll: true, onSuccess: () => form.reset() });
  };
  return (
    <Card>
      <CardHeader>
        <CardTitle>Password</CardTitle>
        <CardDescription>
          {hasPassword
            ? 'Changing your password signs you out on your other devices.'
            : 'You sign in with one-time codes. Add a password if you’d like another option.'}
        </CardDescription>
      </CardHeader>
      <CardContent>
        <form onSubmit={submit} className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          {hasPassword && (
            <Field
              label="Current password"
              error={form.errors.current_password}
              className="sm:col-span-2"
            >
              <Input
                type="password"
                value={form.data.current_password}
                onChange={(e) => form.setData('current_password', e.target.value)}
                autoComplete="current-password"
              />
            </Field>
          )}
          <Field label="New password" error={form.errors.password} hint="At least 10 characters">
            <Input
              type="password"
              value={form.data.password}
              onChange={(e) => form.setData('password', e.target.value)}
              autoComplete="new-password"
            />
          </Field>
          <Field label="Confirm new password">
            <Input
              type="password"
              value={form.data.password_confirmation}
              onChange={(e) => form.setData('password_confirmation', e.target.value)}
              autoComplete="new-password"
            />
          </Field>
          <div className="sm:col-span-2">
            <Button type="submit" loading={form.processing}>
              {hasPassword ? 'Change password' : 'Add password'}
            </Button>
          </div>
        </form>
      </CardContent>
    </Card>
  );
}

function MfaCard({
  mfaEnabled,
  mfaRequired,
  enrolment,
  recoveryCodes,
}: Pick<Props, 'mfaEnabled' | 'mfaRequired' | 'enrolment' | 'recoveryCodes'>) {
  const confirm = useForm({ code: '' });
  const disable = useForm({ code: '' });

  return (
    <Card>
      <CardHeader>
        <div className="flex items-center justify-between gap-3">
          <CardTitle>Authenticator app</CardTitle>
          <Badge tone={mfaEnabled ? 'success' : 'neutral'} dot>
            {mfaEnabled ? 'On' : 'Off'}
          </Badge>
        </div>
        <CardDescription>
          A second step at sign-in using a code from an app on your phone.
        </CardDescription>
      </CardHeader>
      <CardContent className="grid gap-5">
        {recoveryCodes && (
          <div>
            <p className="mb-3 text-sm font-medium">
              Save these recovery codes — they won’t be shown again.
            </p>
            <RecoveryCodeList codes={recoveryCodes} />
          </div>
        )}
        {!mfaEnabled && !enrolment && (
          <Button
            variant="soft"
            className="w-fit"
            onClick={() => router.post('/account/mfa', {}, { preserveScroll: true })}
          >
            <ShieldCheck /> Set up authenticator app
          </Button>
        )}
        {!mfaEnabled && enrolment && (
          <form
            onSubmit={(e) => {
              e.preventDefault();
              confirm.post('/account/mfa/confirm', { preserveScroll: true });
            }}
            className="grid gap-4"
          >
            <MfaEnrolment {...enrolment} />
            <p className="text-sm font-medium">3. Enter the 6-digit code from the app</p>
            <OtpInput
              value={confirm.data.code}
              onChange={(v) => confirm.setData('code', v)}
              invalid={Boolean(confirm.errors.code)}
              describedBy="mfa-confirm-error"
            />
            {confirm.errors.code && (
              <p id="mfa-confirm-error" role="alert" className="text-danger-11 text-sm">
                {confirm.errors.code}
              </p>
            )}
            <Button type="submit" className="w-fit" loading={confirm.processing}>
              Turn on
            </Button>
          </form>
        )}
        {mfaEnabled && !mfaRequired && (
          <form
            onSubmit={(e) => {
              e.preventDefault();
              disable.delete('/account/mfa', {
                preserveScroll: true,
                onSuccess: () => disable.reset(),
              });
            }}
            className="grid gap-3 sm:max-w-sm"
          >
            <Field
              label="Code from your app to turn it off"
              error={
                disable.errors.code ?? (disable.errors as Record<string, string | undefined>).mfa
              }
            >
              <Input
                value={disable.data.code}
                onChange={(e) => disable.setData('code', e.target.value)}
                inputMode="numeric"
                autoComplete="one-time-code"
              />
            </Field>
            <Button
              type="submit"
              variant="secondary"
              className="w-fit"
              loading={disable.processing}
            >
              Turn off
            </Button>
          </form>
        )}
        {mfaEnabled && mfaRequired && (
          <p className="text-fg-muted text-sm">Required for WURK staff accounts.</p>
        )}
      </CardContent>
    </Card>
  );
}

function SessionsCard({ sessions }: Pick<Props, 'sessions'>) {
  const others = sessions.filter((s) => !s.current).length;
  return (
    <Card>
      <CardHeader>
        <CardTitle>Where you’re signed in</CardTitle>
        <CardDescription>
          If you don’t recognise a device, sign it out and change your password.
        </CardDescription>
      </CardHeader>
      <CardContent className="grid gap-4">
        <ul className="divide-border divide-y">
          {sessions.map((s) => {
            const Icon = /Android|iOS/.test(s.device) ? Smartphone : Laptop;
            return (
              <li key={s.id} className="flex items-center gap-4 py-3">
                <span className="bg-neutral-3 text-fg-muted grid size-10 shrink-0 place-items-center rounded-xl">
                  <Icon className="size-5" aria-hidden />
                </span>
                <span className="min-w-0 flex-1">
                  <span className="block font-medium">
                    {s.device}{' '}
                    {s.current && (
                      <Badge tone="success" className="ml-1">
                        This device
                      </Badge>
                    )}
                  </span>
                  <span className="text-fg-muted block truncate text-sm">
                    {s.surface === 'admin' ? 'WURK staff' : 'Business back office'} ·{' '}
                    {s.ip ?? 'Unknown IP'} · {s.last_active}
                  </span>
                </span>
              </li>
            );
          })}
        </ul>
        {others > 0 && (
          <Button
            variant="secondary"
            className="w-fit"
            onClick={() => router.delete('/account/sessions', { preserveScroll: true })}
          >
            <KeyRound /> Sign out other devices
          </Button>
        )}
      </CardContent>
    </Card>
  );
}

export default function Security(props: Props) {
  return (
    <AppShell title="Account & security">
      <Head title="Account & security" />
      <div className="grid gap-8">
        <PageHeader
          title="Account & security"
          description="Your WURK ID works across every business you belong to."
        />
        <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
          <div className="grid content-start gap-6">
            <ProfileCard profile={props.profile} />
            <PasswordCard hasPassword={props.hasPassword} />
          </div>
          <div className="grid content-start gap-6">
            <MfaCard
              mfaEnabled={props.mfaEnabled}
              mfaRequired={props.mfaRequired}
              enrolment={props.enrolment}
              recoveryCodes={props.recoveryCodes}
            />
            <SessionsCard sessions={props.sessions} />
          </div>
        </div>
      </div>
    </AppShell>
  );
}
