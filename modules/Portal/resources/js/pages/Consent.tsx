import { useForm } from '@inertiajs/react';
import { Button, Checkbox } from '@/design-system';
import { PortalLayout } from '../components/PortalLayout';

/** POPIA: what the business keeps and why, agreed once before the portal opens. */
export default function Consent({ business }: { business: string }) {
  const form = useForm({ agree: false });
  return (
    <PortalLayout business={business} title="Before you continue" signedIn>
      <div className="grid gap-2 text-sm">
        <p>
          {business} keeps your name, contact details, purchases, bookings and rewards to run your
          account and its rewards programme.
        </p>
        <p>
          You can ask for a copy of your information, or for it to be deleted, from your account at
          any time.
        </p>
      </div>
      <form
        aria-label="Agree to continue"
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
          I agree to {business} keeping my information for my account.
        </label>
        {form.errors.agree && (
          <p role="alert" className="text-danger text-sm">
            {form.errors.agree}
          </p>
        )}
        <Button type="submit" className="justify-self-start" loading={form.processing}>
          Continue
        </Button>
      </form>
    </PortalLayout>
  );
}
