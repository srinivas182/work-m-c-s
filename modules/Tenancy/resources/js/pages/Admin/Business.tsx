import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Banknote, Coins, ExternalLink, Package, ShieldCheck } from 'lucide-react';
import type { FormEvent } from 'react';
import {
  Badge,
  Button,
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
  Dialog,
  DialogContent,
  DialogFooter,
  DialogTrigger,
  Field,
  PageHeader,
  Textarea,
} from '@/design-system';
import AppShell from '@/layouts/AppShell';
import { statusTone, type BusinessSummary } from './Businesses';

interface Props {
  business: BusinessSummary & {
    country: string;
    timezone: string;
    domains: { host: string; type: string; primary: boolean; verified: boolean }[];
  };
  canManage: boolean;
  auditUrl: string;
}

function StatusChange({ business }: { business: Props['business'] }) {
  const suspending = business.status === 'active';
  const form = useForm({ reason: '' });
  const submit = (e: FormEvent) => {
    e.preventDefault();
    form.post(`/businesses/${business.id}/${suspending ? 'suspend' : 'reactivate'}`, {
      onSuccess: () => form.reset(),
    });
  };
  return (
    <Dialog>
      <DialogTrigger asChild>
        <Button variant={suspending ? 'danger' : 'primary'}>
          {suspending ? 'Suspend business' : 'Reactivate business'}
        </Button>
      </DialogTrigger>
      <DialogContent
        title={suspending ? `Suspend ${business.name}?` : `Reactivate ${business.name}?`}
        description={
          suspending
            ? 'Their website shows a neutral “temporarily unavailable” page and the team can’t use the back office. Nothing is deleted.'
            : 'Their website and back office come back immediately.'
        }
      >
        <form onSubmit={submit}>
          <Field label="Reason (recorded in the audit log)" error={form.errors.reason} required>
            <Textarea
              value={form.data.reason}
              onChange={(e) => form.setData('reason', e.target.value)}
            />
          </Field>
          <DialogFooter>
            <Button
              type="submit"
              variant={suspending ? 'danger' : 'primary'}
              loading={form.processing}
            >
              {suspending ? 'Suspend' : 'Reactivate'}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

export default function Business({ business, canManage, auditUrl }: Props) {
  const details: [string, string][] = [
    ['Handle', business.handle],
    ['Country', business.country],
    ['Time zone', business.timezone],
    ['Database', business.shard ?? '—'],
    ['Created', business.created ? new Date(business.created).toLocaleDateString() : '—'],
  ];
  return (
    <AppShell title={business.name}>
      <Head title={business.name} />
      <div className="grid gap-8">
        <Link
          href="/businesses"
          className="text-fg-muted hover:text-fg inline-flex w-fit items-center gap-1.5 text-sm font-medium"
        >
          <ArrowLeft className="size-4" aria-hidden /> Businesses
        </Link>
        <PageHeader
          title={business.name}
          eyebrow={business.domain ?? undefined}
          actions={
            <>
              <Badge tone={statusTone(business.status)} dot>
                {business.status}
              </Badge>
              {canManage && business.status !== 'provisioning' && (
                <StatusChange business={business} />
              )}
            </>
          }
        />
        <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
          <Card>
            <CardHeader>
              <CardTitle>Details</CardTitle>
            </CardHeader>
            <CardContent>
              <dl className="grid grid-cols-[8rem_1fr] gap-y-3 text-sm">
                {details.map(([k, v]) => (
                  <div key={k} className="contents">
                    <dt className="text-fg-muted">{k}</dt>
                    <dd className="font-medium">{v}</dd>
                  </div>
                ))}
              </dl>
            </CardContent>
          </Card>
          <Card>
            <CardHeader>
              <CardTitle>Web addresses</CardTitle>
              <CardDescription>
                Custom domains arrive with the website engine (Sprint 5).
              </CardDescription>
            </CardHeader>
            <CardContent className="grid gap-3">
              {business.domains.map((d) => (
                <div key={d.host} className="flex items-center justify-between gap-3">
                  <span className="inline-flex items-center gap-2 font-medium">
                    {d.host} <ExternalLink className="text-fg-muted size-3.5" aria-hidden />
                  </span>
                  <span className="flex gap-2">
                    {d.primary && <Badge tone="brand">Primary</Badge>}
                    <Badge tone={d.verified ? 'success' : 'warning'}>
                      {d.verified ? 'Verified' : 'Pending'}
                    </Badge>
                  </span>
                </div>
              ))}
            </CardContent>
          </Card>
        </div>
        <div className="flex flex-wrap gap-3">
          <Button variant="secondary" asChild>
            <Link href={`/businesses/${business.id}/plan`}>
              <Package /> Plan and modules
            </Link>
          </Button>
          <Button variant="secondary" asChild>
            <Link href={`/businesses/${business.id}/credits`}>
              <Coins /> WURK Credits
            </Link>
          </Button>
          <Button variant="secondary" asChild>
            <Link href={`/businesses/${business.id}/cash`}>
              <Banknote /> Cash payment
            </Link>
          </Button>
          <Button variant="secondary" asChild>
            <a href={auditUrl}>
              <ShieldCheck /> View this business in the audit log
            </a>
          </Button>
        </div>
      </div>
    </AppShell>
  );
}
