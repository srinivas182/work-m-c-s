import { Head, useForm } from '@inertiajs/react';
import { Check, X } from 'lucide-react';
import { useEffect, useState, type FormEvent } from 'react';
import { Button } from '@/design-system/components/button';
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/design-system/components/card';
import { Field } from '@/design-system/components/field';
import { Input } from '@/design-system/components/input';
import { PageHeader } from '@/design-system/components/feedback';
import AppShell from '@/layouts/AppShell';
import { __ } from '@/i18n';

const slug = (s: string) =>
  s
    .toLowerCase()
    .normalize('NFKD')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 40);

export default function CreateBusiness({ rootDomain }: { rootDomain: string }) {
  const form = useForm({
    name: '',
    handle: '',
    country: 'ZA',
    owner_first_name: '',
    owner_last_name: '',
    owner_contact: '',
  });
  const [touchedHandle, setTouchedHandle] = useState(false);
  const [check, setCheck] = useState<{ available: boolean; problem: string | null } | null>(null);

  useEffect(() => {
    if (!form.data.handle) return;
    const t = setTimeout(async () => {
      const r = await fetch(
        `/businesses/check-handle?handle=${encodeURIComponent(form.data.handle)}`,
        { headers: { Accept: 'application/json' } },
      );
      if (r.ok) setCheck(await r.json());
    }, 300);
    return () => clearTimeout(t);
  }, [form.data.handle]);

  // Only show availability for the handle currently typed.
  const shown = form.data.handle ? check : null;

  const submit = (e: FormEvent) => {
    e.preventDefault();
    form.post('/businesses');
  };

  return (
    <AppShell title={__('New business')}>
      <Head title={__('New business')} />
      <div className="grid max-w-3xl gap-8">
        <PageHeader
          title={__('New business')}
          description={__(
            'For businesses signing up with help from WURK. Self-service signup arrives in Sprint 3.',
          )}
        />
        <form onSubmit={submit} className="grid gap-6" noValidate>
          <Card>
            <CardHeader>
              <CardTitle>{__('Business')}</CardTitle>
            </CardHeader>
            <CardContent className="grid grid-cols-1 gap-5 sm:grid-cols-2">
              <Field
                label={__('Business name')}
                error={form.errors.name}
                required
                className="sm:col-span-2"
              >
                <Input
                  value={form.data.name}
                  onChange={(e) => {
                    form.setData((d) => ({
                      ...d,
                      name: e.target.value,
                      handle: touchedHandle ? d.handle : slug(e.target.value),
                    }));
                  }}
                  placeholder={__('Glow Salon')}
                />
              </Field>
              <Field
                label={__('Web address')}
                error={
                  form.errors.handle ??
                  (shown && !shown.available ? (shown.problem ?? undefined) : undefined)
                }
                hint={`${form.data.handle || 'your-business'}.${rootDomain}`}
                required
                className="sm:col-span-2"
              >
                {(control) => (
                  <div className="relative">
                    <Input
                      {...control}
                      value={form.data.handle}
                      onChange={(e) => {
                        setTouchedHandle(true);
                        form.setData('handle', e.target.value.toLowerCase());
                      }}
                      className="pr-10"
                    />
                    {shown && (
                      <span
                        className="absolute inset-y-0 right-3 grid place-items-center"
                        aria-live="polite"
                      >
                        {shown.available ? (
                          <Check className="text-success-11 size-4" aria-label={__('Available')} />
                        ) : (
                          <X className="text-danger-11 size-4" aria-label={__('Not available')} />
                        )}
                      </span>
                    )}
                  </div>
                )}
              </Field>
            </CardContent>
          </Card>
          <Card>
            <CardHeader>
              <CardTitle>{__('Owner')}</CardTitle>
              <CardDescription>
                {__(
                  'We’ll send them an invitation by email or SMS. They sign in and land straight in their business.',
                )}
              </CardDescription>
            </CardHeader>
            <CardContent className="grid grid-cols-1 gap-5 sm:grid-cols-2">
              <Field label={__('First name')} error={form.errors.owner_first_name} required>
                <Input
                  value={form.data.owner_first_name}
                  onChange={(e) => form.setData('owner_first_name', e.target.value)}
                />
              </Field>
              <Field label={__('Last name')} error={form.errors.owner_last_name}>
                <Input
                  value={form.data.owner_last_name}
                  onChange={(e) => form.setData('owner_last_name', e.target.value)}
                />
              </Field>
              <Field
                label={__('Email or mobile number')}
                error={form.errors.owner_contact}
                hint={__('Mobile numbers get the invitation by SMS')}
                required
                className="sm:col-span-2"
              >
                <Input
                  value={form.data.owner_contact}
                  onChange={(e) => form.setData('owner_contact', e.target.value)}
                />
              </Field>
            </CardContent>
          </Card>
          <div className="flex gap-3">
            <Button type="submit" size="lg" loading={form.processing}>
              {__('Create business and invite owner')}
            </Button>
          </div>
        </form>
      </div>
    </AppShell>
  );
}
