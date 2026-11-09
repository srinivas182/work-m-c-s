import { useForm } from '@inertiajs/react';
import { Button, Card, CardContent, Field, Input } from '@/design-system';
import { AgentLayout } from '../../components/AgentLayout';

interface Props {
  current: {
    holder: string;
    bank: string;
    branch_code: string;
    account_type: string;
    last4: string;
    changed_at: string;
  } | null;
  banks: string[];
  sent: string | null;
}

/** Banking details: confirmed with a code to the agent's phone before they're saved. */
export default function AgentBank({ current, banks, sent }: Props) {
  const form = useForm({
    holder: current?.holder ?? '',
    bank: current?.bank ?? '',
    branch_code: current?.branch_code ?? '',
    account_type: current?.account_type ?? 'cheque',
    account: '',
  });
  const code = useForm({ code: '' });
  return (
    <AgentLayout title="Banking details">
      {current && (
        <Card>
          <CardContent className="pt-6 text-sm">
            Paid into: {current.holder} · {current.bank} · account ending {current.last4} (changed{' '}
            {current.changed_at})
          </CardContent>
        </Card>
      )}
      {sent ? (
        <form
          aria-label="Confirm your banking details"
          className="grid gap-3"
          onSubmit={(e) => {
            e.preventDefault();
            code.post('/agent/bank/confirm');
          }}
        >
          <Field label={`Code sent to ${sent}`} error={code.errors.code}>
            <Input
              inputMode="numeric"
              autoComplete="one-time-code"
              value={code.data.code}
              onChange={(e) => code.setData('code', e.target.value)}
            />
          </Field>
          <Button type="submit" className="justify-self-start" loading={code.processing}>
            Save my banking details
          </Button>
        </form>
      ) : (
        <form
          aria-label="Your banking details"
          className="grid gap-3"
          onSubmit={(e) => {
            e.preventDefault();
            form.post('/agent/bank');
          }}
        >
          <Field label="Account holder" error={form.errors.holder}>
            <Input
              value={form.data.holder}
              onChange={(e) => form.setData('holder', e.target.value)}
            />
          </Field>
          <Field label="Bank" error={form.errors.bank}>
            <select
              className="border-border bg-bg h-10 w-full rounded-md border px-3"
              value={form.data.bank}
              onChange={(e) => form.setData('bank', e.target.value)}
            >
              <option value="">Choose your bank</option>
              {banks.map((b) => (
                <option key={b}>{b}</option>
              ))}
            </select>
          </Field>
          <Field label="Branch code" error={form.errors.branch_code}>
            <Input
              inputMode="numeric"
              value={form.data.branch_code}
              onChange={(e) => form.setData('branch_code', e.target.value)}
            />
          </Field>
          <Field label="Account type" error={form.errors.account_type}>
            <select
              className="border-border bg-bg h-10 w-full rounded-md border px-3"
              value={form.data.account_type}
              onChange={(e) => form.setData('account_type', e.target.value)}
            >
              <option value="cheque">Cheque / current</option>
              <option value="savings">Savings</option>
              <option value="transmission">Transmission</option>
            </select>
          </Field>
          <Field label="Account number" error={form.errors.account}>
            <Input
              inputMode="numeric"
              autoComplete="off"
              value={form.data.account}
              onChange={(e) => form.setData('account', e.target.value)}
            />
          </Field>
          <p className="text-fg-muted text-sm">
            We’ll send a code to your phone to confirm it’s you. Your account number is stored
            encrypted.
          </p>
          <Button type="submit" className="justify-self-start" loading={form.processing}>
            Send me a code
          </Button>
        </form>
      )}
    </AgentLayout>
  );
}
