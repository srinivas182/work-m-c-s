import { Head, Link } from '@inertiajs/react';
import { Badge } from '@/design-system/components/badge';
import { PageHeader } from '@/design-system/components/feedback';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/design-system/components/table';
import AppShell from '@/layouts/AppShell';
import { __ } from '@/i18n';

interface Country {
  code: string;
  name: string;
  currency: string;
  phone_prefix: string;
  enabled: boolean;
  tax: { name: string; total: number; confirmed: boolean } | null;
}

/** Admin → Countries (Sprint 25.1): every country pack, its tax today, and whether it's open for sign-up. */
export default function Countries({ countries }: { countries: Country[] }) {
  return (
    <AppShell title={__('Countries')}>
      <Head title={__('Countries')} />
      <div className="grid gap-8">
        <PageHeader
          title={__('Countries')}
          description={__(
            'Each country’s pack: currency, tax, phone format, languages and providers. A country opens for sign-up once its tax rate is confirmed.',
          )}
        />
        <Table label={__('Countries')}>
          <TableHeader>
            <TableRow>
              <TableHead>{__('Country')}</TableHead>
              <TableHead>{__('Currency')}</TableHead>
              <TableHead>{__('Phone')}</TableHead>
              <TableHead>{__('Tax today')}</TableHead>
              <TableHead>{__('Sign-up')}</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {countries.map((c) => (
              <TableRow key={c.code}>
                <TableCell>
                  <Link className="font-medium underline" href={`/countries/${c.code}`}>
                    {c.name}
                  </Link>{' '}
                  <span className="text-fg-muted">· {c.code}</span>
                </TableCell>
                <TableCell>{c.currency}</TableCell>
                <TableCell className="tabular-nums">+{c.phone_prefix}</TableCell>
                <TableCell>
                  {c.tax ? (
                    <span className="flex items-center gap-2 tabular-nums">
                      {c.tax.name} {c.tax.total}%
                      {!c.tax.confirmed && <Badge tone="warning">{__('To confirm')}</Badge>}
                    </span>
                  ) : (
                    <span className="text-fg-muted">{__('No rate')}</span>
                  )}
                </TableCell>
                <TableCell>
                  {c.enabled ? (
                    <Badge tone="success">{__('Open')}</Badge>
                  ) : (
                    <Badge>{__('Closed')}</Badge>
                  )}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>
    </AppShell>
  );
}
