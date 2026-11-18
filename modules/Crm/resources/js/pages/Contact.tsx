import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import {
  Badge,
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

interface Data {
  id: string;
  name: string;
  phone: string | null;
  email: string | null;
  tags: string[];
  notes: string | null;
  consent: { at: string; source: string | null } | null;
}

const KIND: Record<string, string> = {
  booking: 'Booking',
  order: 'Order',
  class: 'Class',
  form: 'Form',
  chat: 'Chat',
};

export default function Contact({
  contact: c,
  history,
  merged,
  canManage,
}: {
  contact: Data;
  history: { type: string; summary: string; branch: string | null; at: string }[];
  merged: { id: string; name: string }[];
  canManage: boolean;
}) {
  const base = window.location.pathname.replace(/\/$/, '');
  const list = base.replace(/\/[^/]+$/, '');
  const [editing, setEditing] = useState(false);
  const [mergeQ, setMergeQ] = useState('');
  const [found, setFound] = useState<
    { id: string; name: string; phone: string | null; email: string | null }[] | null
  >(null);
  const lead = useForm({ contact: c.id, title: '', value: '' });
  const form = useForm({
    name: c.name,
    phone: c.phone ?? '',
    email: c.email ?? '',
    tags: c.tags.join(', '),
    notes: c.notes ?? '',
  });
  const find = async () => {
    const r = await fetch(`${list}/search?q=${encodeURIComponent(mergeQ)}`, {
      headers: { Accept: 'application/json' },
    }).catch(() => null);
    const body =
      r && r.ok
        ? ((await r.json()) as {
            contacts: { id: string; name: string; phone: string | null; email: string | null }[];
          })
        : { contacts: [] };
    setFound(body.contacts.filter((x) => x.id !== c.id));
  };
  return (
    <AppShell title={c.name}>
      <Head title={c.name} />
      <div className="grid max-w-4xl gap-6">
        <PageHeader
          title={c.name}
          description={[c.phone, c.email].filter(Boolean).join(' · ') || 'No phone or email'}
        />
        <p>
          <Link href={list} className="underline">
            {__('← All contacts')}
          </Link>
        </p>
        <Card>
          <CardHeader>
            <CardTitle>{__('Details')}</CardTitle>
          </CardHeader>
          <CardContent className="grid gap-3 text-sm">
            <p className="flex flex-wrap gap-1">
              {c.tags.length ? (
                c.tags.map((t) => (
                  <Badge key={t} tone="neutral">
                    {t}
                  </Badge>
                ))
              ) : (
                <span className="text-fg-muted">{__('No tags')}</span>
              )}
            </p>
            <p>
              {c.consent
                ? `Agreed to marketing on ${c.consent.at}${c.consent.source ? ` (${c.consent.source})` : ''}.`
                : 'Hasn’t agreed to marketing messages.'}
            </p>
            {c.notes && <p className="whitespace-pre-wrap">{c.notes}</p>}
            {canManage && !editing && (
              <Button
                variant="secondary"
                size="sm"
                className="justify-self-start"
                onClick={() => setEditing(true)}
              >
                {__('Edit')}
              </Button>
            )}
            {canManage && !editing && (
              <Button
                variant="ghost"
                size="sm"
                className="text-danger-11 justify-self-start"
                onClick={() => {
                  if (
                    window.confirm(
                      `Delete ${c.name}? You can restore them for 30 days; after that their details are erased.`,
                    )
                  )
                    router.delete(window.location.pathname);
                }}
              >
                {__('Delete customer')}
              </Button>
            )}
            {editing && (
              <form
                aria-label={__('Edit contact')}
                className="grid gap-3 sm:grid-cols-2"
                onSubmit={(e) => {
                  e.preventDefault();
                  form.transform((d) => ({
                    ...d,
                    tags: d.tags
                      .split(',')
                      .map((t) => t.trim())
                      .filter(Boolean),
                  }));
                  form.put(base, { preserveScroll: true, onSuccess: () => setEditing(false) });
                }}
              >
                <Field label={__('Name')} error={form.errors.name}>
                  <Input
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                  />
                </Field>
                <Field label={__('Phone')} error={form.errors.phone}>
                  <Input
                    type="tel"
                    value={form.data.phone}
                    onChange={(e) => form.setData('phone', e.target.value)}
                  />
                </Field>
                <Field label={__('Email')} error={form.errors.email}>
                  <Input
                    type="email"
                    value={form.data.email}
                    onChange={(e) => form.setData('email', e.target.value)}
                  />
                </Field>
                <Field label={__('Tags (separated by commas)')}>
                  <Input
                    value={form.data.tags}
                    onChange={(e) => form.setData('tags', e.target.value)}
                  />
                </Field>
                <Field label={__('Notes')}>
                  <textarea
                    className="border-border bg-bg min-h-24 rounded-md border p-2 text-sm"
                    value={form.data.notes}
                    onChange={(e) => form.setData('notes', e.target.value)}
                  />
                </Field>
                <Button
                  type="submit"
                  className="self-end justify-self-start"
                  loading={form.processing}
                >
                  {__('Save contact')}
                </Button>
              </form>
            )}
          </CardContent>
        </Card>
        {canManage && (
          <Card>
            <CardHeader>
              <CardTitle>{__('New lead')}</CardTitle>
            </CardHeader>
            <CardContent>
              <form
                aria-label={__('New lead')}
                className="flex flex-wrap items-end gap-3"
                onSubmit={(e) => {
                  e.preventDefault();
                  lead.post(list.replace(/\/contacts$/, '/leads'), { preserveScroll: true });
                }}
              >
                <Field label={__('What it’s about')} error={lead.errors.title}>
                  <Input
                    value={lead.data.title}
                    onChange={(e) => lead.setData('title', e.target.value)}
                  />
                </Field>
                <Field label={__('Value (R, optional)')} error={lead.errors.value}>
                  <Input
                    type="number"
                    min={0}
                    step={0.01}
                    className="w-32"
                    value={lead.data.value}
                    onChange={(e) => lead.setData('value', e.target.value)}
                  />
                </Field>
                <Button type="submit" variant="secondary" loading={lead.processing}>
                  {__('Add lead')}
                </Button>
              </form>
            </CardContent>
          </Card>
        )}
        <Card>
          <CardHeader>
            <CardTitle>{__('History')}</CardTitle>
          </CardHeader>
          <CardContent>
            <ul aria-label={__('History')} className="grid gap-2 text-sm">
              {history.map((h, k) => (
                <li key={k} className="flex flex-wrap gap-2">
                  <Badge tone="neutral">{KIND[h.type] ?? h.type}</Badge>
                  <span>{h.summary}</span>
                  <span className="text-fg-muted">
                    {h.at}
                    {h.branch ? ` · ${h.branch}` : ''}
                  </span>
                </li>
              ))}
            </ul>
          </CardContent>
        </Card>
        {canManage && (
          <Card>
            <CardHeader>
              <CardTitle>{__('Same person?')}</CardTitle>
            </CardHeader>
            <CardContent className="grid gap-3 text-sm">
              {merged.map((m) => (
                <p key={m.id} className="flex flex-wrap items-center gap-2">
                  {__('Merged in:')} {m.name}
                  <Button
                    size="sm"
                    variant="ghost"
                    aria-label={`Split ${m.name} out again`}
                    onClick={() =>
                      router.post(`${base}/split/${m.id}`, {}, { preserveScroll: true })
                    }
                  >
                    {__('Split')}
                  </Button>
                </p>
              ))}
              <form
                aria-label={__('Find a contact to merge')}
                className="flex flex-wrap items-end gap-3"
                onSubmit={(e) => {
                  e.preventDefault();
                  void find();
                }}
              >
                <Field label={__('Find a contact to merge in')}>
                  <Input value={mergeQ} onChange={(e) => setMergeQ(e.target.value)} />
                </Field>
                <Button type="submit" variant="secondary">
                  {__('Find')}
                </Button>
              </form>
              {found && (
                <ul aria-label={__('Contacts to merge')} className="grid gap-2">
                  {found.length === 0 && (
                    <li className="text-fg-muted">{__('No one else matches.')}</li>
                  )}
                  {found.map((f) => (
                    <li key={f.id} className="flex flex-wrap items-center gap-2">
                      {f.name} {[f.phone, f.email].filter(Boolean).join(' · ')}
                      <Button
                        size="sm"
                        variant="secondary"
                        aria-label={`Merge ${f.name} into ${c.name}`}
                        onClick={() => {
                          if (
                            window.confirm(
                              `Merge ${f.name} into ${c.name}? Their history moves here. You can split them again.`,
                            )
                          )
                            router.post(
                              `${base}/merge`,
                              { other: f.id },
                              { preserveScroll: true, onSuccess: () => setFound(null) },
                            );
                        }}
                      >
                        {__('Merge')}
                      </Button>
                    </li>
                  ))}
                </ul>
              )}
            </CardContent>
          </Card>
        )}
      </div>
    </AppShell>
  );
}
