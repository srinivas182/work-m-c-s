import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { Users } from 'lucide-react';
import { Badge, Button, EmptyState, Field, Input, PageHeader } from '@/design-system';
import AppShell from '@/layouts/AppShell';

interface Row {
  id: string;
  name: string;
  phone: string | null;
  email: string | null;
  tags: string[];
  last_seen: string | null;
}

export default function Contacts({
  contacts,
  pages,
  q,
  tag,
  canManage,
}: {
  contacts: Row[];
  pages: { current: number; last: number; total: number };
  q: string;
  tag: string;
  canManage: boolean;
}) {
  const base = window.location.pathname.replace(/\/$/, '');
  const [search, setSearch] = useState(q);
  const go = (params: Record<string, string | number>) =>
    router.get(base, Object.fromEntries(Object.entries(params).filter(([, v]) => v !== '')), {
      preserveState: true,
      preserveScroll: true,
    });
  return (
    <AppShell title="Contacts">
      <Head title="Contacts" />
      <div className="grid max-w-5xl gap-6">
        <PageHeader
          title="Contacts"
          description={`Everyone who has booked, bought or taken a class — one row per person (${pages.total}).`}
          actions={
            canManage ? (
              <a className="text-sm underline" href={`${base}/export`}>
                Export
              </a>
            ) : undefined
          }
        />
        <form
          role="search"
          aria-label="Search contacts"
          className="flex flex-wrap items-end gap-3"
          onSubmit={(e) => {
            e.preventDefault();
            go({ q: search, tag });
          }}
        >
          <Field label="Name, phone or email">
            <Input value={search} onChange={(e) => setSearch(e.target.value)} />
          </Field>
          <Button type="submit" variant="secondary">
            Search
          </Button>
          {(q || tag) && (
            <Button type="button" variant="ghost" onClick={() => go({})}>
              Clear
            </Button>
          )}
        </form>
        {tag && (
          <p className="text-sm">
            Tagged <Badge tone="neutral">{tag}</Badge>
          </p>
        )}
        {contacts.length === 0 ? (
          <EmptyState
            icon={Users}
            title={q || tag ? 'No one matches' : 'No contacts yet'}
            description={
              q || tag
                ? 'Try another name, number or email.'
                : 'People appear here when they book, buy or take a class.'
            }
          />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <caption className="sr-only">Contacts</caption>
              <thead>
                <tr className="border-border border-b">
                  <th scope="col" className="py-2">
                    Name
                  </th>
                  <th scope="col" className="py-2">
                    Phone
                  </th>
                  <th scope="col" className="py-2">
                    Email
                  </th>
                  <th scope="col" className="py-2">
                    Tags
                  </th>
                  <th scope="col" className="py-2">
                    Last seen
                  </th>
                </tr>
              </thead>
              <tbody>
                {contacts.map((c) => (
                  <tr key={c.id} className="border-border border-b">
                    <td className="py-2">
                      <Link className="underline" href={`${base}/${c.id}`}>
                        {c.name}
                      </Link>
                    </td>
                    <td className="py-2">{c.phone ?? '—'}</td>
                    <td className="py-2">{c.email ?? '—'}</td>
                    <td className="py-2">
                      <span className="flex flex-wrap gap-1">
                        {c.tags.map((t) => (
                          <button
                            key={t}
                            type="button"
                            className="underline"
                            onClick={() => go({ q, tag: t })}
                            aria-label={`Show contacts tagged ${t}`}
                          >
                            {t}
                          </button>
                        ))}
                      </span>
                    </td>
                    <td className="py-2">{c.last_seen ?? '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        {pages.last > 1 && (
          <nav aria-label="Pages" className="flex items-center gap-3 text-sm">
            {pages.current > 1 && (
              <button
                type="button"
                className="underline"
                onClick={() => go({ q, tag, page: pages.current - 1 })}
              >
                ← Previous
              </button>
            )}
            <span>
              Page {pages.current} of {pages.last}
            </span>
            {pages.current < pages.last && (
              <button
                type="button"
                className="underline"
                onClick={() => go({ q, tag, page: pages.current + 1 })}
              >
                Next →
              </button>
            )}
          </nav>
        )}
      </div>
    </AppShell>
  );
}
