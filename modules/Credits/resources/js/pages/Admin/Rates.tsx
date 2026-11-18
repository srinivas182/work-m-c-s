import { Head, useForm } from '@inertiajs/react';
import { Button } from '@/design-system/components/button';
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/design-system/components/card';
import { Field } from '@/design-system/components/field';
import { Input } from '@/design-system/components/input';
import { PageHeader } from '@/design-system/components/feedback';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/design-system/components/select';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/design-system/components/table';
import AppShell from '@/layouts/AppShell';
import { credits } from '../Summary';
import { __ } from '@/i18n';

interface Props {
  channels: Record<string, string>;
  countries: { code: string; name: string; rates: Record<string, number | null> }[];
  history: { country: string; channel: string; credits: number; from: string }[];
}

export default function Rates({ channels, countries, history }: Props) {
  const form = useForm({
    country: countries[0]?.code ?? 'ZA',
    channel: 'sms',
    credits: '',
    effective_from: '',
  });
  return (
    <AppShell title={__('Credit rates')}>
      <Head title={__('Credit rates')} />
      <div className="grid max-w-5xl gap-8">
        <PageHeader
          title={__('Credit rates')}
          description={__(
            'How many WURK Credits each message or action costs, per country. Changes apply from their start date; past usage keeps its price.',
          )}
        />
        <Table label={__('Current rates')}>
          <TableHeader>
            <TableRow>
              <TableHead>{__('Country')}</TableHead>
              {Object.values(channels).map((c) => (
                <TableHead key={c} className="text-right">
                  {c}
                </TableHead>
              ))}
            </TableRow>
          </TableHeader>
          <TableBody>
            {countries.map((c) => (
              <TableRow key={c.code}>
                <TableCell className="font-medium">{c.name}</TableCell>
                {Object.keys(channels).map((ch) => (
                  <TableCell key={ch} className="text-right tabular-nums">
                    {c.rates[ch] === null || c.rates[ch] === undefined ? '—' : credits(c.rates[ch])}
                  </TableCell>
                ))}
              </TableRow>
            ))}
          </TableBody>
        </Table>
        <Card>
          <CardHeader>
            <CardTitle>{__('Change a rate')}</CardTitle>
            <CardDescription>{__('Leave the start date empty to apply it now.')}</CardDescription>
          </CardHeader>
          <CardContent>
            <form
              onSubmit={(e) => {
                e.preventDefault();
                form.post('/credits/rates', {
                  preserveScroll: true,
                  onSuccess: () => form.reset('credits', 'effective_from'),
                });
              }}
              className="grid grid-cols-1 items-end gap-4 sm:grid-cols-[1fr_1fr_8rem_10rem_auto]"
            >
              <Field label={__('Country')}>
                {(control) => (
                  <Select
                    value={form.data.country}
                    onValueChange={(v) => form.setData('country', v)}
                  >
                    <SelectTrigger {...control}>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {countries.map((c) => (
                        <SelectItem key={c.code} value={c.code}>
                          {c.name}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                )}
              </Field>
              <Field label={__('Channel')}>
                {(control) => (
                  <Select
                    value={form.data.channel}
                    onValueChange={(v) => form.setData('channel', v)}
                  >
                    <SelectTrigger {...control}>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {Object.entries(channels).map(([k, v]) => (
                        <SelectItem key={k} value={k}>
                          {v}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                )}
              </Field>
              <Field label={__('Credits')} error={form.errors.credits} required>
                <Input
                  type="number"
                  step="0.001"
                  value={form.data.credits}
                  onChange={(e) => form.setData('credits', e.target.value)}
                />
              </Field>
              <Field label={__('Starts')} error={form.errors.effective_from}>
                <Input
                  type="date"
                  value={form.data.effective_from}
                  onChange={(e) => form.setData('effective_from', e.target.value)}
                />
              </Field>
              <Button type="submit" loading={form.processing}>
                {__('Save')}
              </Button>
            </form>
          </CardContent>
        </Card>
        {history.length > 0 && (
          <Table label={__('Rate history')}>
            <TableHeader>
              <TableRow>
                <TableHead>{__('From')}</TableHead>
                <TableHead>{__('Country')}</TableHead>
                <TableHead>{__('Channel')}</TableHead>
                <TableHead className="text-right">{__('Credits')}</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {history.map((h, i) => (
                <TableRow key={i}>
                  <TableCell className="text-fg-muted">
                    {new Date(h.from).toLocaleDateString()}
                  </TableCell>
                  <TableCell>{h.country}</TableCell>
                  <TableCell>{channels[h.channel] ?? h.channel}</TableCell>
                  <TableCell className="text-right tabular-nums">{credits(h.credits)}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
      </div>
    </AppShell>
  );
}
