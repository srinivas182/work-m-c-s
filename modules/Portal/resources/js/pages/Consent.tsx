import { useForm } from '@inertiajs/react';
import { Button } from '@/design-system/components/button';
import { Checkbox } from '@/design-system/components/choice';
import { PortalLayout } from '../components/PortalLayout';
import { __ } from '@/i18n';

/** POPIA: what the business keeps and why, agreed once before the portal opens. */
export default function Consent({ business }: { business: string }) {
  const form = useForm({ agree: false });
  return (
    <PortalLayout business={business} title={__('Before you continue')} signedIn>
      <div className="grid gap-2 text-sm">
        <p>
          {business}{' '}
          {__(
            'keeps your name, contact details, purchases, bookings and rewards to run your account and its rewards programme.',
          )}
        </p>
        <p>
          {__(
            'You can ask for a copy of your information, or for it to be deleted, from your account at any time.',
          )}
        </p>
      </div>
      <form
        aria-label={__('Agree to continue')}
        className="grid gap-3"
        onSubmit={(e) => {
          e.preventDefault();
          form.post('/account/consent');
        }}
      >
        <label className="flex items-start gap-2 text-sm">
          <Checkbox
            checked={form.data.agree}
            onCheckedChange={(on) => form.setData('agree', on === true)}
          />
          {__('I agree to')} {business} {__('keeping my information for my account.')}
        </label>
        {form.errors.agree && (
          <p role="alert" className="text-danger text-sm">
            {form.errors.agree}
          </p>
        )}
        <Button type="submit" className="justify-self-start" loading={form.processing}>
          {__('Continue')}
        </Button>
      </form>
    </PortalLayout>
  );
}
