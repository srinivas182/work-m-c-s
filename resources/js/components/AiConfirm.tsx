import { Link } from '@inertiajs/react';
import { Sparkles } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button, Dialog, DialogContent, DialogFooter } from '@/design-system';

export interface AiQuote {
  label: string;
  credits: number;
  free: boolean;
  balance: number;
  after: number;
  allowed: boolean;
  reason: 'not_included' | 'insufficient' | 'limit' | null;
  can_buy: boolean;
}

const fmt = (n: number) => (Number.isInteger(n) ? String(n) : n.toFixed(1));

/**
 * Every paid WURK AI action asks first: the exact credits, the balance before and after.
 * If it can't run, it says why and offers Buy credits and/or Upgrade.
 */
export function AiConfirm({
  open,
  onOpenChange,
  quoteUrl,
  tenantBase,
  onConfirm,
  busy,
}: {
  open: boolean;
  onOpenChange: (o: boolean) => void;
  quoteUrl: string;
  tenantBase: string;
  onConfirm: () => void;
  busy: boolean;
}) {
  const [quote, setQuote] = useState<AiQuote | null>(null);
  useEffect(() => {
    if (!open) return;
    fetch(quoteUrl, { headers: { Accept: 'application/json' } })
      .then((r) => r.json())
      .then(setQuote)
      .catch(() => setQuote(null));
  }, [open, quoteUrl]);

  return (
    <Dialog
      open={open}
      onOpenChange={(o) => {
        if (!o) setQuote(null); // a fresh quote every time it opens
        onOpenChange(o);
      }}
    >
      <DialogContent
        title={quote ? `${quote.label}?` : 'Checking the price…'}
        description={
          quote
            ? quote.reason === 'not_included'
              ? 'WURK AI isn’t included in your package.'
              : quote.free
                ? 'Free — your first one is on us.'
                : `This uses ${fmt(quote.credits)} credits.`
            : undefined
        }
      >
        {quote && quote.reason !== 'not_included' && (
          <dl className="bg-surface grid grid-cols-2 gap-2 rounded-xl p-4 text-sm">
            <dt className="text-fg-muted">Your balance</dt>
            <dd className="text-right font-semibold tabular-nums">{fmt(quote.balance)} credits</dd>
            <dt className="text-fg-muted">After this</dt>
            <dd
              className={`text-right font-semibold tabular-nums ${quote.after < 0 ? 'text-danger-11' : ''}`}
            >
              {quote.after < 0 ? '—' : `${fmt(quote.after)} credits`}
            </dd>
          </dl>
        )}
        {quote?.reason === 'limit' && (
          <p role="alert" className="text-sm">
            You’ve reached your monthly spending limit. The owner can change it under Plan & usage.
          </p>
        )}
        {quote?.reason === 'insufficient' && (
          <p role="alert" className="text-sm">
            You need {fmt(quote.credits)} credits and have {fmt(quote.balance)}.
          </p>
        )}
        <DialogFooter>
          {quote?.allowed ? (
            <>
              <Button variant="ghost" onClick={() => onOpenChange(false)}>
                Cancel
              </Button>
              <Button onClick={onConfirm} loading={busy}>
                <Sparkles /> {quote.free ? 'Go ahead' : `Use ${fmt(quote.credits)} credits`}
              </Button>
            </>
          ) : quote ? (
            <>
              {quote.reason === 'insufficient' && quote.can_buy && (
                <Button variant="secondary" asChild>
                  <Link href={`${tenantBase}/settings/billing`}>Buy credits</Link>
                </Button>
              )}
              <Button asChild>
                <Link href={`${tenantBase}/settings/billing`}>Upgrade</Link>
              </Button>
            </>
          ) : null}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
