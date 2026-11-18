import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import {
  Badge,
  Button,
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
  Checkbox,
  EmptyState,
  Field,
  Input,
  PageHeader,
} from '@/design-system';
import AppShell from '@/layouts/AppShell';
import { MessageCircle } from 'lucide-react';
import { __ } from '@/i18n';

interface Connection {
  display_phone: string | null;
  verified_name: string | null;
  coexistence: boolean;
  name_status: string | null;
  quality: string | null;
  messaging_limit: string | null;
  follow_up_status: string | null;
  connected_at: string | null;
  checked_at: string | null;
}

interface Props {
  available: boolean;
  connection: Connection | null;
  base: string;
  /** Click-to-chat (21.5): the link the website's WhatsApp button uses, and its QR code (SVG). */
  chat?: { text: string; link: string; qr: string } | null;
  /** The owner's evening summary (21.6); only owners see it. */
  summary?: { enabled: boolean; send_at: string; phone: string | null } | null;
}

const QUALITY: Record<string, { label: string; tone: 'success' | 'warning' | 'danger' }> = {
  GREEN: { label: 'High quality', tone: 'success' },
  YELLOW: { label: 'Medium quality — fewer blocks and reports keep it up', tone: 'warning' },
  RED: { label: 'Low quality — Meta may limit messages', tone: 'danger' },
};

const NAME: Record<string, string> = {
  APPROVED: 'Display name approved',
  PENDING_REVIEW: 'Display name waiting for Meta’s review',
  DECLINED: 'Display name declined — change it in Meta Business Settings',
};

const FOLLOW_UP: Record<string, { label: string; tone: 'success' | 'warning' | 'danger' }> = {
  APPROVED: { label: 'Follow-up message approved', tone: 'success' },
  PENDING: { label: 'Follow-up message waiting for Meta’s review', tone: 'warning' },
  REJECTED: { label: 'Follow-up message declined by Meta', tone: 'danger' },
  PAUSED: { label: 'Follow-up message paused by Meta', tone: 'warning' },
};

/** Settings → WhatsApp (Sprint 21.1): connect the business's own number, usually keeping the WhatsApp Business app. */
export default function WhatsApp({
  available,
  connection,
  base,
  chat = null,
  summary = null,
}: Props) {
  const [busy, setBusy] = useState(false);
  const quality = connection?.quality ? QUALITY[connection.quality] : undefined;
  const connect = (coexistence: boolean) => {
    setBusy(true);
    // A full page visit: Meta's sign-up runs on Meta's site and sends the owner back here.
    router.post(`${base}/connect`, { coexistence }, { onFinish: () => setBusy(false) });
  };

  return (
    <AppShell title={__('WhatsApp')}>
      <Head title={__('WhatsApp')} />
      <div className="grid max-w-3xl gap-6">
        <PageHeader
          title={__('WhatsApp')}
          description={__(
            'Answer customers’ WhatsApp messages in Chats, with your team and the AI receptionist — on your own number.',
          )}
        />
        {!available ? (
          <EmptyState
            icon={MessageCircle}
            title={__('Coming soon')}
            description={__(
              'WURK is finishing its set-up with Meta. You’ll be able to connect your number here.',
            )}
          />
        ) : connection === null ? (
          <Card>
            <CardHeader>
              <CardTitle>{__('Connect your WhatsApp number')}</CardTitle>
              <CardDescription>
                {__(
                  'Meta’s sign-up opens. Sign in with Facebook, choose your business and your number. It takes about five minutes.',
                )}
              </CardDescription>
            </CardHeader>
            <CardContent className="grid gap-5">
              <div className="grid gap-2">
                <Button loading={busy} onClick={() => connect(true)}>
                  {__('Connect — keep using the WhatsApp Business app')}
                </Button>
                <p className="text-fg-muted text-sm">
                  {__('Recommended. Your phone keeps working as before; WURK sees the same chats.')}
                </p>
              </div>
              <div className="grid gap-2">
                <Button variant="secondary" disabled={busy} onClick={() => connect(false)}>
                  {__('Connect a new number')}
                </Button>
                <p className="text-fg-muted text-sm">
                  {__('For a number not yet on WhatsApp. It then works only through WURK.')}
                </p>
              </div>
              <ul className="text-fg-muted list-disc pl-5 text-sm">
                <li>{__('You need a Facebook account and your business’s details.')}</li>
                <li>
                  {__(
                    'Meta asks for a payment method on your WhatsApp account; Meta charges for some messages. Customers’ messages to you are free.',
                  )}
                </li>
              </ul>
            </CardContent>
          </Card>
        ) : (
          <Card>
            <CardHeader>
              <CardTitle>{connection.verified_name ?? 'Your WhatsApp number'}</CardTitle>
              <CardDescription>
                {connection.display_phone}
                {connection.coexistence ? ' · also in the WhatsApp Business app' : ''}
              </CardDescription>
            </CardHeader>
            <CardContent className="grid gap-4">
              <div className="flex flex-wrap gap-2">
                <Badge tone="success">{__('Connected')}</Badge>
                {connection.name_status && (
                  <Badge tone={connection.name_status === 'APPROVED' ? 'success' : 'warning'}>
                    {NAME[connection.name_status] ?? connection.name_status}
                  </Badge>
                )}
                {quality && <Badge tone={quality.tone}>{quality.label}</Badge>}
                {connection.follow_up_status && (
                  <Badge tone={FOLLOW_UP[connection.follow_up_status]?.tone ?? 'warning'}>
                    {FOLLOW_UP[connection.follow_up_status]?.label ??
                      `Follow-up message: ${connection.follow_up_status.toLowerCase()}`}
                  </Badge>
                )}
              </div>
              {connection.messaging_limit && (
                <p className="text-sm">
                  {__('Messages you can start each day:')}{' '}
                  {connection.messaging_limit.replace('TIER_', '').replace('UNLIMITED', 'no limit')}{' '}
                  {__('customers. Replies to customers who wrote to you don’t count.')}
                </p>
              )}
              <p className="text-muted-foreground text-sm">
                {__(
                  'WhatsApp allows free replies for 24 hours after a customer writes. After that, Chats offers your follow-up message (“Hi Thandi, this is your business following up on your message…”) once Meta approves it.',
                )}
              </p>
              <div className="flex flex-wrap gap-2">
                <Button
                  variant="secondary"
                  size="sm"
                  onClick={() => router.post(`${base}/refresh`, {}, { preserveScroll: true })}
                >
                  {__('Check with Meta')}
                </Button>
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => router.delete(base, { preserveScroll: true })}
                >
                  {__('Disconnect')}
                </Button>
              </div>
            </CardContent>
          </Card>
        )}
        {chat && <ChatLink chat={chat} base={base} />}
        {summary && <EveningSummary summary={summary} base={base} />}
      </div>
    </AppShell>
  );
}

/** Click-to-chat (21.5): the website's WhatsApp button opens this; the QR code goes on counters, flyers and vans. */
function ChatLink({ chat, base }: { chat: NonNullable<Props['chat']>; base: string }) {
  const form = useForm({ text: chat.text });
  const qr = `data:image/svg+xml;charset=utf-8,${encodeURIComponent(chat.qr)}`;
  return (
    <Card>
      <CardHeader>
        <CardTitle>{__('Your WhatsApp link')}</CardTitle>
        <CardDescription>
          {__(
            'Your website’s WhatsApp button opens a chat with this message ready to send. Print the QR code for your counter, flyers or car.',
          )}
        </CardDescription>
      </CardHeader>
      <CardContent className="grid gap-4 sm:grid-cols-[auto_1fr] sm:items-start">
        <img
          src={qr}
          alt={__('QR code that opens a WhatsApp chat with you')}
          width={160}
          height={160}
        />
        <div className="grid gap-3">
          <form
            aria-label={__('The message customers start with')}
            className="grid gap-2"
            onSubmit={(e) => {
              e.preventDefault();
              form.put(`${base}/chat-link`, { preserveScroll: true });
            }}
          >
            <Field label={__('The message customers start with')} error={form.errors.text}>
              <Input
                value={form.data.text}
                maxLength={160}
                onChange={(e) => form.setData('text', e.target.value)}
              />
            </Field>
            <Button
              type="submit"
              size="sm"
              className="justify-self-start"
              loading={form.processing}
            >
              {__('Save')}
            </Button>
          </form>
          <p className="text-sm break-all">
            <a className="underline" href={chat.link} target="_blank" rel="noopener noreferrer">
              {chat.link}
            </a>
          </p>
          <a className="text-sm underline" href={qr} download="whatsapp-qr-code.svg">
            {__('Download the QR code')}
          </a>
        </div>
      </CardContent>
    </Card>
  );
}

/**
 * The owner's evening summary (21.6): tomorrow's bookings, today's orders and money, chats waiting, and requests to
 * answer by replying with a number — from WURK's WhatsApp number to the owner's own, verified mobile.
 */
function EveningSummary({
  summary,
  base,
}: {
  summary: NonNullable<Props['summary']>;
  base: string;
}) {
  const form = useForm({ enabled: summary.enabled, send_at: summary.send_at });
  return (
    <Card>
      <CardHeader>
        <CardTitle>{__('Your evening summary')}</CardTitle>
        <CardDescription>
          {__(
            'Every evening on WhatsApp: tomorrow’s bookings, today’s orders and payments, chats waiting, and anything that needs your yes or no. Reply with a number to say yes, or N and the number to say no. It comes from WURK’s WhatsApp number and costs one message in credits.',
          )}
        </CardDescription>
      </CardHeader>
      <CardContent>
        <form
          aria-label={__('Your evening summary')}
          className="grid gap-4"
          onSubmit={(e) => {
            e.preventDefault();
            form.put(`${base}/summary`, { preserveScroll: true });
          }}
        >
          <label className="flex items-center gap-2 text-sm">
            <Checkbox
              checked={form.data.enabled}
              onCheckedChange={(on) => form.setData('enabled', on === true)}
            />
            {__('Send me the summary')}
            {summary.phone ? ` on ${summary.phone}` : ''}
          </label>
          {form.errors.enabled && (
            <p role="alert" className="text-danger-11 text-sm">
              {form.errors.enabled}
            </p>
          )}
          <Field label={__('At')} error={form.errors.send_at}>
            <Input
              type="time"
              className="max-w-40"
              value={form.data.send_at}
              onChange={(e) => form.setData('send_at', e.target.value)}
            />
          </Field>
          <Button type="submit" size="sm" className="justify-self-start" loading={form.processing}>
            {__('Save')}
          </Button>
        </form>
      </CardContent>
    </Card>
  );
}
