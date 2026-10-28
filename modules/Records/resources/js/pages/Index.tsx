import { Head, Link, router, useForm } from '@inertiajs/react';
import { FileLock } from 'lucide-react';
import { useState } from 'react';
import {
  Badge,
  Button,
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
  EmptyState,
  Field,
  Input,
  PageHeader,
} from '@/design-system';
import AppShell from '@/layouts/AppShell';

interface Row {
  id: string;
  name: string;
  email: string | null;
  last: string | null;
  keptUntil: string | null;
  flagged: boolean;
}

export default function RecordsIndex({
  health,
  q,
  patients,
  due,
  retentionYears,
}: {
  health: boolean;
  q: string;
  patients: Row[];
  due: Row[];
  retentionYears: number;
}) {
  const base = window.location.pathname.replace(/\/$/, '');
  const [search, setSearch] = useState(q);
  const add = useForm({ name: '', email: '', phone: '' });
  if (!health) {
    return (
      <AppShell title="Patient records">
        <Head title="Patient records" />
        <EmptyState
          icon={FileLock}
          title="For health practices"
          description="Switch on “We’re a health practice” under Booking settings → Health practice to keep patient documents here — encrypted, private to your practice, and shared with patients only after a one-time code."
        />
      </AppShell>
    );
  }
  const row = (p: Row) => (
    <li key={p.id}>
      <Link
        href={`${base}/${p.id}`}
        className="border-border hover:bg-surface flex flex-wrap items-center justify-between gap-2 rounded-lg border p-3"
      >
        <span>
          <strong>{p.name}</strong>
          {p.email ? ` · ${p.email}` : ''}
        </span>
        <span className="text-fg-muted text-sm">
          {p.last ? `Last document ${p.last} · kept until ${p.keptUntil}` : 'No documents yet'}
          {p.flagged && (
            <>
              {' '}
              <Badge tone="warning">Due for removal</Badge>
            </>
          )}
        </span>
      </Link>
    </li>
  );
  return (
    <AppShell title="Patient records">
      <Head title="Patient records" />
      <div className="grid max-w-4xl gap-6">
        <PageHeader
          title="Patient records"
          description={`Documents are encrypted and private to your practice. Nothing can be deleted for ${retentionYears} years after a patient’s most recent document; corrections add a new version.`}
        />
        {due.length > 0 && (
          <Card>
            <CardHeader>
              <CardTitle>Due for removal</CardTitle>
              <CardDescription>
                These patients’ records are past the {retentionYears}-year retention period. Nothing
                is removed automatically — review and remove them if you no longer need them.
              </CardDescription>
            </CardHeader>
            <CardContent>
              <ul className="grid gap-2" aria-label="Due for removal">
                {due.map(row)}
              </ul>
            </CardContent>
          </Card>
        )}
        <Card>
          <CardHeader>
            <CardTitle>Add a patient</CardTitle>
          </CardHeader>
          <CardContent>
            <form
              aria-label="Add a patient"
              className="grid gap-3 sm:grid-cols-3 sm:items-end"
              onSubmit={(e) => {
                e.preventDefault();
                add.post(base);
              }}
            >
              <Field label="Name" error={add.errors.name}>
                <Input
                  value={add.data.name}
                  onChange={(e) => add.setData('name', e.target.value)}
                />
              </Field>
              <Field label="Email" error={add.errors.email}>
                <Input
                  type="email"
                  value={add.data.email}
                  onChange={(e) => add.setData('email', e.target.value)}
                />
              </Field>
              <Field label="Mobile number" error={add.errors.phone}>
                <Input
                  type="tel"
                  value={add.data.phone}
                  onChange={(e) => add.setData('phone', e.target.value)}
                />
              </Field>
              <Button type="submit" className="justify-self-start" loading={add.processing}>
                Add patient
              </Button>
            </form>
          </CardContent>
        </Card>
        <form
          role="search"
          className="flex gap-2"
          onSubmit={(e) => {
            e.preventDefault();
            router.get(base, { q: search }, { preserveState: true });
          }}
        >
          <Field label="Find a patient" className="flex-1">
            <Input
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Name or email"
            />
          </Field>
          <Button type="submit" variant="secondary" className="self-end">
            Search
          </Button>
        </form>
        {patients.length === 0 ? (
          <EmptyState
            icon={FileLock}
            title="No patients yet"
            description="Add a patient, then upload their documents."
          />
        ) : (
          <ul className="grid gap-2" aria-label="Patients">
            {patients.map(row)}
          </ul>
        )}
      </div>
    </AppShell>
  );
}
