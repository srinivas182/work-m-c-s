import { Head, useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/design-system/components/badge';
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
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/design-system/components/select';
import { Switch } from '@/design-system/components/choice';
import AppShell from '@/layouts/AppShell';
import { rand } from '@/lib/money';
import { __ } from '@/i18n';

interface Step {
  provider: string;
  model: string;
}

interface Settings {
  tasks: Record<string, Step>;
  fallback: Step[];
  prices: Record<string, [number, number]>;
  fx_zar_per_usd: number;
  fx_buffer_pct: number;
  target_margin_pct: number;
  caps: { tenant_daily_requests: number; provider_monthly_usd: Record<string, number | null> };
  retirements?: Record<string, string | null>;
  assistant: { enabled: boolean; free_questions: number; monthly_cap: number };
  sales: { enabled: boolean; greeting: string; monthly_cap: number };
  speech: Step;
  speech_fallback: Step[];
  speech_prices: Record<string, number>;
}

interface Props {
  settings: Settings;
  providers: Record<string, string>;
  speechProviders: Record<string, string>;
  tasks: Record<string, string>;
  connected: Record<string, boolean>;
  sales: { replies: number; zar: number };
  usage: {
    byProvider: {
      provider: string;
      requests: number;
      failed: number;
      fallbacks: number;
      usd: number;
      zar: number;
    }[];
    byBusiness: { business: string; requests: number; zar: number }[];
    totalZar: number;
  };
}

function StepPicker({
  step,
  providers,
  onChange,
  label,
}: {
  step: Step;
  providers: Record<string, string>;
  onChange: (s: Step) => void;
  label: string;
}) {
  return (
    <div className="grid gap-2 sm:grid-cols-2">
      <Field label={`${label}: provider`}>
        <Select value={step.provider} onValueChange={(v) => onChange({ ...step, provider: v })}>
          <SelectTrigger aria-label={`${label}: provider`}>
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {Object.entries(providers).map(([k, name]) => (
              <SelectItem key={k} value={k}>
                {name}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </Field>
      <Field label={`${label}: model (pinned version)`}>
        <Input
          value={step.model}
          onChange={(e) => onChange({ ...step, model: e.target.value.trim() })}
          spellCheck={false}
        />
      </Field>
    </div>
  );
}

export default function AiAdmin({
  settings,
  providers,
  speechProviders,
  tasks,
  connected,
  usage,
  sales,
}: Props) {
  const form = useForm<Settings>({ ...settings, retirements: settings.retirements ?? {} });
  const [trial, setTrial] = useState<Step>({
    provider: 'anthropic',
    model: settings.tasks.site_generation?.model ?? '',
  });
  const [trialResult, setTrialResult] = useState<string | null>(null);
  const [trying, setTrying] = useState(false);
  const zar = (n: number) => rand(Math.round(n * 100)); // the one money format across WURK
  const modelKeys = Object.keys(form.data.prices);
  const [now] = useState(() => Date.now()); // captured once: render stays pure
  const soon = (date: string | null | undefined) =>
    !!date && new Date(date).getTime() - now < 60 * 86400000;

  const tryModel = async () => {
    setTrying(true);
    setTrialResult(null);
    const token = decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '');
    const r = await fetch('/settings/ai/try', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-XSRF-TOKEN': token,
      },
      body: JSON.stringify(trial),
    });
    const data = await r.json();
    setTrialResult(
      r.ok
        ? `${data.text}\n\n${data.input_tokens} in / ${data.output_tokens} out tokens · $${data.usd.toFixed(6)} (${zar(data.usd * form.data.fx_zar_per_usd)})`
        : data.message,
    );
    setTrying(false);
  };

  return (
    <AppShell title={__('WURK AI')}>
      <Head title={__('WURK AI')} />
      <form
        onSubmit={(e) => {
          e.preventDefault();
          form.put('/settings/ai', { preserveScroll: true });
        }}
        className="grid max-w-4xl gap-6"
      >
        <PageHeader
          title={__('WURK AI')}
          description={__(
            'Businesses see one “WURK AI”. Here you choose what runs behind it and see what it costs.',
          )}
        />

        <Card>
          <CardHeader>
            <CardTitle>{__('This month')}</CardTitle>
            <CardDescription>
              {__('Real cost of every request, including failed attempts and fallbacks:')}{' '}
              {zar(usage.totalZar)} {__('so far.')}
            </CardDescription>
          </CardHeader>
          <CardContent className="grid gap-6">
            {usage.byProvider.length === 0 ? (
              <p className="text-fg-muted text-sm">{__('No AI requests yet this month.')}</p>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <caption className="sr-only">{__('Usage by provider')}</caption>
                  <thead>
                    <tr className="text-fg-muted text-left">
                      <th className="py-2 font-medium">{__('Provider')}</th>
                      <th className="py-2 text-right font-medium">{__('Requests')}</th>
                      <th className="py-2 text-right font-medium">{__('Failed')}</th>
                      <th className="py-2 text-right font-medium">{__('Fallbacks')}</th>
                      <th className="py-2 text-right font-medium">{__('Cost')}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {usage.byProvider.map((p) => (
                      <tr key={p.provider} className="border-border border-t">
                        <td className="py-2">{p.provider}</td>
                        <td className="py-2 text-right tabular-nums">{p.requests}</td>
                        <td className="py-2 text-right tabular-nums">{p.failed}</td>
                        <td className="py-2 text-right tabular-nums">{p.fallbacks}</td>
                        <td className="py-2 text-right tabular-nums">
                          ${p.usd.toFixed(2)} · {zar(p.zar)}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
            {usage.byBusiness.length > 0 && (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <caption className="mb-2 text-left font-medium">
                    {__('Businesses using the most')}
                  </caption>
                  <tbody>
                    {usage.byBusiness.map((b) => (
                      <tr key={b.business} className="border-border border-t">
                        <td className="py-2">{b.business}</td>
                        <td className="py-2 text-right tabular-nums">
                          {b.requests} {__('requests')}
                        </td>
                        <td className="py-2 text-right tabular-nums">{zar(b.zar)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>{__('Models')}</CardTitle>
            <CardDescription>
              {__(
                'Pin a specific version — never a “latest” alias. If the first fails, the fallbacks are tried in order. API keys live under Providers',
              )}{' '}
              {Object.entries(connected).map(([p, ok]) => (
                <Badge key={p} tone={ok ? 'success' : 'neutral'} className="ml-1">
                  {providers[p]}: {ok ? 'connected' : 'no key'}
                </Badge>
              ))}
            </CardDescription>
          </CardHeader>
          <CardContent className="grid gap-6">
            {Object.entries(tasks).map(([task, label]) => (
              <StepPicker
                key={task}
                label={label}
                providers={providers}
                step={form.data.tasks[task] ?? { provider: 'anthropic', model: '' }}
                onChange={(s) => form.setData('tasks', { ...form.data.tasks, [task]: s })}
              />
            ))}
            <fieldset className="grid gap-3">
              <legend className="mb-2 text-sm font-medium">{__('Fallback order')}</legend>
              {form.data.fallback.map((f, i) => (
                <div key={i} className="flex items-end gap-2">
                  <div className="flex-1">
                    <StepPicker
                      label={`Fallback ${i + 1}`}
                      providers={providers}
                      step={f}
                      onChange={(s) =>
                        form.setData(
                          'fallback',
                          form.data.fallback.map((x, j) => (j === i ? s : x)),
                        )
                      }
                    />
                  </div>
                  <Button
                    variant="ghost"
                    size="icon"
                    aria-label={`Remove fallback ${i + 1}`}
                    onClick={() =>
                      form.setData(
                        'fallback',
                        form.data.fallback.filter((_, j) => j !== i),
                      )
                    }
                  >
                    <Trash2 />
                  </Button>
                </div>
              ))}
              {form.data.fallback.length < 4 && (
                <Button
                  variant="secondary"
                  size="sm"
                  className="justify-self-start"
                  onClick={() =>
                    form.setData('fallback', [
                      ...form.data.fallback,
                      { provider: 'google', model: '' },
                    ])
                  }
                >
                  <Plus /> {__('Add a fallback')}
                </Button>
              )}
            </fieldset>
            <fieldset className="grid gap-3">
              <legend className="mb-2 text-sm font-medium">
                {__('Voice notes (speech to text)')}
              </legend>
              <p className="text-fg-muted text-sm">
                {__(
                  'Customers’ WhatsApp voice notes are transcribed with this model, then answered like typed messages. Test the African languages in the evaluation set before switching.',
                )}
              </p>
              <StepPicker
                label={__('Voice notes')}
                providers={speechProviders}
                step={form.data.speech}
                onChange={(s) => form.setData('speech', s)}
              />
              <StepPicker
                label={__('Voice notes fallback')}
                providers={speechProviders}
                step={form.data.speech_fallback[0] ?? { provider: 'google', model: '' }}
                onChange={(s) => form.setData('speech_fallback', s.model === '' ? [] : [s])}
              />
              <div className="grid gap-2 sm:grid-cols-2">
                {Object.keys(form.data.speech_prices).map((key) => (
                  <Field key={key} label={`${key}: US$ per minute`}>
                    <Input
                      type="number"
                      min={0}
                      step="0.0001"
                      value={form.data.speech_prices[key]}
                      onChange={(e) =>
                        form.setData('speech_prices', {
                          ...form.data.speech_prices,
                          [key]: Number(e.target.value),
                        })
                      }
                    />
                  </Field>
                ))}
              </div>
            </fieldset>
            <div className="border-border grid gap-3 rounded-xl border p-4">
              <p className="font-medium">{__('Try a model before switching')}</p>
              <StepPicker
                label={__('Try')}
                providers={providers}
                step={trial}
                onChange={setTrial}
              />
              <Button
                variant="secondary"
                className="justify-self-start"
                onClick={tryModel}
                loading={trying}
              >
                {__('Write a sample')}
              </Button>
              {trialResult && (
                <p role="status" className="text-sm whitespace-pre-line">
                  {trialResult}
                </p>
              )}
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>{__('Prices, exchange rate and margin')}</CardTitle>
            <CardDescription>
              {__(
                'US dollars per million tokens, from each provider’s pricing page. Keep these and the exchange rate current — they drive the cost tip on packages.',
              )}
            </CardDescription>
          </CardHeader>
          <CardContent className="grid gap-4">
            {modelKeys.map((key) => (
              <div key={key} className="grid items-end gap-2 sm:grid-cols-[1fr_8rem_8rem_10rem]">
                <p className="pb-2 text-sm font-medium">
                  {key}
                  {soon(form.data.retirements?.[key]) && (
                    <Badge tone="warning" className="ml-2">
                      {__('Retiring soon')}
                    </Badge>
                  )}
                </p>
                <Field label={__('Input $/M')}>
                  <Input
                    inputMode="decimal"
                    value={String(form.data.prices[key]?.[0] ?? 0)}
                    onChange={(e) =>
                      form.setData('prices', {
                        ...form.data.prices,
                        [key]: [Number(e.target.value) || 0, form.data.prices[key]?.[1] ?? 0],
                      })
                    }
                  />
                </Field>
                <Field label={__('Output $/M')}>
                  <Input
                    inputMode="decimal"
                    value={String(form.data.prices[key]?.[1] ?? 0)}
                    onChange={(e) =>
                      form.setData('prices', {
                        ...form.data.prices,
                        [key]: [form.data.prices[key]?.[0] ?? 0, Number(e.target.value) || 0],
                      })
                    }
                  />
                </Field>
                <Field label={__('Retires on (optional)')}>
                  <Input
                    type="date"
                    value={form.data.retirements?.[key] ?? ''}
                    onChange={(e) =>
                      form.setData('retirements', {
                        ...form.data.retirements,
                        [key]: e.target.value || null,
                      })
                    }
                  />
                </Field>
              </div>
            ))}
            <div className="grid gap-4 sm:grid-cols-3">
              <Field label={__('Rands per US dollar')} error={form.errors.fx_zar_per_usd}>
                <Input
                  inputMode="decimal"
                  value={String(form.data.fx_zar_per_usd)}
                  onChange={(e) => form.setData('fx_zar_per_usd', Number(e.target.value) || 0)}
                />
              </Field>
              <Field label={__('Exchange-rate buffer (%)')} error={form.errors.fx_buffer_pct}>
                <Input
                  inputMode="decimal"
                  value={String(form.data.fx_buffer_pct)}
                  onChange={(e) => form.setData('fx_buffer_pct', Number(e.target.value) || 0)}
                />
              </Field>
              <Field label={__('Target margin (%)')} error={form.errors.target_margin_pct}>
                <Input
                  inputMode="decimal"
                  value={String(form.data.target_margin_pct)}
                  onChange={(e) => form.setData('target_margin_pct', Number(e.target.value) || 0)}
                />
              </Field>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>{__('Limits')}</CardTitle>
            <CardDescription>
              {__('Protect against runaway costs. 0 means no limit.')}
            </CardDescription>
          </CardHeader>
          <CardContent className="grid gap-4 sm:grid-cols-2">
            <Field label={__('AI requests per business per day')}>
              <Input
                inputMode="numeric"
                value={String(form.data.caps.tenant_daily_requests)}
                onChange={(e) =>
                  form.setData('caps', {
                    ...form.data.caps,
                    tenant_daily_requests: Number(e.target.value) || 0,
                  })
                }
              />
            </Field>
            {['anthropic', 'google', 'openai'].map((p) => (
              <Field key={p} label={`${providers[p]}: monthly spend cap ($)`}>
                <Input
                  inputMode="decimal"
                  value={
                    form.data.caps.provider_monthly_usd?.[p] == null
                      ? ''
                      : String(form.data.caps.provider_monthly_usd[p])
                  }
                  onChange={(e) =>
                    form.setData('caps', {
                      ...form.data.caps,
                      provider_monthly_usd: {
                        ...form.data.caps.provider_monthly_usd,
                        [p]: e.target.value === '' ? null : Number(e.target.value),
                      },
                    })
                  }
                />
              </Field>
            ))}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>{__('Ask WURK')}</CardTitle>
            <CardDescription>
              The assistant in every business&rsquo;s back office. Questions beyond the free ones
              use the rate card&rsquo;s &ldquo;Ask WURK: a question&rdquo; price; changes it makes
              are free.
            </CardDescription>
          </CardHeader>
          <CardContent className="grid gap-4 sm:grid-cols-3">
            <label className="flex items-center gap-3 text-sm">
              <Switch
                aria-label={__('Ask WURK switched on')}
                checked={form.data.assistant.enabled}
                onCheckedChange={(v) =>
                  form.setData('assistant', { ...form.data.assistant, enabled: v })
                }
              />
              {__('Switched on')}
            </label>
            <Field
              label={__('Free questions a month (per business)')}
              error={form.errors['assistant.free_questions']}
            >
              <Input
                inputMode="numeric"
                value={String(form.data.assistant.free_questions)}
                onChange={(e) =>
                  form.setData('assistant', {
                    ...form.data.assistant,
                    free_questions: Number(e.target.value) || 0,
                  })
                }
              />
            </Field>
            <Field
              label={__('Most questions a month (per business)')}
              error={form.errors['assistant.monthly_cap']}
            >
              <Input
                inputMode="numeric"
                value={String(form.data.assistant.monthly_cap)}
                onChange={(e) =>
                  form.setData('assistant', {
                    ...form.data.assistant,
                    monthly_cap: Number(e.target.value) || 0,
                  })
                }
              />
            </Field>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>WURK&rsquo;s sales assistant</CardTitle>
            <CardDescription>
              {__(
                'Chats with visitors on wurk.co.za: answers from the live packages and the help centre, recommends a package, opens signup, and passes visitors to WURK or to the agent who referred them. WURK pays for it. This month:',
              )}{' '}
              {sales.replies} {__('replies, R')}
              {sales.zar.toFixed(2)}.
            </CardDescription>
          </CardHeader>
          <CardContent className="grid gap-4 sm:grid-cols-3">
            <label className="flex items-center gap-3 text-sm">
              <Switch
                aria-label={__('WURK’s sales assistant switched on')}
                checked={form.data.sales.enabled}
                onCheckedChange={(v) => form.setData('sales', { ...form.data.sales, enabled: v })}
              />
              {__('Switched on')}
            </label>
            <Field
              label={__('Greeting')}
              error={form.errors['sales.greeting']}
              className="sm:col-span-2"
            >
              <Input
                value={form.data.sales.greeting}
                maxLength={200}
                onChange={(e) =>
                  form.setData('sales', { ...form.data.sales, greeting: e.target.value })
                }
              />
            </Field>
            <Field label={__('Most replies a month')} error={form.errors['sales.monthly_cap']}>
              <Input
                inputMode="numeric"
                value={String(form.data.sales.monthly_cap)}
                onChange={(e) =>
                  form.setData('sales', {
                    ...form.data.sales,
                    monthly_cap: Number(e.target.value) || 0,
                  })
                }
              />
            </Field>
          </CardContent>
        </Card>

        {Object.keys(form.errors).length > 0 && (
          <p role="alert" className="text-danger-11 text-sm">
            {Object.values(form.errors)[0]}
          </p>
        )}
        <Button type="submit" className="justify-self-start" loading={form.processing}>
          {__('Save WURK AI settings')}
        </Button>
      </form>
    </AppShell>
  );
}
