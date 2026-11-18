import { useForm } from '@inertiajs/react';
import { useId, useRef, useState } from 'react';
import {
  Badge,
  Button,
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  Checkbox,
  Field,
  Input,
  RadioGroup,
  RadioGroupItem,
  Textarea,
} from '@/design-system';
import { firstError, request } from '../editor/http';
import { __ } from '@/i18n';

type May = 'book' | 'change' | 'order';
export interface ReceptionistProps {
  settings: {
    enabled: boolean;
    whatsapp: boolean;
    may: May[];
    tone: 'friendly' | 'formal';
    greeting: string;
    monthly_cap: number;
    languages: string[];
    person_hours: string;
    away: string;
  };
  can: Record<May, boolean>;
  languages: Record<string, string>;
  credits_per_reply: number;
  replies_this_month: number;
  whatsapp_connected: boolean;
}

const MAY: [May, string, string][] = [
  [
    'book',
    'Book appointments, classes and tables',
    'Only at times that are really free, with your deposits.',
  ],
  [
    'change',
    'Move or cancel bookings',
    'Only bookings made in the chat, or proven with a code sent to the customer.',
  ],
  ['order', 'Help customers order from the shop', 'It sends a link to check out on your website.'],
];

/** Website → Chat: the AI receptionist (Sprint 19.4a) — what it may do, how it sounds, its monthly limit, and "Try it". */
export function ReceptionistCard({ r, base }: { r: ReceptionistProps; base: string }) {
  const f = useForm(r.settings);
  const id = useId();
  const credits = (n: number) => `${Number(n.toFixed(2))} credit${n === 1 ? '' : 's'}`;
  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2">
          {__('AI assistant')}{' '}
          {r.settings.enabled || r.settings.whatsapp ? (
            <Badge tone="success">{__('On')}</Badge>
          ) : (
            <Badge tone="neutral">{__('Off')}</Badge>
          )}
        </CardTitle>
      </CardHeader>
      <CardContent className="grid gap-6">
        <p className="text-fg-muted text-sm">
          {__(
            'Answers your customers’ typed questions from your own website and answers, any time of day, and books them in. It says it’s an AI, never invents prices or times, and passes the chat to you when it can’t help. Each reply costs',
          )}{' '}
          {credits(r.credits_per_reply)} — {r.replies_this_month} {__('replies so far this month.')}
        </p>
        <form
          aria-label={__('AI assistant settings')}
          className="grid gap-4"
          onSubmit={(e) => {
            e.preventDefault();
            f.put(`${base}/receptionist`, { preserveScroll: true });
          }}
        >
          <label className="flex items-center gap-2 text-sm">
            <Checkbox
              checked={f.data.enabled}
              onCheckedChange={(on) => f.setData('enabled', on === true)}
            />
            {__('Let the AI assistant answer in my website chat')}
          </label>
          {f.errors.enabled && (
            <p role="alert" className="text-danger text-sm">
              {f.errors.enabled}
            </p>
          )}
          <label className="flex items-start gap-2 text-sm">
            <Checkbox
              className="mt-0.5"
              disabled={!r.whatsapp_connected && !f.data.whatsapp}
              checked={f.data.whatsapp}
              onCheckedChange={(on) => f.setData('whatsapp', on === true)}
            />
            <span>
              {__('Let it answer on WhatsApp too')}
              <span className="text-fg-muted block">
                {r.whatsapp_connected
                  ? 'Customers tap buttons and lists to choose services and times. Take over any chat in Customers → Chats.'
                  : 'Connect your WhatsApp number first (Settings → WhatsApp).'}
              </span>
            </span>
          </label>
          {f.errors.whatsapp && (
            <p role="alert" className="text-danger text-sm">
              {f.errors.whatsapp}
            </p>
          )}
          <fieldset className="grid gap-2">
            <legend className="text-sm font-medium">
              {__('Besides answering questions, it may')}
            </legend>
            {MAY.map(([key, label, hint]) => (
              <label key={key} className="flex items-start gap-2 text-sm">
                <Checkbox
                  className="mt-0.5"
                  disabled={!r.can[key]}
                  checked={f.data.may.includes(key)}
                  onCheckedChange={(on) =>
                    f.setData(
                      'may',
                      on === true ? [...f.data.may, key] : f.data.may.filter((m) => m !== key),
                    )
                  }
                />
                <span>
                  {label}
                  <span className="text-fg-muted block">
                    {r.can[key] ? hint : 'Not in your package.'}
                  </span>
                </span>
              </label>
            ))}
          </fieldset>
          <fieldset className="grid gap-2">
            <legend className="text-sm font-medium">{__('How it sounds')}</legend>
            <RadioGroup
              value={f.data.tone}
              onValueChange={(v) => f.setData('tone', v as 'friendly' | 'formal')}
              className="flex flex-wrap gap-4"
              aria-label={__('How it sounds')}
            >
              {(
                [
                  ['friendly', 'Friendly'],
                  ['formal', 'Formal'],
                ] as const
              ).map(([v, label]) => (
                <label key={v} htmlFor={`${id}-${v}`} className="flex items-center gap-2 text-sm">
                  <RadioGroupItem value={v} id={`${id}-${v}`} />
                  {label}
                </label>
              ))}
            </RadioGroup>
          </fieldset>
          <Field label={__('Greeting')} error={f.errors.greeting}>
            <Input
              value={f.data.greeting}
              maxLength={200}
              onChange={(e) => f.setData('greeting', e.target.value)}
            />
          </Field>
          <fieldset className="grid gap-2">
            <legend className="text-sm font-medium">{__('Languages it replies in')}</legend>
            <p className="text-fg-muted text-sm">
              {__(
                'It replies in the customer’s language when it’s one of these, and in English otherwise.',
              )}
            </p>
            <div className="flex flex-wrap gap-x-6 gap-y-3">
              {Object.entries(r.languages).map(([code, name]) => (
                <label key={code} className="flex items-center gap-2 text-sm">
                  <Checkbox
                    checked={f.data.languages.includes(code)}
                    onCheckedChange={(on) =>
                      f.setData(
                        'languages',
                        on === true
                          ? [...f.data.languages, code]
                          : f.data.languages.filter((l) => l !== code),
                      )
                    }
                  />
                  {name}
                </label>
              ))}
            </div>
            {f.errors.languages && (
              <p role="alert" className="text-danger text-sm">
                {f.errors.languages}
              </p>
            )}
          </fieldset>
          <Field
            label={__('When a person can take over (optional)')}
            hint={__('For example: Mon–Fri 8:00–17:00. The assistant tells customers.')}
            error={f.errors.person_hours}
          >
            <Input
              value={f.data.person_hours}
              maxLength={160}
              onChange={(e) => f.setData('person_hours', e.target.value)}
            />
          </Field>
          {f.data.whatsapp && (
            <Field
              label={__('When you’re closed, WhatsApp customers first get')}
              hint={__(
                'Sent once a day, outside the opening hours on your website. The assistant then carries on and can book them in.',
              )}
              error={f.errors.away}
            >
              <Textarea
                rows={2}
                value={f.data.away}
                maxLength={300}
                onChange={(e) => f.setData('away', e.target.value)}
              />
            </Field>
          )}
          <Field
            label={__('Most replies a month')}
            hint={`At ${credits(r.credits_per_reply)} a reply, ${f.data.monthly_cap} replies cost up to ${credits(f.data.monthly_cap * r.credits_per_reply)}. After that, your own answers take over.`}
            error={f.errors.monthly_cap}
          >
            <Input
              type="number"
              min={0}
              inputMode="numeric"
              value={f.data.monthly_cap}
              onChange={(e) => f.setData('monthly_cap', Number.parseInt(e.target.value, 10) || 0)}
            />
          </Field>
          <Button type="submit" className="justify-self-start" loading={f.processing}>
            {__('Save the assistant')}
          </Button>
        </form>
        <TryIt base={base} />
      </CardContent>
    </Card>
  );
}

type Line = { from: 'you' | 'assistant'; text: string };

/** A test chat with the real facts and free times. Nothing is booked, changed, ordered or handed over. */
function TryIt({ base }: { base: string }) {
  const [lines, setLines] = useState<Line[]>([]);
  const [text, setText] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const conversation = useRef<string | null>(null);
  return (
    <section
      aria-labelledby="try-it-title"
      className="border-border grid gap-3 rounded-lg border p-4"
    >
      <h3 id="try-it-title" className="font-semibold">
        {__('Try it')}
      </h3>
      <p className="text-fg-muted text-sm">
        {__(
          'Chat with the assistant as a customer would. It uses your real answers and free times, but nothing is booked, changed or ordered. Each reply costs the same as a real one.',
        )}
      </p>
      <ol aria-label={__('Test chat')} aria-live="polite" className="grid gap-2">
        {lines.map((l, i) => (
          <li
            key={i}
            className={
              l.from === 'you'
                ? 'bg-primary text-primary-fg justify-self-end rounded-lg px-3 py-2 text-sm'
                : 'bg-surface-raised border-border justify-self-start rounded-lg border px-3 py-2 text-sm whitespace-pre-line'
            }
          >
            <span className="sr-only">{l.from === 'you' ? 'You: ' : 'Assistant: '}</span>
            {l.text}
          </li>
        ))}
      </ol>
      <p role="status" className="text-fg-muted min-h-5 text-sm">
        {busy ? 'The assistant is replying…' : ''}
      </p>
      {error && (
        <p role="alert" className="text-danger text-sm">
          {error}
        </p>
      )}
      <form
        aria-label={__('Try the assistant')}
        className="flex gap-2"
        onSubmit={async (e) => {
          e.preventDefault();
          const message = text.trim();
          if (!message || busy) return;
          setText('');
          setError(null);
          setLines((ls) => [...ls, { from: 'you', text: message }]);
          setBusy(true);
          const r = await request<{ conversation?: string; reply?: string | null }>(
            'POST',
            `${base}/try`,
            {
              message,
              conversation: conversation.current,
            },
          ).catch(() => null);
          setBusy(false);
          if (!r || r.status !== 200) {
            setError((r && firstError(r.data)) ?? 'That didn’t send — please try again.');
            return;
          }
          conversation.current = r.data.conversation ?? conversation.current;
          setLines((ls) => [...ls, { from: 'assistant', text: r.data.reply ?? '…' }]);
        }}
      >
        <Input
          aria-label={__('Your test message')}
          placeholder={__('e.g. Can I book braids on Saturday?')}
          value={text}
          maxLength={2000}
          onChange={(e) => setText(e.target.value)}
        />
        <Button type="submit" loading={busy}>
          {__('Send')}
        </Button>
        {lines.length > 0 && (
          <Button
            type="button"
            variant="ghost"
            onClick={() => {
              setLines([]);
              conversation.current = null;
            }}
          >
            {__('Start again')}
          </Button>
        )}
      </form>
    </section>
  );
}
