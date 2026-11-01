import { Head, router } from '@inertiajs/react';
import { useRef, useState } from 'react';
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
  PageHeader,
} from '@/design-system';
import AppShell from '@/layouts/AppShell';

interface Channel {
  channel: 'email' | 'sms';
  subject: string | null;
  text: string;
  own: boolean;
  preview: { subject: string | null; text: string };
}
interface Message {
  key: string;
  group: string;
  label: string;
  placeholders: string[];
  required: string[];
  sample: Record<string, string>;
  channels: Channel[];
  whatsapp: boolean;
  optional: boolean;
  enabled: boolean;
}

const fill = (text: string, sample: Record<string, string>) =>
  text.replace(/\{([a-z_]+)\}/g, (all, k: string) => sample[k] ?? all);
/** SMS parts: 160 characters for one, 153 each when split. */
export const smsParts = (length: number) => (length <= 160 ? 1 : Math.ceil(length / 153));

function ChannelEditor({ m, c, smsMax }: { m: Message; c: Channel; smsMax: number }) {
  const base = window.location.pathname.replace(/\/$/, '');
  const [subject, setSubject] = useState(c.subject ?? '');
  const [text, setText] = useState(c.text);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const box = useRef<HTMLTextAreaElement>(null);
  const id = `${m.key}-${c.channel}`.replace(/\./g, '-');
  const opts = {
    preserveScroll: true,
    onError: (e: Record<string, string>) => setErrors(e),
    onSuccess: () => setErrors({}),
  };
  const insert = (p: string) => {
    const el = box.current;
    const at = el?.selectionStart ?? text.length;
    setText(`${text.slice(0, at)}{${p}}${text.slice(el?.selectionEnd ?? at)}`);
    el?.focus();
  };
  const length = fill(text, m.sample).length;
  return (
    <section
      aria-label={`${m.label} — ${c.channel === 'email' ? 'Email' : 'SMS'}`}
      className="border-border grid gap-3 rounded-lg border p-3"
    >
      <div className="flex flex-wrap items-center gap-2 text-sm font-medium">
        {c.channel === 'email' ? 'Email' : 'SMS'}
        {c.own ? (
          <Badge tone="neutral">Your wording</Badge>
        ) : (
          <Badge tone="neutral">Standard wording</Badge>
        )}
      </div>
      {c.channel === 'email' && (
        <Field label="Subject" error={errors.subject}>
          <Input
            id={`${id}-subject`}
            value={subject}
            maxLength={120}
            onChange={(e) => setSubject(e.target.value)}
          />
        </Field>
      )}
      <Field label="Message" error={errors.text ?? errors.channel}>
        <textarea
          ref={box}
          id={`${id}-text`}
          className="border-border bg-bg min-h-24 rounded-md border p-2 text-sm"
          value={text}
          onChange={(e) => setText(e.target.value)}
        />
      </Field>
      <div
        className="flex flex-wrap items-center gap-2 text-sm"
        role="group"
        aria-label="Insert a placeholder"
      >
        {m.placeholders.map((p) => (
          <button
            key={p}
            type="button"
            className="border-border rounded-full border px-2 py-0.5"
            onClick={() => insert(p)}
          >
            {`{${p}}`}
            {m.required.includes(p) ? ' (keep)' : ''}
          </button>
        ))}
      </div>
      {c.channel === 'sms' && (
        <p className="text-fg-muted text-sm" aria-live="polite">
          About {length} of {smsMax} characters · {smsParts(length)} SMS{' '}
          {smsParts(length) === 1 ? 'part' : 'parts'} per message (uses SMS credits)
        </p>
      )}
      <div className="bg-muted rounded-md p-3 text-sm">
        <p className="font-medium">Preview</p>
        {c.channel === 'email' && <p>Subject: {fill(subject, m.sample)}</p>}
        <p className="whitespace-pre-line">{fill(text, m.sample)}</p>
      </div>
      {errors.test && (
        <p role="alert" className="text-danger text-sm">
          {errors.test}
        </p>
      )}
      <div className="flex flex-wrap gap-2">
        <Button
          size="sm"
          onClick={() =>
            router.put(
              `${base}/${m.key}/${c.channel}`,
              { subject: c.channel === 'email' ? subject : null, text },
              opts,
            )
          }
        >
          Save
        </Button>
        {c.own && (
          <Button
            size="sm"
            variant="ghost"
            onClick={() => router.delete(`${base}/${m.key}/${c.channel}`, opts)}
          >
            Back to standard wording
          </Button>
        )}
        <Button
          size="sm"
          variant="secondary"
          onClick={() => router.post(`${base}/${m.key}/${c.channel}/test`, {}, opts)}
        >
          {c.channel === 'email' ? 'Send me a test email' : 'Send me a test SMS (uses credits)'}
        </Button>
      </div>
    </section>
  );
}

export default function Messages({ messages, smsMax }: { messages: Message[]; smsMax: number }) {
  const base = window.location.pathname.replace(/\/$/, '');
  const groups = [...new Set(messages.map((m) => m.group))];
  return (
    <AppShell title="Messages">
      <Head title="Messages" />
      <div className="grid max-w-4xl gap-6">
        <PageHeader
          title="Messages"
          description="What your customers receive. Reword emails and SMS in your own voice — the parts customers need (like the link to change a booking) stay. Saved wording is used straight away."
        />
        {groups.map((g) => (
          <section key={g} aria-label={g} className="grid gap-4">
            <h2 className="text-lg font-semibold">{g}</h2>
            {messages
              .filter((m) => m.group === g)
              .map((m) => (
                <Card key={m.key}>
                  <CardHeader>
                    <CardTitle className="flex flex-wrap items-center gap-2">
                      {m.label}
                      {m.optional &&
                        (m.enabled ? (
                          <Badge tone="success">On</Badge>
                        ) : (
                          <Badge tone="neutral">Off</Badge>
                        ))}
                    </CardTitle>
                  </CardHeader>
                  <CardContent className="grid gap-4">
                    {m.optional && (
                      <label className="flex items-center gap-2 text-sm">
                        <Checkbox
                          checked={m.enabled}
                          onCheckedChange={(on) =>
                            router.put(
                              `${base}/${m.key}`,
                              { enabled: on === true },
                              { preserveScroll: true },
                            )
                          }
                        />
                        Send this message
                      </label>
                    )}
                    {m.channels.map((c) => (
                      <ChannelEditor key={c.channel} m={m} c={c} smsMax={smsMax} />
                    ))}
                    {m.whatsapp && (
                      <p className="text-fg-muted text-sm">
                        WhatsApp uses wording approved by Meta for every WURK business, so it can’t
                        be changed here.
                      </p>
                    )}
                  </CardContent>
                </Card>
              ))}
          </section>
        ))}
      </div>
    </AppShell>
  );
}
