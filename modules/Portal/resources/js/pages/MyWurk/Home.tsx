import { router } from '@inertiajs/react';
import { Button, Card, CardContent, CardHeader, CardTitle } from '@/design-system';
import { rand } from '@/lib/money';
import { MyWurkLayout } from '../../components/MyWurkLayout';

interface BusinessCard {
  handle: string;
  business: string;
  wallet_cents: number | null;
  pending_cents: number | null;
  latest: string | null;
}

/** One card per business — each built from that business only. */
export default function MyWurkHome({ cards }: { cards: BusinessCard[] }) {
  return (
    <MyWurkLayout title="Your businesses" signedIn>
      {cards.length === 0 ? (
        <p className="text-sm">
          When you sign in to a business’s account page on WURK, it appears here.
        </p>
      ) : (
        <ul aria-label="Your businesses" className="grid gap-4">
          {cards.map((c) => (
            <li key={c.handle}>
              <Card>
                <CardHeader>
                  <CardTitle>{c.business}</CardTitle>
                </CardHeader>
                <CardContent className="grid gap-2 text-sm">
                  {c.wallet_cents !== null && (
                    <p>
                      Wallet: <strong>{rand(c.wallet_cents)}</strong>
                      {c.pending_cents ? ` · ${rand(c.pending_cents)} on its way` : ''}
                    </p>
                  )}
                  {c.latest && <p className="text-fg-muted">Latest: {c.latest}</p>}
                  <div className="flex flex-wrap gap-2">
                    <Button size="sm" asChild>
                      <a href={`/open/${c.handle}`}>Open</a>
                    </Button>
                    <Button
                      size="sm"
                      variant="ghost"
                      onClick={() => router.post(`/hide/${c.handle}`, {}, { preserveScroll: true })}
                    >
                      Remove from My WURK
                    </Button>
                  </div>
                </CardContent>
              </Card>
            </li>
          ))}
        </ul>
      )}
    </MyWurkLayout>
  );
}
