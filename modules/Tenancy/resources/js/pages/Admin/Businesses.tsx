import { Head, Link, router } from '@inertiajs/react';
import { Building2, Plus, Search } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { Badge } from '@/design-system/components/badge';
import { Button } from '@/design-system/components/button';
import { EmptyState, PageHeader } from '@/design-system/components/feedback';
import { Field } from '@/design-system/components/field';
import { Input } from '@/design-system/components/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/design-system/components/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/design-system/components/table';
import AppShell from '@/layouts/AppShell';
import { __ } from '@/i18n';

export interface BusinessSummary {
  id: string;
  name: string;
  handle: string;
  status: string;
  demo?: boolean;
  shard: string | null;
  domain: string | null;
  created: string | null;
}

export const statusTone = (s: string) =>
  s === 'active' ? 'success' : s === 'suspended' ? 'danger' : 'warning';

interface Props {
  filters: { search?: string; status?: string; shard?: string; kind?: string };
  shards: string[];
  canCreate: boolean;
  businesses: { data: BusinessSummary[]; total: number; next: string | null; prev: string | null };
}

export default function Businesses({ filters, shards, canCreate, businesses }: Props) {
  const [form, setForm] = useState({
    search: filters.search ?? '',
    status: filters.status ?? 'any',
    shard: filters.shard ?? 'any',
    kind: filters.kind ?? 'any',
  });
  const apply = (e: FormEvent) => {
    e.preventDefault();
    const q = Object.fromEntries(Object.entries(form).filter(([, v]) => v !== '' && v !== 'any'));
    router.get('/businesses', q, { preserveState: true, preserveScroll: true });
  };

  return (
    <AppShell title={__('Businesses')}>
      <Head title={__('Businesses')} />
      <div className="grid gap-8">
        <PageHeader
          title={__('Businesses')}
          description={`${businesses.total.toLocaleString()} ${businesses.total === 1 ? 'business' : 'businesses'} on WURK.`}
          actions={
            canCreate && (
              <Button asChild>
                <Link href="/businesses/new">
                  <Plus /> {__('New business')}
                </Link>
              </Button>
            )
          }
        />
        <form
          onSubmit={apply}
          className="border-border bg-surface grid grid-cols-1 items-end gap-4 rounded-2xl border p-5 shadow-xs sm:grid-cols-[2fr_1fr_1fr_auto]"
        >
          <Field label={__('Search')}>
            <Input
              value={form.search}
              onChange={(e) => setForm((f) => ({ ...f, search: e.target.value }))}
              placeholder={__('Name or handle')}
            />
          </Field>
          <Field label={__('Status')}>
            {(control) => (
              <Select
                value={form.status}
                onValueChange={(v) => setForm((f) => ({ ...f, status: v }))}
              >
                <SelectTrigger {...control}>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="any">{__('Any status')}</SelectItem>
                  <SelectItem value="active">{__('Active')}</SelectItem>
                  <SelectItem value="suspended">{__('Suspended')}</SelectItem>
                  <SelectItem value="provisioning">{__('Provisioning')}</SelectItem>
                  <SelectItem value="deleted">{__('Archived')}</SelectItem>
                </SelectContent>
              </Select>
            )}
          </Field>
          <Field label={__('Kind')}>
            {(control) => (
              <Select value={form.kind} onValueChange={(v) => setForm((f) => ({ ...f, kind: v }))}>
                <SelectTrigger {...control}>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="any">{__('Live and demos')}</SelectItem>
                  <SelectItem value="live">{__('Live businesses')}</SelectItem>
                  <SelectItem value="demo">{__('Agents’ demos')}</SelectItem>
                </SelectContent>
              </Select>
            )}
          </Field>
          <Field label={__('Database')}>
            {(control) => (
              <Select
                value={form.shard}
                onValueChange={(v) => setForm((f) => ({ ...f, shard: v }))}
              >
                <SelectTrigger {...control}>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="any">{__('Any database')}</SelectItem>
                  {shards.map((s) => (
                    <SelectItem key={s} value={s}>
                      {s}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            )}
          </Field>
          <Button type="submit">
            <Search /> {__('Filter')}
          </Button>
        </form>
        {businesses.data.length === 0 ? (
          <EmptyState
            icon={Building2}
            title={__('No businesses found')}
            description={__('Try a different search, or create the first business.')}
            action={
              canCreate && (
                <Button asChild>
                  <Link href="/businesses/new">{__('New business')}</Link>
                </Button>
              )
            }
          />
        ) : (
          <div className="grid gap-4">
            <Table label={__('Businesses')}>
              <TableHeader>
                <TableRow>
                  <TableHead>{__('Business')}</TableHead>
                  <TableHead>{__('Address')}</TableHead>
                  <TableHead>{__('Status')}</TableHead>
                  <TableHead>{__('Database')}</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {businesses.data.map((b) => (
                  <TableRow key={b.id}>
                    <TableCell>
                      <Link href={`/businesses/${b.id}`} className="font-medium hover:underline">
                        {b.name}
                      </Link>
                      {b.demo && (
                        <Badge tone="neutral" className="ml-2">
                          {__('Demo')}
                        </Badge>
                      )}
                    </TableCell>
                    <TableCell className="text-fg-muted">{b.domain}</TableCell>
                    <TableCell>
                      <Badge tone={statusTone(b.status)} dot>
                        {b.status}
                      </Badge>
                    </TableCell>
                    <TableCell className="text-fg-muted font-mono text-xs">{b.shard}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
            <div className="flex justify-end gap-2">
              {businesses.prev && (
                <Button variant="secondary" size="sm" asChild>
                  <Link href={businesses.prev}>{__('Previous')}</Link>
                </Button>
              )}
              {businesses.next && (
                <Button variant="secondary" size="sm" asChild>
                  <Link href={businesses.next}>{__('Next')}</Link>
                </Button>
              )}
            </div>
          </div>
        )}
      </div>
    </AppShell>
  );
}
