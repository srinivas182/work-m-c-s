import { Head, Link, router } from '@inertiajs/react';
import { Building2, Plus, Search } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import {
  Badge,
  Button,
  EmptyState,
  Field,
  Input,
  PageHeader,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/design-system';
import AppShell from '@/layouts/AppShell';

export interface BusinessSummary {
  id: string;
  name: string;
  handle: string;
  status: string;
  shard: string | null;
  domain: string | null;
  created: string | null;
}

export const statusTone = (s: string) =>
  s === 'active' ? 'success' : s === 'suspended' ? 'danger' : 'warning';

interface Props {
  filters: { search?: string; status?: string; shard?: string };
  shards: string[];
  canCreate: boolean;
  businesses: { data: BusinessSummary[]; total: number; next: string | null; prev: string | null };
}

export default function Businesses({ filters, shards, canCreate, businesses }: Props) {
  const [form, setForm] = useState({
    search: filters.search ?? '',
    status: filters.status ?? 'any',
    shard: filters.shard ?? 'any',
  });
  const apply = (e: FormEvent) => {
    e.preventDefault();
    const q = Object.fromEntries(Object.entries(form).filter(([, v]) => v !== '' && v !== 'any'));
    router.get('/businesses', q, { preserveState: true, preserveScroll: true });
  };

  return (
    <AppShell title="Businesses">
      <Head title="Businesses" />
      <div className="grid gap-8">
        <PageHeader
          title="Businesses"
          description={`${businesses.total.toLocaleString()} ${businesses.total === 1 ? 'business' : 'businesses'} on WURK.`}
          actions={
            canCreate && (
              <Button asChild>
                <Link href="/businesses/new">
                  <Plus /> New business
                </Link>
              </Button>
            )
          }
        />
        <form
          onSubmit={apply}
          className="border-border bg-surface grid grid-cols-1 items-end gap-4 rounded-2xl border p-5 shadow-xs sm:grid-cols-[2fr_1fr_1fr_auto]"
        >
          <Field label="Search">
            <Input
              value={form.search}
              onChange={(e) => setForm((f) => ({ ...f, search: e.target.value }))}
              placeholder="Name or handle"
            />
          </Field>
          <Field label="Status">
            {(control) => (
              <Select
                value={form.status}
                onValueChange={(v) => setForm((f) => ({ ...f, status: v }))}
              >
                <SelectTrigger {...control}>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="any">Any status</SelectItem>
                  <SelectItem value="active">Active</SelectItem>
                  <SelectItem value="suspended">Suspended</SelectItem>
                  <SelectItem value="provisioning">Provisioning</SelectItem>
                </SelectContent>
              </Select>
            )}
          </Field>
          <Field label="Database">
            {(control) => (
              <Select
                value={form.shard}
                onValueChange={(v) => setForm((f) => ({ ...f, shard: v }))}
              >
                <SelectTrigger {...control}>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="any">Any database</SelectItem>
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
            <Search /> Filter
          </Button>
        </form>
        {businesses.data.length === 0 ? (
          <EmptyState
            icon={Building2}
            title="No businesses found"
            description="Try a different search, or create the first business."
            action={
              canCreate && (
                <Button asChild>
                  <Link href="/businesses/new">New business</Link>
                </Button>
              )
            }
          />
        ) : (
          <div className="grid gap-4">
            <Table label="Businesses">
              <TableHeader>
                <TableRow>
                  <TableHead>Business</TableHead>
                  <TableHead>Address</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead>Database</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {businesses.data.map((b) => (
                  <TableRow key={b.id}>
                    <TableCell>
                      <Link href={`/businesses/${b.id}`} className="font-medium hover:underline">
                        {b.name}
                      </Link>
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
                  <Link href={businesses.prev}>Previous</Link>
                </Button>
              )}
              {businesses.next && (
                <Button variant="secondary" size="sm" asChild>
                  <Link href={businesses.next}>Next</Link>
                </Button>
              )}
            </div>
          </div>
        )}
      </div>
    </AppShell>
  );
}
