import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import {
  Badge,
  Button,
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
  EmptyState,
  PageHeader,
} from '@/design-system';
import AppShell from '@/layouts/AppShell';
import { MessageCircle } from 'lucide-react';

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
export default function WhatsApp({ available, connection, base }: Props) {
  const [busy, setBusy] = useState(false);
  const quality = connection?.quality ? QUALITY[connection.quality] : undefined;
  const connect = (coexistence: boolean) => {
    setBusy(true);
    // A full page visit: Meta's sign-up runs on Meta's site and sends the owner back here.
    router.post(`${base}/connect`, { coexistence }, { onFinish: () => setBusy(false) });
  };

  return (
    <AppShell title="WhatsApp">
      <Head title="WhatsApp" />
      <div className="grid max-w-3xl gap-6">
        <PageHeader
          title="WhatsApp"
          description="Answer customers’ WhatsApp messages in Chats, with your team and the AI receptionist — on your own number."
        />
        {!available ? (
          <EmptyState
            icon={MessageCircle}
            title="Coming soon"
            description="WURK is finishing its set-up with Meta. You’ll be able to connect your number here."
          />
        ) : connection === null ? (
          <Card>
            <CardHeader>
              <CardTitle>Connect your WhatsApp number</CardTitle>
              <CardDescription>
                Meta’s sign-up opens. Sign in with Facebook, choose your business and your number.
                It takes about five minutes.
              </CardDescription>
            </CardHeader>
            <CardContent className="grid gap-5">
              <div className="grid gap-2">
                <Button loading={busy} onClick={() => connect(true)}>
                  Connect — keep using the WhatsApp Business app
                </Button>
                <p className="text-fg-muted text-sm">
                  Recommended. Your phone keeps working as before; WURK sees the same chats.
                </p>
              </div>
              <div className="grid gap-2">
                <Button variant="secondary" disabled={busy} onClick={() => connect(false)}>
                  Connect a new number
                </Button>
                <p className="text-fg-muted text-sm">
                  For a number not yet on WhatsApp. It then works only through WURK.
                </p>
              </div>
              <ul className="text-fg-muted list-disc pl-5 text-sm">
                <li>You need a Facebook account and your business’s details.</li>
                <li>
                  Meta asks for a payment method on your WhatsApp account; Meta charges for some
                  messages. Customers’ messages to you are free.
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
                <Badge tone="success">Connected</Badge>
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
                  Messages you can start each day:{' '}
                  {connection.messaging_limit.replace('TIER_', '').replace('UNLIMITED', 'no limit')}{' '}
                  customers. Replies to customers who wrote to you don’t count.
                </p>
              )}
              <p className="text-muted-foreground text-sm">
                WhatsApp allows free replies for 24 hours after a customer writes. After that, Chats
                offers your follow-up message (“Hi Thandi, this is your business following up on
                your message…”) once Meta approves it.
              </p>
              <div className="flex flex-wrap gap-2">
                <Button
                  variant="secondary"
                  size="sm"
                  onClick={() => router.post(`${base}/refresh`, {}, { preserveScroll: true })}
                >
                  Check with Meta
                </Button>
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => router.delete(base, { preserveScroll: true })}
                >
                  Disconnect
                </Button>
              </div>
            </CardContent>
          </Card>
        )}
      </div>
    </AppShell>
  );
}
