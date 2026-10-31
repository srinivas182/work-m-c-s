import { Head, Link, router, useForm } from '@inertiajs/react';
import { Archive, Inbox, Mail, MessageCircle, Phone } from 'lucide-react';
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

interface Enquiry {
  id: string;
  name: string;
  phone: string | null;
  email: string | null;
  service: string | null;
  message: string;
  status: 'new' | 'read' | 'archived';
  at: string;
  form: string;
  answers: { q: string; a: string }[];
  contact_id: string | null;
  lead_id: string | null;
}

const KIND: Record<string, string> = {
  quote: 'Quote request',
  enquiry: 'Enquiry',
  waiting_list: 'Waiting list',
  interest: 'Registration of interest',
};

interface Props {
  view: 'open' | 'archived';
  enquiries: Enquiry[];
  newCount: number;
  notifyEmail: string;
  base: string;
}

/** WhatsApp wants international digits only: 082… → 2782… */
const waNumber = (phone: string) => {
  const digits = phone.replace(/\D/g, '');
  return digits.startsWith('0') ? `27${digits.slice(1)}` : digits;
};

export default function Enquiries({ view, enquiries, newCount, notifyEmail, base }: Props) {
  const appBase = `/${window.location.pathname.split('/')[1] ?? ''}`; // the business's back office (/{handle})
  const form = useForm({ notify_email: notifyEmail });
  const setStatus = (e: Enquiry, status: Enquiry['status']) =>
    router.put(`${base}/${e.id}`, { status }, { preserveScroll: true });

  return (
    <AppShell title="Enquiries">
      <Head title="Enquiries" />
      <div className="grid max-w-4xl gap-8">
        <PageHeader
          title="Enquiries"
          description={
            newCount > 0
              ? `${newCount} new request${newCount === 1 ? '' : 's'} from your website.`
              : 'Quote requests from your website arrive here.'
          }
          actions={
            <Button variant="secondary" asChild>
              <Link href={view === 'archived' ? base : `${base}?show=archived`}>
                {view === 'archived' ? 'Show open requests' : 'Show archived'}
              </Link>
            </Button>
          }
        />
        {enquiries.length === 0 ? (
          <EmptyState
            icon={Inbox}
            title={view === 'archived' ? 'Nothing archived' : 'No requests yet'}
            description="When a visitor sends your quote form, you’ll see it here — and by email if you set it below."
          />
        ) : (
          <ul className="grid gap-4" aria-label="Enquiries">
            {enquiries.map((e) => (
              <li key={e.id}>
                <Card>
                  <CardContent className="grid gap-3 p-5">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                      <span className="flex items-center gap-2 font-semibold">
                        {e.name}
                        {e.status === 'new' && <Badge tone="brand">New</Badge>}
                      </span>
                      <span className="text-fg-muted text-sm">
                        {new Date(e.at).toLocaleString()}
                      </span>
                    </div>
                    <p className="text-fg-muted text-sm font-medium">
                      {KIND[e.form] ?? 'Enquiry'}
                      {e.service ? ` · ${e.service}` : ''}
                    </p>
                    {e.answers.length > 0 && (
                      <dl className="grid gap-1 text-sm sm:grid-cols-[auto_1fr] sm:gap-x-4">
                        {e.answers.map((a, k) => (
                          <div key={k} className="contents">
                            <dt className="font-medium">{a.q}</dt>
                            <dd>{a.a}</dd>
                          </div>
                        ))}
                      </dl>
                    )}
                    {e.message && <p className="whitespace-pre-line">{e.message}</p>}
                    {(e.contact_id || e.lead_id) && (
                      <p className="flex flex-wrap gap-3 text-sm">
                        {e.contact_id && (
                          <a className="underline" href={`${appBase}/contacts/${e.contact_id}`}>
                            Contact
                          </a>
                        )}
                        {e.lead_id && (
                          <a className="underline" href={`${appBase}/leads/${e.lead_id}`}>
                            Lead
                          </a>
                        )}
                      </p>
                    )}
                    <div className="flex flex-wrap gap-2">
                      {e.phone && (
                        <>
                          <Button size="sm" variant="secondary" asChild>
                            <a href={`tel:${e.phone}`}>
                              <Phone /> Call
                            </a>
                          </Button>
                          <Button size="sm" variant="secondary" asChild>
                            <a
                              href={`https://wa.me/${waNumber(e.phone)}`}
                              target="_blank"
                              rel="noopener"
                            >
                              <MessageCircle /> WhatsApp
                              <span className="sr-only"> (opens in a new tab)</span>
                            </a>
                          </Button>
                        </>
                      )}
                      {e.email && (
                        <Button size="sm" variant="secondary" asChild>
                          <a href={`mailto:${e.email}`}>
                            <Mail /> Email
                          </a>
                        </Button>
                      )}
                      {e.status === 'new' && (
                        <Button size="sm" variant="ghost" onClick={() => setStatus(e, 'read')}>
                          Mark as read
                        </Button>
                      )}
                      {e.status !== 'archived' ? (
                        <Button size="sm" variant="ghost" onClick={() => setStatus(e, 'archived')}>
                          <Archive /> Archive
                        </Button>
                      ) : (
                        <Button size="sm" variant="ghost" onClick={() => setStatus(e, 'read')}>
                          Move back to open
                        </Button>
                      )}
                    </div>
                  </CardContent>
                </Card>
              </li>
            ))}
          </ul>
        )}
        <Card>
          <CardHeader>
            <CardTitle>Email me new requests</CardTitle>
            <CardDescription>Leave empty to turn email notifications off.</CardDescription>
          </CardHeader>
          <CardContent>
            <form
              onSubmit={(ev) => {
                ev.preventDefault();
                form.put(`${base}/settings`, { preserveScroll: true });
              }}
              className="flex flex-wrap items-end gap-3"
            >
              <Field
                label="Email address"
                error={form.errors.notify_email}
                className="min-w-64 flex-1"
              >
                <Input
                  type="email"
                  value={form.data.notify_email}
                  onChange={(ev) => form.setData('notify_email', ev.target.value)}
                  autoComplete="email"
                />
              </Field>
              <Button type="submit" loading={form.processing}>
                Save
              </Button>
            </form>
          </CardContent>
        </Card>
      </div>
    </AppShell>
  );
}
