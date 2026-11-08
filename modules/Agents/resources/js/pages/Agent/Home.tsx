import { Link } from '@inertiajs/react';
import { Card, CardContent } from '@/design-system';
import { rand } from '@/lib/money';
import { AgentLayout } from '../../components/AgentLayout';

interface Props {
  code: string;
  link: string;
  status: 'active' | 'suspended';
  commission: { pending: number; available: number; paid_out: number };
  hasBank: boolean;
}

/** The agent area's home (14.6 adds the full dashboard). */
export default function AgentHome({ code, link, status, commission, hasBank }: Props) {
  return (
    <AgentLayout title="Your agent area">
      {status === 'suspended' && (
        <p role="alert" className="text-danger text-sm">
          Your agent account is suspended. Contact WURK.
        </p>
      )}
      <Card>
        <CardContent className="grid gap-2 pt-6 text-sm">
          <p>
            Your link: <span className="font-mono break-all">{link}</span> (code{' '}
            <span className="font-mono">{code}</span>)
          </p>
          <p>
            Commission: <strong>{rand(commission.available)}</strong> available ·{' '}
            {rand(commission.pending)} pending · {rand(commission.paid_out)} paid out
          </p>
          {!hasBank && (
            <p>
              <Link className="underline" href="/agent/bank">
                Add your banking details
              </Link>{' '}
              so WURK can pay you.
            </p>
          )}
        </CardContent>
      </Card>
    </AgentLayout>
  );
}
