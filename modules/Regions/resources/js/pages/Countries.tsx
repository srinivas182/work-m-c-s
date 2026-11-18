import { Head, useForm } from '@inertiajs/react';
import { Badge } from '@/design-system/components/badge';
import { Button } from '@/design-system/components/button';
import { Input } from '@/design-system/components/input';
import { PageHeader } from '@/design-system/components/feedback';
import { Switch } from '@/design-system/components/choice';
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
  timezone: string;
  enabled: boolean;
  vat: number;
}

function Row({ country }: { country: Country }) {
  const form = useForm({ vat: country.vat, enabled: country.enabled });
  const dirty = form.data.vat !== country.vat || form.data.enabled !== country.enabled;
  return (
    <TableRow>
      <TableCell>
        <span className="font-medium">{country.name}</span>{' '}
        <span className="text-fg-muted">· {country.code}</span>
      </TableCell>
      <TableCell>{country.currency}</TableCell>
      <TableCell className="tabular-nums">+{country.phone_prefix}</TableCell>
      <TableCell className="text-fg-muted">{country.timezone}</TableCell>
      <TableCell>
        <Input
          type="number"
          step="0.5"
          aria-label={`VAT for ${country.name} (%)`}
          className="w-24"
          value={form.data.vat}
          onChange={(e) => form.setData('vat', Number(e.target.value))}
        />
      </TableCell>
      <TableCell>
        <span className="flex items-center gap-2">
          <Switch
            aria-label={`WURK operates in ${country.name}`}
            checked={form.data.enabled}
            onCheckedChange={(v) => form.setData('enabled', v)}
          />
          {country.enabled ? (
            <Badge tone="success">{__('Live')}</Badge>
          ) : (
            <Badge>{__('Off')}</Badge>
          )}
        </span>
      </TableCell>
      <TableCell>
        <Button
          size="sm"
          variant="secondary"
          disabled={!dirty}
          loading={form.processing}
          onClick={() => form.put(`/countries/${country.code}`, { preserveScroll: true })}
        >
          {__('Save')}
        </Button>
      </TableCell>
    </TableRow>
  );
}

export default function Countries({ countries }: { countries: Country[] }) {
  return (
    <AppShell title={__('Countries')}>
      <Head title={__('Countries')} />
      <div className="grid gap-8">
        <PageHeader
          title={__('Countries')}
          description={__(
            'Where businesses can sign up, with each country’s currency and VAT. Confirm the VAT rate with a tax adviser before switching a country on.',
          )}
        />
        <Table label={__('Countries')}>
          <TableHeader>
            <TableRow>
              <TableHead>{__('Country')}</TableHead>
              <TableHead>{__('Currency')}</TableHead>
              <TableHead>{__('Phone')}</TableHead>
              <TableHead>{__('Time zone')}</TableHead>
              <TableHead>{__('VAT (%)')}</TableHead>
              <TableHead>{__('Open for sign-up')}</TableHead>
              <TableHead>
                <span className="sr-only">{__('Save')}</span>
              </TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {countries.map((c) => (
              <Row key={c.code} country={c} />
            ))}
          </TableBody>
        </Table>
      </div>
    </AppShell>
  );
}
