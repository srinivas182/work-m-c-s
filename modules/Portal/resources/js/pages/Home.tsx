import { useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Button, Card, CardContent, CardHeader, CardTitle, Checkbox } from '@/design-system';
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
  deletion: {
    status: 'open' | 'completed' | 'declined';
    reason: string | null;
    asked: string;
  } | null;
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

export default function Home({ business, name, rewards, history, deletion }: Props) {
  const [copied, setCopied] = useState(false);
  const [idVersion, setIdVersion] = useState(0);
  useEffect(() => {
    const t = window.setInterval(() => setIdVersion((v) => v + 1), 4 * 60 * 1000); // codes last 5 minutes
    return () => window.clearInterval(t);
  }, []);
  return (
    <PortalLayout
      business={business}
      title={name ? `Hi ${name.split(' ')[0]}` : 'Your account'}
      signedIn
    >
      <Card>
        <CardHeader>
          <CardTitle>Show this at the till</CardTitle>
        </CardHeader>
        <CardContent className="grid gap-2 text-sm">
          <p>
            So {business} can add your in-store purchases to your account. It changes every few
            minutes.
          </p>
          <img
            src={`/account/id-qr.svg?v=${idVersion}`}
            alt="Your code for the till"
            width={200}
            height={200}
            className="rounded-md bg-white p-2"
          />
        </CardContent>
      </Card>
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
      <YourInformation business={business} deletion={deletion} />
    </PortalLayout>
  );
}

/** POPIA: a copy of what the business holds, and asking for it to be deleted (Sprint 13.6e). */
function YourInformation({
  business,
  deletion,
}: {
  business: string;
  deletion: Props['deletion'];
}) {
  const [asking, setAsking] = useState(false);
  const form = useForm({ confirm: false });
  return (
    <Card>
      <CardHeader>
        <CardTitle>Your information</CardTitle>
      </CardHeader>
      <CardContent className="grid gap-3 text-sm">
        <a className="underline" href="/account/my-information.json" download>
          Download a copy of your information
        </a>
        {deletion?.status === 'open' ? (
          <p role="status">
            You asked {business} to delete your information on {deletion.asked}. We’ll email you
            when it’s done.
          </p>
        ) : deletion?.status === 'declined' && !asking ? (
          <p>
            {business} couldn’t delete your information: {deletion.reason}
          </p>
        ) : !asking ? (
          <Button
            size="sm"
            variant="ghost"
            className="justify-self-start"
            onClick={() => setAsking(true)}
          >
            Ask {business} to delete my information
          </Button>
        ) : (
          <form
            aria-label="Ask for my information to be deleted"
            className="grid gap-3"
            onSubmit={(e) => {
              e.preventDefault();
              form.post('/account/delete-request', { preserveScroll: true });
            }}
          >
            <p>If {business} approves it:</p>
            <ul className="grid list-disc gap-1 pl-5">
              <li>any money in your wallet is paid out or refunded to you first;</li>
              <li>rewards that aren’t available yet are cancelled;</li>
              <li>
                your name and contact details are removed, and this account and your referral link
                stop working;
              </li>
              <li>
                records the law requires them to keep (such as invoices and patient records) are
                kept for as long as required.
              </li>
            </ul>
            <label className="flex items-start gap-2">
              <Checkbox
                checked={form.data.confirm}
                onCheckedChange={(on) => form.setData('confirm', on === true)}
              />
              I understand — please delete my information.
            </label>
            {form.errors.confirm && (
              <p role="alert" className="text-danger">
                {form.errors.confirm}
              </p>
            )}
            <div className="flex gap-2">
              <Button size="sm" type="submit" loading={form.processing}>
                Send my request
              </Button>
              <Button size="sm" variant="ghost" onClick={() => setAsking(false)}>
                Cancel
              </Button>
            </div>
          </form>
        )}
      </CardContent>
    </Card>
  );
}
