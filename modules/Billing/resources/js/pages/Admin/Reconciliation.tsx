import { Head, router, useForm } from '@inertiajs/react';
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
import { rand } from '@/lib/money';

interface Result {
  matchedBy: string;
  statement: number;
  wurk: number;
  matched: number;
  statementOnly: number;
  wurkOnly: number;
  unreadable: number;
  statementCents: number | null;
  expectedCents: number | null;
  differenceCents: number | null;
  statementOnlyRows: string[][];
  wurkOnlyRows: string[][];
  driver: string;
  from: string;
  to: string;
}
interface Props {
  drivers: string[];
  upload: { name: string; columns: string[]; sample: string[][]; rows: number } | null;
  result: Result | null;
}

const table = (label: string, columns: string[], rows: string[][]) => (
  <div className="overflow-x-auto" tabIndex={0} role="region" aria-label={label}>
    <table className="w-full text-left text-sm">
      <thead>
        <tr>
          {columns.map((c, i) => (
            <th key={i} scope="col">
              {c}
            </th>
          ))}
        </tr>
      </thead>
      <tbody>
        {rows.map((r, i) => (
          <tr key={i}>
            {r.map((v, j) => (
              <td key={j}>{v}</td>
            ))}
          </tr>
        ))}
      </tbody>
    </table>
  </div>
);

/** Admin → Reconciliation: a provider's statement against what WURK sent. */
export default function Reconciliation({ drivers, upload, result }: Props) {
  const base = window.location.pathname.replace(/\/$/, '');
  const file = useForm<{ statement: File | null }>({ statement: null });
  const map = useForm({
    driver: drivers[0] ?? '',
    from: '',
    to: '',
    id: '',
    number: '',
    date: '',
    cost: '',
  });
  const select = (key: 'id' | 'number' | 'date' | 'cost', label: string) => (
    <Field label={label} error={(map.errors as Record<string, string>)[key]}>
      <select
        className="border-border bg-bg h-10 w-full rounded-md border px-3"
        value={map.data[key]}
        onChange={(e) => map.setData(key, e.target.value)}
      >
        <option value="">—</option>
        {upload?.columns.map((c, i) => (
          <option key={i} value={i}>
            {c || `Column ${i + 1}`}
          </option>
        ))}
      </select>
    </Field>
  );
  return (
    <AppShell title="Reconciliation">
      <Head title="Reconciliation" />
      <div className="grid max-w-4xl gap-6">
        <PageHeader
          title="Reconciliation"
          description="Check a provider’s statement against what WURK sent. The statement is kept for 30 minutes at most, encrypted, and never stored — clear it when you’re done."
        />
        {upload === null ? (
          <Card>
            <CardContent className="pt-6">
              <form
                aria-label="Upload a statement"
                className="grid gap-3 text-sm"
                onSubmit={(e) => {
                  e.preventDefault();
                  file.post(base, { forceFormData: true });
                }}
              >
                <Field label="Provider statement (CSV)" error={file.errors.statement}>
                  <input
                    type="file"
                    accept=".csv,text/csv"
                    onChange={(e) => file.setData('statement', e.target.files?.[0] ?? null)}
                  />
                </Field>
                <Button type="submit" className="justify-self-start" loading={file.processing}>
                  Upload
                </Button>
              </form>
            </CardContent>
          </Card>
        ) : (
          <Card>
            <CardHeader>
              <CardTitle>
                {upload.name} — {upload.rows} lines
              </CardTitle>
            </CardHeader>
            <CardContent className="grid gap-4 text-sm">
              {table('First lines of the statement', upload.columns, upload.sample)}
              <form
                aria-label="Match the statement"
                className="grid gap-3 sm:grid-cols-2"
                onSubmit={(e) => {
                  e.preventDefault();
                  map.post(`${base}/run`, { preserveScroll: true });
                }}
              >
                <Field label="Provider" error={map.errors.driver}>
                  <select
                    className="border-border bg-bg h-10 w-full rounded-md border px-3"
                    value={map.data.driver}
                    onChange={(e) => map.setData('driver', e.target.value)}
                  >
                    {drivers.map((d) => (
                      <option key={d} value={d}>
                        {d}
                      </option>
                    ))}
                  </select>
                </Field>
                <div className="grid grid-cols-2 gap-2">
                  <Field label="From" error={map.errors.from}>
                    <Input
                      type="date"
                      value={map.data.from}
                      onChange={(e) => map.setData('from', e.target.value)}
                    />
                  </Field>
                  <Field label="To" error={map.errors.to}>
                    <Input
                      type="date"
                      value={map.data.to}
                      onChange={(e) => map.setData('to', e.target.value)}
                    />
                  </Field>
                </div>
                {select('id', 'Message ID column (best)')}
                {select('cost', 'Cost column (optional)')}
                {select('number', 'Or: number column')}
                {select('date', 'and date column')}
                <div className="flex gap-2 sm:col-span-2">
                  <Button type="submit" loading={map.processing}>
                    Check
                  </Button>
                  <Button
                    type="button"
                    variant="ghost"
                    onClick={() => router.post(`${base}/clear`)}
                  >
                    Clear the statement
                  </Button>
                </div>
              </form>
            </CardContent>
          </Card>
        )}
        {result && (
          <Card>
            <CardHeader>
              <CardTitle>
                {result.driver}, {result.from} to {result.to} (matched by {result.matchedBy})
              </CardTitle>
            </CardHeader>
            <CardContent className="grid gap-3 text-sm">
              <ul aria-label="Reconciliation result" className="grid gap-1">
                <li>
                  Statement lines: {result.statement}; WURK sent: {result.wurk}; matched:{' '}
                  {result.matched}.
                </li>
                <li>On the statement only (billed, not in WURK): {result.statementOnly}.</li>
                <li>In WURK only (sent, not billed): {result.wurkOnly}.</li>
                {result.unreadable > 0 && (
                  <li>Lines that couldn’t be read: {result.unreadable}.</li>
                )}
                {result.statementCents !== null && (
                  <li>Statement total: {rand(result.statementCents)}.</li>
                )}
                <li>
                  {result.expectedCents === null
                    ? 'Expected cost: set the provider cost in Profitability.'
                    : `Expected from WURK’s costs: ${rand(result.expectedCents)}.`}
                </li>
                {result.differenceCents !== null && (
                  <li>
                    Difference:{' '}
                    {(result.differenceCents >= 0 ? '+' : '') + rand(result.differenceCents)}.
                  </li>
                )}
              </ul>
              {result.statementOnlyRows.length > 0 &&
                table(
                  'On the statement only',
                  ['Date', 'Provider message ID', 'Cost'],
                  result.statementOnlyRows.slice(0, 50),
                )}
              {result.wurkOnlyRows.length > 0 &&
                table(
                  'In WURK only',
                  ['Date', 'Business', 'Provider message ID'],
                  result.wurkOnlyRows.slice(0, 50),
                )}
              {(result.statementOnly > 0 || result.wurkOnly > 0) && (
                <a className="justify-self-start underline" href={`${base}/export.csv`}>
                  Download unmatched (CSV)
                </a>
              )}
            </CardContent>
          </Card>
        )}
      </div>
    </AppShell>
  );
}
