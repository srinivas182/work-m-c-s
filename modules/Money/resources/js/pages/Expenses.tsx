import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import {
  Button,
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  Field,
  Input,
  PageHeader,
} from '@/design-system';
import AppShell from '@/layouts/AppShell';
import { __ } from '@/i18n';

interface Props {
  profit: { took: number; spent: number; made: number; by_category: Record<string, number> };
  month: string;
  expenses: {
    id: string;
    spent_on: string;
    amount_cents: number;
    category: string;
    note: string | null;
    slip: boolean;
  }[];
  categories: Record<string, string>;
  today: string;
  slip: { id: string; amount: string; spent_on: string; category: string; note: string } | null;
  weekly: { on: boolean; phone: boolean };
  reasonAbove: number;
}

/** Rand as WURK writes it everywhere: R1 380.00 (the server's own format). */
const rand = (c: number) => `R${(c / 100).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ' ')}`;
const tenantBase = () => '/' + window.location.pathname.split('/')[1];

export default function Expenses({
  profit,
  month,
  expenses,
  categories,
  today,
  slip,
  weekly,
  reasonAbove,
}: Props) {
  const page = usePage();
  const warning = (page.props.flash as { warning?: string | null } | undefined)?.warning;
  const slipError = (page.props.errors as Record<string, string | undefined>).slip;
  const form = useForm({ amount: '', category: 'stock', spent_on: today, note: '', slip_id: '' });
  const photo = useForm<{ slip: File | null }>({ slip: null });
  const fileInput = useRef<HTMLInputElement>(null);
  useEffect(() => {
    if (slip) {
      form.setData({
        amount: slip.amount,
        category: slip.category,
        spent_on: slip.spent_on,
        note: slip.note,
        slip_id: slip.id,
      });
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [slip?.id]);
  return (
    <AppShell title={__('Expenses and profit')}>
      <Head title={__('Expenses and profit')} />
      <div className="grid max-w-3xl gap-6">
        <PageHeader
          title={__('Expenses and profit')}
          description={__('Add what you spend in seconds, and see what you made.')}
        />
        {warning && (
          <p role="alert" className="text-danger text-sm">
            {warning}
          </p>
        )}
        <Card>
          <CardHeader>
            <CardTitle>{month}</CardTitle>
          </CardHeader>
          <CardContent>
            <p className="text-lg">
              {__('This month you took')} <strong>{rand(profit.took)}</strong>
              {__(', spent')} <strong>{rand(profit.spent)}</strong>
              {__(', so you made about')}{' '}
              <strong className={profit.made < 0 ? 'text-danger' : ''}>{rand(profit.made)}</strong>.
            </p>
          </CardContent>
        </Card>
        <Card>
          <CardHeader>
            <CardTitle>{__('Add an expense')}</CardTitle>
          </CardHeader>
          <CardContent className="grid gap-4">
            <div className="grid gap-2 text-sm">
              <input
                ref={fileInput}
                type="file"
                accept="image/*"
                capture="environment"
                className="sr-only"
                aria-label={__('Photo of the slip')}
                onChange={(e) => {
                  const file = e.target.files?.[0];
                  if (file) {
                    photo.setData('slip', file);
                    router.post(
                      `${tenantBase()}/money/expenses/slip`,
                      { slip: file },
                      { forceFormData: true, preserveScroll: true },
                    );
                  }
                }}
              />
              <Button
                type="button"
                variant="secondary"
                className="justify-self-start"
                onClick={() => fileInput.current?.click()}
              >
                {__('Take a photo of the slip')}
              </Button>
              {slipError && <p className="text-danger">{slipError}</p>}
              <p className="text-fg-muted">
                {__(
                  'WURK AI reads the amount, date and what it was for (1 credit). You check it before it’s added.',
                )}
              </p>
            </div>
            <form
              className="grid gap-3"
              onSubmit={(e) => {
                e.preventDefault();
                form.post(`${tenantBase()}/money/expenses`, {
                  preserveScroll: true,
                  onSuccess: () => form.reset(),
                });
              }}
            >
              <div className="grid gap-3 sm:grid-cols-3">
                <Field label={__('Amount (R)')} error={form.errors.amount}>
                  <Input
                    inputMode="decimal"
                    value={form.data.amount}
                    onChange={(e) => form.setData('amount', e.target.value)}
                  />
                </Field>
                <Field label={__('Category')} error={form.errors.category}>
                  <select
                    className="border-border bg-bg min-h-10 rounded-md border px-3"
                    value={form.data.category}
                    onChange={(e) => form.setData('category', e.target.value)}
                  >
                    {Object.entries(categories).map(([k, v]) => (
                      <option key={k} value={k}>
                        {v}
                      </option>
                    ))}
                  </select>
                </Field>
                <Field label={__('Date')} error={form.errors.spent_on}>
                  <Input
                    type="date"
                    max={today}
                    value={form.data.spent_on}
                    onChange={(e) => form.setData('spent_on', e.target.value)}
                  />
                </Field>
              </div>
              <Field label={__('Note')} error={form.errors.note}>
                <Input
                  value={form.data.note}
                  onChange={(e) => form.setData('note', e.target.value)}
                />
              </Field>
              <Button type="submit" loading={form.processing} className="justify-self-start">
                {form.data.slip_id ? 'Add it (from the slip)' : 'Add the expense'}
              </Button>
            </form>
          </CardContent>
        </Card>
        {expenses.length > 0 && (
          <Card>
            <CardHeader>
              <CardTitle>{__('Expenses')}</CardTitle>
            </CardHeader>
            <CardContent>
              <ul className="grid gap-2 text-sm">
                {expenses.map((e) => (
                  <li key={e.id} className="flex flex-wrap items-center justify-between gap-2">
                    <span>
                      {e.spent_on} · {categories[e.category] ?? e.category} · {rand(e.amount_cents)}
                      {e.note ? ` · ${e.note}` : ''}
                    </span>
                    <span className="flex gap-2">
                      {e.slip && (
                        <a
                          className="underline"
                          href={`${tenantBase()}/money/expenses/${e.id}/slip`}
                          target="_blank"
                          rel="noopener noreferrer"
                        >
                          {__('Slip')}
                        </a>
                      )}
                      <Button
                        size="sm"
                        variant="ghost"
                        aria-label={`Remove expense: ${rand(e.amount_cents)} on ${e.spent_on}`}
                        onClick={() =>
                          router.delete(`${tenantBase()}/money/expenses/${e.id}`, {
                            preserveScroll: true,
                          })
                        }
                      >
                        {__('Remove')}
                      </Button>
                    </span>
                  </li>
                ))}
              </ul>
            </CardContent>
          </Card>
        )}
        <Card>
          <CardHeader>
            <CardTitle>{__('Monday summary and cash-ups')}</CardTitle>
          </CardHeader>
          <CardContent className="grid gap-3 text-sm">
            <label className="flex items-center gap-2">
              <input
                type="checkbox"
                checked={weekly.on}
                onChange={(e) =>
                  router.put(
                    `${tenantBase()}/money/settings`,
                    { weekly_summary: e.target.checked },
                    { preserveScroll: true },
                  )
                }
              />
              {__('Send me “how did I do” on WhatsApp every Monday')}
            </label>
            {!weekly.phone && (
              <p className="text-fg-muted">
                {__('Verify your mobile number in your account to get it.')}
              </p>
            )}
            <p className="text-fg-muted">
              {__('A cash-up asks for a reason when the cash is more than')} {rand(reasonAbove)}{' '}
              {__('out.')}
            </p>
          </CardContent>
        </Card>
      </div>
    </AppShell>
  );
}
