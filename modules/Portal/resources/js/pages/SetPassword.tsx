import { useForm } from '@inertiajs/react';
import { Button } from '@/design-system/components/button';
import { Field } from '@/design-system/components/field';
import { Input } from '@/design-system/components/input';
import { PortalLayout } from '../components/PortalLayout';
import { __ } from '@/i18n';

/** After signing in with a default password: choose your own. */
export default function SetPassword({ business }: { business: string }) {
  const form = useForm({ password: '', password_confirmation: '' });
  return (
    <PortalLayout business={business} title={__('Choose your password')} signedIn>
      <p className="text-sm">
        {__(
          'The password you were sent works only once. Choose your own — you’ll use it with every business on WURK.',
        )}
      </p>
      <form
        aria-label={__('Choose your password')}
        className="grid gap-3"
        onSubmit={(e) => {
          e.preventDefault();
          form.post('/account/set-password');
        }}
      >
        <Field label={__('New password')} error={form.errors.password}>
          <Input
            type="password"
            autoComplete="new-password"
            value={form.data.password}
            onChange={(e) => form.setData('password', e.target.value)}
          />
        </Field>
        <Field label={__('Type it again')}>
          <Input
            type="password"
            autoComplete="new-password"
            value={form.data.password_confirmation}
            onChange={(e) => form.setData('password_confirmation', e.target.value)}
          />
        </Field>
        <Button type="submit" className="justify-self-start" loading={form.processing}>
          {__('Save my password')}
        </Button>
      </form>
    </PortalLayout>
  );
}
