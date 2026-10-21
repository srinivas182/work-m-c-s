import { Head, Link } from '@inertiajs/react';
import { Check, Package as PackageIcon, Plus } from 'lucide-react';
import {
  Badge,
  Button,
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
  EmptyState,
  PageHeader,
} from '@/design-system';
import AppShell from '@/layouts/AppShell';

export interface Terms {
  monthly_price: number;
  yearly_price: number;
  trial_days: number;
  storage_mb: number;
  staff_limit: number;
  multi_branch: boolean;
  max_branches: number;
  custom_domain: boolean;
  monthly_credits: number;
  referral_max_levels: number;
  referral_max_commission: number;
  managed_fee: number | null;
  modules: string[];
}

export interface PackageData {
  id: string;
  key: string;
  name: string;
  description: string;
  type: 'self_managed' | 'managed';
  visibility: 'public' | 'private';
  display_order: number;
  status: 'active' | 'archived';
  version: number | null;
  terms: Terms | null;
  subscribers?: number;
}

export const rand = (n: number) =>
  `R\u202f${n.toLocaleString('en-ZA', { minimumFractionDigits: 0, maximumFractionDigits: 2 })}`;

interface Props {
  packages: PackageData[];
  modules: Record<string, { name: string; installed: boolean }>;
  canManage: boolean;
}

export default function Packages({ packages, modules, canManage }: Props) {
  return (
    <AppShell title="Packages">
      <Head title="Packages" />
      <div className="grid gap-8">
        <PageHeader
          title="Packages"
          description="What businesses can buy. Price changes create a new version — existing businesses keep their terms."
          actions={
            canManage && (
              <Button asChild>
                <Link href="/packages/new">
                  <Plus /> New package
                </Link>
              </Button>
            )
          }
        />
        {packages.length === 0 ? (
          <EmptyState
            icon={PackageIcon}
            title="No packages yet"
            description="Create the first package so businesses can sign up."
            action={
              canManage && (
                <Button asChild>
                  <Link href="/packages/new">New package</Link>
                </Button>
              )
            }
          />
        ) : (
          <div className="grid grid-cols-1 gap-6 md:grid-cols-2 xl:grid-cols-3">
            {packages.map((p) => (
              <Card key={p.id} className={p.status === 'archived' ? 'opacity-70' : undefined}>
                <CardHeader>
                  <div className="flex items-start justify-between gap-3">
                    <div>
                      <CardTitle>{p.name}</CardTitle>
                      <CardDescription>
                        {p.type === 'managed' ? 'Managed by WURK' : 'Self-managed'} · v{p.version}
                      </CardDescription>
                    </div>
                    <span className="flex flex-col items-end gap-1">
                      {p.status === 'archived' ? (
                        <Badge tone="warning">Archived</Badge>
                      ) : (
                        <Badge tone="success" dot>
                          On sale
                        </Badge>
                      )}
                      {p.visibility === 'private' && <Badge>Private</Badge>}
                    </span>
                  </div>
                </CardHeader>
                <CardContent className="grid gap-4">
                  {p.terms && (
                    <p>
                      <span className="font-display text-3xl font-semibold tracking-tight">
                        {rand(p.terms.monthly_price)}
                      </span>
                      <span className="text-fg-muted"> / month + VAT</span>
                      <span className="text-fg-muted block text-sm">
                        or {rand(p.terms.yearly_price)} / year
                        {p.terms.trial_days > 0 ? ` · ${p.terms.trial_days}-day free trial` : ''}
                      </span>
                    </p>
                  )}
                  <ul className="grid gap-1.5 text-sm">
                    {p.terms?.modules.map((m) => (
                      <li key={m} className="flex items-center gap-2">
                        <Check className="text-success-11 size-4" aria-hidden />{' '}
                        {modules[m]?.name ?? m}
                      </li>
                    ))}
                  </ul>
                  <div className="border-border flex items-center justify-between border-t pt-4 text-sm">
                    <span className="text-fg-muted">{p.subscribers ?? 0} businesses</span>
                    <Button variant="secondary" size="sm" asChild>
                      <Link href={`/packages/${p.id}`}>{canManage ? 'Edit' : 'View'}</Link>
                    </Button>
                  </div>
                </CardContent>
              </Card>
            ))}
          </div>
        )}
      </div>
    </AppShell>
  );
}
