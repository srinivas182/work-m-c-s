import { useState } from 'react';
import { Button, Card, CardContent, CardHeader, CardTitle } from '@/design-system';
import { rand } from '@/lib/money';
import { PortalLayout } from '../components/PortalLayout';

interface Rewards {
  code: string;
  link: string;
  referred: { name: string; since: string }[];
  pending_cents: number;
  wallet_cents: number;
  expiry_months: number;
}
interface Props {
  business: string;
  name: string;
  rewards: Rewards | null;
  history: { type: string; summary: string; at: string }[];
}

const KIND: Record<string, string> = {
  order: 'Order',
  booking: 'Booking',
  class: 'Class',
  sale: 'In-store',
};

export default function Home({ business, name, rewards, history }: Props) {
  const [copied, setCopied] = useState(false);
  return (
    <PortalLayout
      business={business}
      title={name ? `Hi ${name.split(' ')[0]}` : 'Your account'}
      signedIn
    >
      {rewards && (
        <>
          <Card>
            <CardHeader>
              <CardTitle>Your rewards</CardTitle>
            </CardHeader>
            <CardContent className="grid gap-2 text-sm">
              <p className="text-3xl font-semibold">{rand(rewards.wallet_cents)}</p>
              <p>to spend with {business}.</p>
              {rewards.pending_cents > 0 && (
                <p className="text-fg-muted">
                  {rand(rewards.pending_cents)} more on its way — available once the return period
                  ends.
                </p>
              )}
              {rewards.expiry_months > 0 && (
                <p className="text-fg-muted">
                  Unused rewards expire {rewards.expiry_months} months after they become available.
                </p>
              )}
            </CardContent>
          </Card>
          <Card>
            <CardHeader>
              <CardTitle>Share and earn</CardTitle>
            </CardHeader>
            <CardContent className="grid gap-3 text-sm">
              <p>When someone you send buys from {business}, you earn a reward too.</p>
              <p className="font-mono break-all">{rewards.link}</p>
              <div className="flex flex-wrap gap-2">
                <Button
                  size="sm"
                  onClick={() =>
                    void navigator.clipboard?.writeText(rewards.link).then(() => setCopied(true))
                  }
                >
                  {copied ? 'Copied' : 'Copy your link'}
                </Button>
                <Button size="sm" variant="secondary" asChild>
                  <a href="/account/referral-qr.svg" download={`referral-${rewards.code}.svg`}>
                    Download your QR code
                  </a>
                </Button>
              </div>
              <img
                src="/account/referral-qr.svg"
                alt="Your referral QR code"
                width={160}
                height={160}
                className="rounded-md bg-white p-2"
              />
              {rewards.referred.length > 0 && (
                <>
                  <h3 className="font-medium">People you’ve referred</h3>
                  <ul aria-label="People you’ve referred" className="grid gap-1">
                    {rewards.referred.map((r, i) => (
                      <li key={i}>
                        {r.name} · since {r.since}
                      </li>
                    ))}
                  </ul>
                </>
              )}
            </CardContent>
          </Card>
        </>
      )}
      <Card>
        <CardHeader>
          <CardTitle>Your history</CardTitle>
        </CardHeader>
        <CardContent>
          {history.length === 0 ? (
            <p className="text-fg-muted text-sm">Nothing yet.</p>
          ) : (
            <ul aria-label="Your history" className="grid gap-2 text-sm">
              {history.map((h, i) => (
                <li key={i}>
                  <strong>{KIND[h.type] ?? h.type}</strong> · {h.summary} · {h.at}
                </li>
              ))}
            </ul>
          )}
        </CardContent>
      </Card>
    </PortalLayout>
  );
}
