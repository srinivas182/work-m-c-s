import { useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button, Field, Input, Textarea } from '@/design-system';
import { Site, type SiteProps } from '../components/Site';

export default function Contact(props: SiteProps) {
  const { flash } = usePage<{ flash: { status: string | null } }>().props;
  const form = useForm({ name: '', contact: '', business: '', message: '', website: '' });
  const submit = (e: FormEvent) => {
    e.preventDefault();
    form.post('/contact', { preserveScroll: true, onSuccess: () => form.reset() });
  };

  return (
    <Site {...props} title="Contact" description="Talk to WURK about getting your business online.">
      <section className="mx-auto grid max-w-6xl gap-12 px-5 pt-10 sm:px-8 lg:grid-cols-[1fr_1.2fr]">
        <div>
          <h1 className="font-display text-4xl font-extrabold tracking-[-0.03em] sm:text-5xl">
            Talk to us
          </h1>
          <p className="mt-4 max-w-md text-lg text-[#1B1640]/75">
            Questions about packages, moving from another system, or setting up several branches? We
            reply within one working day.
          </p>
        </div>
        <form
          onSubmit={submit}
          className="grid gap-5 rounded-[22px] bg-white p-7 ring-1 ring-[#1B1640]/10"
          noValidate
        >
          {flash.status && (
            <p
              role="status"
              className="rounded-xl bg-[#E8F7EE] px-4 py-3 text-[15px] text-[#0F5132]"
            >
              {flash.status}
            </p>
          )}
          <Field label="Your name" error={form.errors.name} required>
            <Input
              value={form.data.name}
              onChange={(e) => form.setData('name', e.target.value)}
              autoComplete="name"
            />
          </Field>
          <Field label="Email or mobile number" error={form.errors.contact} required>
            <Input
              value={form.data.contact}
              onChange={(e) => form.setData('contact', e.target.value)}
              autoComplete="email"
            />
          </Field>
          <Field label="Business name" error={form.errors.business}>
            <Input
              value={form.data.business}
              onChange={(e) => form.setData('business', e.target.value)}
              autoComplete="organization"
            />
          </Field>
          <Field label="How can we help?" error={form.errors.message} required>
            <Textarea
              rows={5}
              value={form.data.message}
              onChange={(e) => form.setData('message', e.target.value)}
            />
          </Field>
          <div aria-hidden className="absolute -left-[9999px]">
            <label htmlFor="website">Website</label>
            <input
              id="website"
              tabIndex={-1}
              autoComplete="off"
              value={form.data.website}
              onChange={(e) => form.setData('website', e.target.value)}
            />
          </div>
          <Button type="submit" size="lg" loading={form.processing} className="w-fit">
            Send message
          </Button>
        </form>
      </section>
    </Site>
  );
}
