import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Button, Card, CardContent, CardHeader, CardTitle, Field, Input } from '@/design-system';
import { AgentLayout } from '../../components/AgentLayout';
import { __ } from '@/i18n';

interface Onboarding {
  id: string;
  step: 'verify' | 'package' | 'setup' | 'topup' | 'handover' | 'done';
  business: string;
  owner: string;
  masked: string;
  handle: string | null;
  demo: boolean;
  top_up: { rand: number; how: string } | null;
  setup_url: string | null;
  started: string;
}
interface Pkg {
  id: string;
  name: string;
  description: string;
  monthly_cents: number;
  yearly_cents: number;
  trial_days: number;
}
interface Props {
  onboarding: Onboarding | null;
  recent: { id: string; business: string; step: string; minutes: number | null; live: boolean }[];
  demo: { business: string; handle: string } | null;
  packages: Pkg[];
  methods: Record<string, string>;
  minTopUp: number;
}

const STEPS = ['Owner', 'Code', 'Package', 'Set up', 'Top-up', 'Hand over'];
const ORDER: Record<Onboarding['step'], number> = {
  verify: 1,
  package: 2,
  setup: 3,
  topup: 4,
  handover: 5,
  done: 6,
};
/** Rand as WURK writes it everywhere: R1 380.00 (the server's own format). */
const rand = (c: number) => `R${(c / 100).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ' ')}`;

/** Minutes since the start, so the agent can keep to the 15 minutes. */
function Clock({ since }: { since: string }) {
  const [now, setNow] = useState(() => Date.now());
  useEffect(() => {
    const t = window.setInterval(() => setNow(Date.now()), 15000);
    return () => window.clearInterval(t);
  }, []);
  const minutes = Math.max(
    0,
    Math.floor((now - new Date(since.replace(' ', 'T') + 'Z').getTime()) / 60000),
  );
  return (
    <p className="text-fg-muted text-sm" aria-live="off">
      {minutes} {minutes === 1 ? 'minute' : 'minutes'} {__('so far')}
    </p>
  );
}

function Start({ demo }: { demo: Props['demo'] }) {
  const form = useForm({
    business_name: demo?.business ?? '',
    first_name: '',
    last_name: '',
    mobile: '',
    use_demo: false,
  });
  return (
    <form
      className="grid gap-3"
      onSubmit={(e) => {
        e.preventDefault();
        form.post('/agent/onboard');
      }}
    >
      <Field label={__('Business name')} error={form.errors.business_name}>
        <Input
          value={form.data.business_name}
          onChange={(e) => form.setData('business_name', e.target.value)}
        />
      </Field>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label={__('Owner’s first name')} error={form.errors.first_name}>
          <Input
            value={form.data.first_name}
            onChange={(e) => form.setData('first_name', e.target.value)}
          />
        </Field>
        <Field label={__('Owner’s last name')} error={form.errors.last_name}>
          <Input
            value={form.data.last_name}
            onChange={(e) => form.setData('last_name', e.target.value)}
          />
        </Field>
      </div>
      <Field
        label={__('Owner’s mobile number')}
        hint={__('We send them a code on WhatsApp or SMS.')}
        error={form.errors.mobile}
      >
        <Input
          type="tel"
          inputMode="tel"
          value={form.data.mobile}
          onChange={(e) => form.setData('mobile', e.target.value)}
        />
      </Field>
      {demo && (
        <label className="flex items-center gap-2 text-sm">
          <input
            type="checkbox"
            checked={form.data.use_demo}
            onChange={(e) => form.setData('use_demo', e.target.checked)}
          />
          {__('Use my demo site (')}
          {demo.business}
          {__(') for this business')}
        </label>
      )}
      <Button type="submit" loading={form.processing}>
        {__('Send the code')}
      </Button>
    </form>
  );
}

function Verify({ o }: { o: Onboarding }) {
  const form = useForm({ code: '' });
  return (
    <form
      className="grid gap-3"
      onSubmit={(e) => {
        e.preventDefault();
        form.post(`/agent/onboard/${o.id}/verify`);
      }}
    >
      <p className="text-sm">
        {__('Ask')} {o.owner} {__('for the code we sent to')} {o.masked}.
      </p>
      <Field label={__('Code')} error={form.errors.code}>
        <Input
          inputMode="numeric"
          autoComplete="one-time-code"
          value={form.data.code}
          onChange={(e) => form.setData('code', e.target.value)}
        />
      </Field>
      <div className="flex flex-wrap gap-2">
        <Button type="submit" loading={form.processing}>
          {__('Check the code')}
        </Button>
        <Button
          type="button"
          variant="ghost"
          onClick={() => router.post(`/agent/onboard/${o.id}/resend`)}
        >
          {__('Send a new code')}
        </Button>
      </div>
    </form>
  );
}

function Package({ o, packages }: { o: Onboarding; packages: Pkg[] }) {
  const form = useForm({ package_id: packages[0]?.id ?? '', period: 'monthly' });
  return (
    <form
      className="grid gap-3"
      onSubmit={(e) => {
        e.preventDefault();
        form.post(`/agent/onboard/${o.id}/package`);
      }}
    >
      <fieldset className="grid gap-2">
        <legend className="mb-1 text-sm font-medium">{__('Package')}</legend>
        {packages.map((p) => (
          <label
            key={p.id}
            className="border-border has-[:checked]:border-brand-9 grid gap-1 rounded-md border p-3 text-sm"
          >
            <span className="flex items-center gap-2 font-medium">
              <input
                type="radio"
                name="package"
                checked={form.data.package_id === p.id}
                onChange={() => form.setData('package_id', p.id)}
              />
              {p.name} — {rand(p.monthly_cents)} {__('a month')}
            </span>
            <span className="text-fg-muted">
              {p.description}
              {p.trial_days > 0 ? ` · ${p.trial_days}-day free trial` : ''}
            </span>
          </label>
        ))}
      </fieldset>
      {form.errors.package_id && <p className="text-danger text-sm">{form.errors.package_id}</p>}
      <Button type="submit" loading={form.processing}>
        {__('Create')} {o.business}
      </Button>
    </form>
  );
}

function Setup({ o }: { o: Onboarding }) {
  return (
    <div className="grid gap-3 text-sm">
      <p>
        {__('Now set up')} {o.business} {__('together with')} {o.owner}
        {__(
          ': answer six questions by voice or text, or start from their Google listing or Facebook page. Nothing goes live until it’s published.',
        )}
      </p>
      <div className="flex flex-wrap gap-2">
        {o.setup_url && (
          <Button asChild>
            <a href={o.setup_url}>{__('Set up by talking')}</a>
          </Button>
        )}
        <Button
          variant="secondary"
          onClick={() => router.post(`/agent/onboard/${o.id}/setup-done`)}
        >
          {o.demo ? 'Keep the demo site — next' : 'Next: the first top-up'}
        </Button>
      </div>
    </div>
  );
}

function TopUp({ o, methods, min }: { o: Onboarding; methods: Props['methods']; min: number }) {
  const form = useForm({
    how: 'online',
    amount: String(min),
    email: '',
    method: Object.keys(methods)[0] ?? '',
    receipt: '',
  });
  const submit = (how: string) => {
    form.transform((d) => ({ ...d, how }));
    form.post(`/agent/onboard/${o.id}/top-up`);
  };
  return (
    <div className="grid gap-4 text-sm">
      <Field label={__('Amount (R)')} hint={`Any amount from R${min}.`} error={form.errors.amount}>
        <Input
          inputMode="numeric"
          value={form.data.amount}
          onChange={(e) => form.setData('amount', e.target.value)}
        />
      </Field>
      <fieldset className="border-border grid gap-3 rounded-md border p-3">
        <legend className="px-1 font-medium">{__('The owner pays now')}</legend>
        {Object.keys(methods).length > 0 && (
          <Field label={__('How')}>
            <select
              className="border-border bg-bg rounded-md border px-3 py-2"
              value={form.data.method}
              onChange={(e) => form.setData('method', e.target.value)}
            >
              {Object.entries(methods).map(([k, name]) => (
                <option key={k} value={k}>
                  {name}
                </option>
              ))}
            </select>
          </Field>
        )}
        <Field
          label={__('Owner’s email (for the receipt)')}
          error={form.errors.email ?? form.errors.method}
        >
          <Input
            type="email"
            value={form.data.email}
            onChange={(e) => form.setData('email', e.target.value)}
          />
        </Field>
        <Button onClick={() => submit('online')} loading={form.processing}>
          {__('Pay now')}
        </Button>
      </fieldset>
      <fieldset className="border-border grid gap-3 rounded-md border p-3">
        <legend className="px-1 font-medium">{__('The owner gives you cash')}</legend>
        <Field label={__('Receipt number you gave')} error={form.errors.receipt}>
          <Input
            value={form.data.receipt}
            onChange={(e) => form.setData('receipt', e.target.value)}
          />
        </Field>
        <Button variant="secondary" onClick={() => submit('cash')} loading={form.processing}>
          {__('Record cash')}
        </Button>
      </fieldset>
      <Button variant="ghost" onClick={() => submit('later')}>
        {__('The owner will top up later')}
      </Button>
    </div>
  );
}

function HandOver({ o }: { o: Onboarding }) {
  return (
    <div className="grid gap-3 text-sm">
      <p>
        {o.owner} {__('gets a WhatsApp message with their sign-in link and how to share')}{' '}
        {o.business}
        {__('. Your access to the business ends; it stays credited to you.')}
      </p>
      <Button onClick={() => router.post(`/agent/onboard/${o.id}/hand-over`)}>
        {__('Hand over to')} {o.owner}
      </Button>
    </div>
  );
}

export default function AgentOnboard({
  onboarding: o,
  recent,
  demo,
  packages,
  methods,
  minTopUp,
}: Props) {
  const warning = (usePage().props.flash as { warning?: string | null } | undefined)?.warning;
  return (
    <AgentLayout title={__('Set up a business')}>
      {warning && (
        <p role="alert" className="text-danger text-sm">
          {warning}
        </p>
      )}
      {o === null ? (
        <>
          <Card>
            <CardHeader>
              <CardTitle>{__('Start with the owner')}</CardTitle>
            </CardHeader>
            <CardContent>
              <Start demo={demo} />
            </CardContent>
          </Card>
          {recent.length > 0 && (
            <Card>
              <CardHeader>
                <CardTitle>{__('Recent')}</CardTitle>
              </CardHeader>
              <CardContent>
                <ul className="grid gap-2 text-sm">
                  {recent.map((r) => (
                    <li key={r.id} className="flex flex-wrap justify-between gap-2">
                      <Link href={`/agent/onboard/${r.id}`}>{r.business}</Link>
                      <span className="text-fg-muted">
                        {r.step === 'done'
                          ? `${r.live ? 'Live' : 'Set up, not yet live'} · ${r.minutes} min`
                          : 'Not finished'}
                      </span>
                    </li>
                  ))}
                </ul>
              </CardContent>
            </Card>
          )}
        </>
      ) : (
        <Card>
          <CardHeader>
            <CardTitle>{o.business}</CardTitle>
          </CardHeader>
          <CardContent className="grid gap-4">
            <ol aria-label={__('Steps')} className="flex flex-wrap gap-2 text-xs">
              {STEPS.map((s, i) => (
                <li
                  key={s}
                  aria-current={ORDER[o.step] === i ? 'step' : undefined}
                  className={`rounded-full px-2 py-1 ${ORDER[o.step] > i ? 'bg-brand-3' : ORDER[o.step] === i ? 'bg-brand-9 text-white' : 'bg-muted'}`}
                >
                  {i + 1}. {s}
                </li>
              ))}
            </ol>
            {o.step !== 'done' && <Clock since={o.started} />}
            {o.step === 'verify' && <Verify o={o} />}
            {o.step === 'package' && <Package o={o} packages={packages} />}
            {o.step === 'setup' && <Setup o={o} />}
            {o.step === 'topup' && <TopUp o={o} methods={methods} min={minTopUp} />}
            {o.step === 'handover' && <HandOver o={o} />}
            {o.step === 'done' && (
              <p className="text-sm">
                {__('Done.')} {o.owner} {__('has their sign-in link.')}{' '}
                <Link href="/agent/onboard">{__('Set up another business')}</Link>
              </p>
            )}
          </CardContent>
        </Card>
      )}
    </AgentLayout>
  );
}
