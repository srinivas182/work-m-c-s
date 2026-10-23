import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Check, X } from 'lucide-react';
import { useEffect, useState, type FormEvent, type ReactNode } from 'react';
import { rand } from '@/lib/money';
import {
  Button,
  Field,
  Input,
  OtpInput,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/design-system';

interface Pkg {
  id: string;
  key: string;
  name: string;
  description: string;
  monthly_cents: number;
  yearly_cents: number;
  trial_days: number;
}

interface Props {
  step: 'details' | 'verify' | 'package' | 'address' | 'finish';
  steps: string[];
  data: {
    business_name: string;
    first_name: string;
    last_name: string;
    contact: string;
    country: string;
    package_id: string;
    period: 'monthly' | 'yearly';
    handle: string;
  };
  maskedContact: string | null;
  packages: Pkg[];
  countries: { code: string; name: string; vat: number }[];
  trialAvailable: boolean;
  rootDomain: string;
}

const LABELS: Record<string, string> = {
  details: 'Your details',
  verify: 'Verify',
  package: 'Package',
  address: 'Web address',
  finish: 'Finish',
};
const slug = (s: string) =>
  s
    .toLowerCase()
    .normalize('NFKD')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 40);

function Shell({
  step,
  steps,
  children,
  title,
}: {
  step: string;
  steps: string[];
  children: ReactNode;
  title: string;
}) {
  const index = steps.indexOf(step);
  const { errors, flash } = usePage<{
    errors: Record<string, string>;
    flash?: { warning?: string | null };
  }>().props;
  return (
    <div className="min-h-dvh bg-[#F7F7FB] text-[#1B1640]">
      <Head title={`Sign up — ${title}`} />
      <div className="mx-auto max-w-xl px-5 py-8 sm:py-14">
        <p className="font-display flex items-center gap-2.5 text-lg font-bold">
          <span
            aria-hidden
            className="bg-primary text-primary-fg grid size-8 place-items-center rounded-[9px] text-sm"
          >
            W
          </span>
          WURK
        </p>
        <ol aria-label="Signup progress" className="mt-8 flex gap-1.5">
          {steps.map((s, i) => (
            <li key={s} className="flex-1" aria-current={i === index ? 'step' : undefined}>
              <span
                className={`block h-1.5 rounded-full ${i <= index ? 'bg-primary' : 'bg-[#1B1640]/12'}`}
              />
              <span className="sr-only">{`Step ${i + 1} of ${steps.length}: ${LABELS[s]}${i < index ? ' (done)' : ''}`}</span>
            </li>
          ))}
        </ol>
        <p className="mt-3 text-sm text-[#1B1640]/65">
          Step {index + 1} of {steps.length}
        </p>
        <h1 className="font-display mt-1 text-3xl font-extrabold tracking-[-0.02em]">{title}</h1>
        {errors.signup && (
          <p
            role="alert"
            className="mt-4 rounded-xl bg-[#FDECEC] px-4 py-3 text-[15px] text-[#8A1C1C]"
          >
            {errors.signup}
          </p>
        )}
        {flash?.warning && (
          <p
            role="alert"
            className="mt-4 rounded-xl bg-[#FDECEC] px-4 py-3 text-[15px] text-[#8A1C1C]"
          >
            {flash?.warning}
          </p>
        )}
        <div className="mt-7 rounded-[22px] bg-white p-6 ring-1 ring-[#1B1640]/10 sm:p-8">
          {children}
        </div>
      </div>
    </div>
  );
}

function Details({ data, countries }: Pick<Props, 'data' | 'countries'>) {
  const form = useForm({
    business_name: data.business_name,
    first_name: data.first_name,
    last_name: data.last_name,
    contact: data.contact,
    country: data.country,
  });
  const submit = (e: FormEvent) => {
    e.preventDefault();
    form.post('/signup/details', { preserveScroll: true });
  };
  return (
    <form onSubmit={submit} className="grid gap-5" noValidate>
      <Field label="Business name" error={form.errors.business_name} required>
        <Input
          value={form.data.business_name}
          onChange={(e) => form.setData('business_name', e.target.value)}
          autoComplete="organization"
          autoFocus
          className="h-12 text-base"
        />
      </Field>
      <div className="grid gap-5 sm:grid-cols-2">
        <Field label="First name" error={form.errors.first_name} required>
          <Input
            value={form.data.first_name}
            onChange={(e) => form.setData('first_name', e.target.value)}
            autoComplete="given-name"
            className="h-12 text-base"
          />
        </Field>
        <Field label="Last name" error={form.errors.last_name} required>
          <Input
            value={form.data.last_name}
            onChange={(e) => form.setData('last_name', e.target.value)}
            autoComplete="family-name"
            className="h-12 text-base"
          />
        </Field>
      </div>
      <Field
        label="Email or mobile number"
        hint="We’ll send a code to check it’s you"
        error={form.errors.contact}
        required
      >
        <Input
          value={form.data.contact}
          onChange={(e) => form.setData('contact', e.target.value)}
          autoComplete="email"
          inputMode="email"
          className="h-12 text-base"
        />
      </Field>
      {countries.length > 1 && (
        <Field label="Country" error={form.errors.country}>
          {(control) => (
            <Select value={form.data.country} onValueChange={(v) => form.setData('country', v)}>
              <SelectTrigger {...control}>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {countries.map((c) => (
                  <SelectItem key={c.code} value={c.code}>
                    {c.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          )}
        </Field>
      )}
      <Button type="submit" size="lg" className="w-full" loading={form.processing}>
        Send my code
      </Button>
      <p className="text-center text-sm text-[#1B1640]/65">
        Already on WURK?{' '}
        <a
          href="/sign-in"
          className="text-primary font-semibold underline-offset-4 hover:underline"
        >
          Sign in
        </a>
      </p>
    </form>
  );
}

function Verify({ maskedContact, onEdit }: { maskedContact: string | null; onEdit: () => void }) {
  const form = useForm({ code: '' });
  const send = (code: string) => {
    form.transform(() => ({ code }));
    form.post('/signup/verify', { preserveScroll: true, onError: () => form.setData('code', '') });
  };
  return (
    <form
      onSubmit={(e) => {
        e.preventDefault();
        send(form.data.code);
      }}
      className="grid gap-6"
    >
      <p className="text-[17px] text-[#1B1640]/75">
        Enter the 6-digit code we sent to{' '}
        <strong className="font-semibold text-[#1B1640]">{maskedContact}</strong>.
      </p>
      <div role="group" aria-label="One-time code">
        <OtpInput
          value={form.data.code}
          onChange={(v) => form.setData('code', v)}
          onComplete={send}
          invalid={Boolean(form.errors.code)}
          describedBy="code-error"
          autoFocus
        />
        {form.errors.code && (
          <p id="code-error" role="alert" className="text-danger-11 mt-3 text-sm">
            {form.errors.code}
          </p>
        )}
      </div>
      <Button
        type="submit"
        size="lg"
        className="w-full"
        loading={form.processing}
        disabled={form.data.code.length < 6}
      >
        Verify
      </Button>
      <div className="flex flex-wrap justify-between gap-3 text-sm">
        <button
          type="button"
          className="text-primary min-h-11 font-semibold underline-offset-4 hover:underline"
          onClick={() => router.post('/signup/resend', {}, { preserveScroll: true })}
        >
          Send a new code
        </button>
        <button
          type="button"
          className="min-h-11 text-[#1B1640]/70 underline-offset-4 hover:underline"
          onClick={onEdit}
        >
          Use a different email or number
        </button>
      </div>
    </form>
  );
}

function PackageStep({ data, packages }: Pick<Props, 'data' | 'packages'>) {
  const form = useForm({
    package_id: data.package_id || packages[0]?.id || '',
    period: data.period,
  });
  return (
    <form
      onSubmit={(e) => {
        e.preventDefault();
        form.post('/signup/package', { preserveScroll: true });
      }}
      className="grid gap-5"
    >
      <div
        role="radiogroup"
        aria-label="Billing period"
        className="inline-flex w-fit rounded-full bg-[#F1EFFA] p-1"
      >
        {(['monthly', 'yearly'] as const).map((p) => (
          <button
            key={p}
            type="button"
            role="radio"
            aria-checked={form.data.period === p}
            onClick={() => form.setData('period', p)}
            className={`min-h-11 rounded-full px-5 text-sm font-semibold ${form.data.period === p ? 'bg-[#1B1640] text-white' : 'text-[#1B1640]/75'}`}
          >
            {p === 'monthly' ? 'Monthly' : 'Yearly'}
          </button>
        ))}
      </div>
      <div role="radiogroup" aria-label="Package" className="grid gap-3">
        {packages.map((p) => {
          const selected = form.data.package_id === p.id;
          return (
            <button
              key={p.id}
              type="button"
              role="radio"
              aria-checked={selected}
              onClick={() => form.setData('package_id', p.id)}
              className={`flex items-start justify-between gap-4 rounded-2xl p-4 text-left ring-1 transition-colors ${selected ? 'ring-primary bg-[#F1EFFA] ring-2' : 'ring-[#1B1640]/12 hover:ring-[#1B1640]/30'}`}
            >
              <span>
                <span className="font-display block text-lg font-bold">{p.name}</span>
                {p.description && (
                  <span className="block text-sm text-[#1B1640]/70">{p.description}</span>
                )}
                {p.trial_days > 0 && (
                  <span className="text-primary mt-1 block text-sm font-medium">
                    {p.trial_days}-day free trial
                  </span>
                )}
              </span>
              <span className="shrink-0 text-right">
                <span className="block font-semibold">
                  {rand(form.data.period === 'monthly' ? p.monthly_cents : p.yearly_cents)}
                </span>
                <span className="block text-xs text-[#1B1640]/65">
                  / {form.data.period === 'monthly' ? 'month' : 'year'} excl. VAT
                </span>
              </span>
            </button>
          );
        })}
      </div>
      {form.errors.package_id && (
        <p role="alert" className="text-danger-11 text-sm">
          {form.errors.package_id}
        </p>
      )}
      <Button
        type="submit"
        size="lg"
        className="w-full"
        loading={form.processing}
        disabled={!form.data.package_id}
      >
        Continue
      </Button>
    </form>
  );
}

function Address({ data, rootDomain }: Pick<Props, 'data' | 'rootDomain'>) {
  const form = useForm({ handle: data.handle || slug(data.business_name) });
  const [check, setCheck] = useState<{
    handle: string;
    available: boolean;
    problem: string | null;
  } | null>(null);
  useEffect(() => {
    if (!form.data.handle) return;
    const handle = form.data.handle;
    const t = setTimeout(async () => {
      const r = await fetch(`/signup/check-handle?handle=${encodeURIComponent(handle)}`, {
        headers: { Accept: 'application/json' },
      });
      if (r.ok) setCheck({ handle, ...(await r.json()) });
    }, 300);
    return () => clearTimeout(t);
  }, [form.data.handle]);
  const shown = check && check.handle === form.data.handle ? check : null;

  return (
    <form
      onSubmit={(e) => {
        e.preventDefault();
        form.post('/signup/address', { preserveScroll: true });
      }}
      className="grid gap-5"
      noValidate
    >
      <Field
        label="Your web address"
        hint={`${form.data.handle || 'your-business'}.${rootDomain}`}
        error={
          form.errors.handle ??
          (shown && !shown.available ? (shown.problem ?? undefined) : undefined)
        }
        required
      >
        {(control) => (
          <div className="relative">
            <Input
              {...control}
              value={form.data.handle}
              onChange={(e) => form.setData('handle', e.target.value.toLowerCase())}
              className="h-12 pr-11 text-base"
              autoCapitalize="none"
              spellCheck={false}
            />
            {shown && (
              <span
                className="absolute inset-y-0 right-3.5 grid place-items-center"
                aria-live="polite"
              >
                {shown.available ? (
                  <Check className="text-success-11 size-5" aria-label="Available" />
                ) : (
                  <X className="text-danger-11 size-5" aria-label="Not available" />
                )}
              </span>
            )}
          </div>
        )}
      </Field>
      <p className="text-sm text-[#1B1640]/65">
        Letters, numbers and dashes. You can add your own domain later on packages that include it.
      </p>
      <div className="flex gap-3">
        <Button
          type="button"
          variant="secondary"
          size="lg"
          onClick={() => router.post('/signup/back/package', {}, { preserveScroll: true })}
        >
          Back
        </Button>
        <Button
          type="submit"
          size="lg"
          className="flex-1"
          loading={form.processing}
          disabled={Boolean(shown && !shown.available)}
        >
          Continue
        </Button>
      </div>
    </form>
  );
}

function Finish({
  data,
  packages,
  trialAvailable,
  rootDomain,
}: Pick<Props, 'data' | 'packages' | 'trialAvailable' | 'rootDomain'>) {
  const form = useForm({});
  const { errors } = usePage<{ errors: Record<string, string> }>().props;
  const pkg = packages.find((p) => p.id === data.package_id);
  const trial = trialAvailable && (pkg?.trial_days ?? 0) > 0;
  const rows: [string, string][] = [
    ['Business', data.business_name],
    ['Web address', `${data.handle}.${rootDomain}`],
    ['Package', `${pkg?.name ?? ''}, ${data.period === 'monthly' ? 'monthly' : 'yearly'}`],
  ];
  return (
    <div className="grid gap-6">
      <dl className="grid gap-3 text-[15px]">
        {rows.map(([k, v]) => (
          <div key={k} className="flex justify-between gap-4 border-b border-[#1B1640]/8 pb-3">
            <dt className="text-[#1B1640]/65">{k}</dt>
            <dd className="text-right font-medium">{v}</dd>
          </div>
        ))}
      </dl>
      <p className="text-[15px] text-[#1B1640]/75">
        {trial
          ? `Your ${pkg?.trial_days}-day free trial starts now. No card needed — we’ll remind you before it ends.`
          : 'You’ve already had a free trial with this email or number, so you’ll pay for your first period next — by card.'}
      </p>
      {errors.finish && (
        <p role="alert" className="text-danger-11 text-sm">
          {errors.finish}
        </p>
      )}
      <div className="flex gap-3">
        <Button
          type="button"
          variant="secondary"
          size="lg"
          onClick={() => router.post('/signup/back/address', {}, { preserveScroll: true })}
        >
          Back
        </Button>
        <Button
          type="button"
          size="lg"
          className="flex-1"
          loading={form.processing}
          onClick={() => form.post('/signup/complete')}
        >
          Create my business
        </Button>
      </div>
    </div>
  );
}

export default function Signup(props: Props) {
  const [editing, setEditing] = useState(false);
  const step = editing ? 'details' : props.step;
  const titles: Record<string, string> = {
    details: 'Tell us about your business',
    verify: 'Check it’s you',
    package: 'Choose a package',
    address: 'Pick your web address',
    finish: 'Ready to go',
  };

  return (
    <Shell step={step} steps={props.steps} title={titles[step] ?? ''}>
      {step === 'details' && <Details data={props.data} countries={props.countries} />}
      {step === 'verify' && (
        <Verify maskedContact={props.maskedContact} onEdit={() => setEditing(true)} />
      )}
      {step === 'package' && <PackageStep data={props.data} packages={props.packages} />}
      {step === 'address' && <Address data={props.data} rootDomain={props.rootDomain} />}
      {step === 'finish' && (
        <Finish
          data={props.data}
          packages={props.packages}
          trialAvailable={props.trialAvailable}
          rootDomain={props.rootDomain}
        />
      )}
    </Shell>
  );
}
