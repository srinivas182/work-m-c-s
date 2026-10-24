import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, Archive } from 'lucide-react';
import type { FormEvent } from 'react';
import {
  Badge,
  Button,
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
  Checkbox,
  Field,
  Input,
  Label,
  PageHeader,
  RadioGroup,
  RadioGroupItem,
  Switch,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
  Textarea,
} from '@/design-system';
import AppShell from '@/layouts/AppShell';
import { rand, type PackageData } from './Packages';

interface CreditCosts {
  perCredit: Record<string, { label: string; zar_per_credit: number | null }>;
  assumptions: { fx_zar_per_usd: number; fx_buffer_pct: number; target_margin_pct: number };
}

/** The package pages' own money format (from rands), so the tip matches the prices beside it. */
const rands = (n: number) => rand(Math.round(n * 100) / 100);

/** What the credits cost WURK if fully used — a range across channels — and a safe price per extra credit. */
function CostTip({
  credits,
  monthlyPrice,
  costs,
}: {
  credits: number;
  monthlyPrice: number;
  costs: CreditCosts;
}) {
  const entries = Object.values(costs.perCredit);
  const known = entries
    .filter((e) => e.zar_per_credit !== null)
    .map((e) => ({ ...e, zar: e.zar_per_credit as number }));
  const missing = entries.filter((e) => e.zar_per_credit === null).map((e) => e.label);
  if (credits <= 0 || known.length === 0) {
    return missing.length > 0 ? (
      <p className="text-fg-muted text-sm">Costs not set yet for: {missing.join(', ')}.</p>
    ) : null;
  }
  const cheapest = known.reduce((a, b) => (a.zar <= b.zar ? a : b));
  const dearest = known.reduce((a, b) => (a.zar >= b.zar ? a : b));
  const margin = costs.assumptions.target_margin_pct / 100;
  return (
    <div
      role="note"
      aria-label="Estimated cost of these credits"
      className="bg-surface grid gap-1.5 rounded-xl p-4 text-sm"
    >
      <p className="font-semibold">
        {credits.toLocaleString('en-ZA')} credits — your cost if fully used:{' '}
        {rands(credits * cheapest.zar)}–{rands(credits * dearest.zar)} / month
      </p>
      <p className="text-fg-muted">
        Cheapest: all spent on {cheapest.label} · Most expensive: all on {dearest.label}
        {monthlyPrice > 0 && (
          <>
            {' '}
            · up to {Math.round(((credits * dearest.zar) / monthlyPrice) * 100)}% of the R
            {monthlyPrice.toLocaleString('en-ZA')} monthly price
          </>
        )}
      </p>
      <p>
        At your {costs.assumptions.target_margin_pct}% margin, sell extra credits for at least{' '}
        <strong>{rands(dearest.zar / (1 - margin))}</strong> each.
      </p>
      <p className="text-fg-muted text-xs">
        Allowance credits expire monthly and many businesses won’t use them all, so real cost is
        usually lower. AI costs use R{costs.assumptions.fx_zar_per_usd} per US dollar plus a{' '}
        {costs.assumptions.fx_buffer_pct}% buffer.
        {missing.length > 0 && <> Not included yet (cost not set): {missing.join(', ')}.</>}
      </p>
    </div>
  );
}

interface Props {
  package: PackageData | null;
  modules: Record<string, { name: string; installed: boolean }>;
  versions: {
    version: number;
    monthly: number;
    yearly: number;
    created: string | null;
    subscribers: number;
  }[];
  canManage?: boolean;
  creditCosts?: CreditCosts | null;
}

export default function PackageForm({
  package: pkg,
  modules,
  versions,
  canManage = true,
  creditCosts = null,
}: Props) {
  const t = pkg?.terms;
  const form = useForm({
    key: pkg?.key ?? '',
    name: pkg?.name ?? '',
    description: pkg?.description ?? '',
    type: pkg?.type ?? 'self_managed',
    visibility: pkg?.visibility ?? 'public',
    display_order: pkg?.display_order ?? 100,
    monthly_price: t?.monthly_price ?? 0,
    yearly_price: t?.yearly_price ?? 0,
    trial_days: t?.trial_days ?? 14,
    storage_mb: t?.storage_mb ?? 1024,
    staff_limit: t?.staff_limit ?? 3,
    multi_branch: t?.multi_branch ?? false,
    max_branches: t?.max_branches ?? 1,
    custom_domain: t?.custom_domain ?? false,
    monthly_credits: t?.monthly_credits ?? 100,
    credit_packs_allowed: t?.credit_packs_allowed ?? true,
    referral_max_levels: t?.referral_max_levels ?? 7,
    referral_max_commission: t?.referral_max_commission ?? 30,
    managed_fee: t?.managed_fee ?? '',
    modules: t?.modules ?? [],
  });
  const number = (key: keyof typeof form.data) => (e: React.ChangeEvent<HTMLInputElement>) =>
    form.setData(key, e.target.value as never);
  const toggleModule = (key: string, on: boolean) =>
    form.setData(
      'modules',
      on ? [...form.data.modules, key] : form.data.modules.filter((m) => m !== key),
    );
  const submit = (e: FormEvent) => {
    e.preventDefault();
    if (pkg) form.put(`/packages/${pkg.id}`, { preserveScroll: true });
    else form.post('/packages');
  };
  const disabled = !canManage;

  return (
    <AppShell title={pkg ? pkg.name : 'New package'}>
      <Head title={pkg ? pkg.name : 'New package'} />
      <form onSubmit={submit} className="grid max-w-4xl gap-6" noValidate>
        <Link
          href="/packages"
          className="text-fg-muted hover:text-fg inline-flex w-fit items-center gap-1.5 text-sm font-medium"
        >
          <ArrowLeft className="size-4" aria-hidden /> Packages
        </Link>
        <PageHeader
          title={pkg ? pkg.name : 'New package'}
          eyebrow={pkg ? `Version ${pkg.version}` : undefined}
          description="Changing prices, limits or modules saves a new version. Businesses already on this package keep their current terms."
          actions={
            pkg &&
            canManage && (
              <Button
                type="button"
                variant="secondary"
                onClick={() =>
                  router.post(`/packages/${pkg.id}/archive`, {}, { preserveScroll: true })
                }
              >
                <Archive /> {pkg.status === 'active' ? 'Archive' : 'Put back on sale'}
              </Button>
            )
          }
        />
        <fieldset disabled={disabled} className="grid gap-6">
          <Card>
            <CardHeader>
              <CardTitle>Presentation</CardTitle>
              <CardDescription>
                Shown to businesses. Changing these doesn’t create a new version.
              </CardDescription>
            </CardHeader>
            <CardContent className="grid grid-cols-1 gap-5 sm:grid-cols-2">
              <Field label="Name" error={form.errors.name} required>
                <Input
                  value={form.data.name}
                  onChange={(e) => form.setData('name', e.target.value)}
                />
              </Field>
              <Field
                label="Key"
                error={form.errors.key}
                hint="Used in links and reports; can’t change later"
                required
              >
                <Input
                  value={form.data.key}
                  onChange={(e) => form.setData('key', e.target.value.toLowerCase())}
                  disabled={Boolean(pkg)}
                />
              </Field>
              <Field label="Description" error={form.errors.description} className="sm:col-span-2">
                <Textarea
                  value={form.data.description}
                  onChange={(e) => form.setData('description', e.target.value)}
                />
              </Field>
              {!pkg && (
                <RadioGroup
                  value={form.data.type}
                  onValueChange={(v) => form.setData('type', v as 'self_managed' | 'managed')}
                  className="flex gap-6 sm:col-span-2"
                  aria-label="Package type"
                >
                  {(['self_managed', 'managed'] as const).map((v) => (
                    <div key={v} className="flex items-center gap-2">
                      <RadioGroupItem value={v} id={`type-${v}`} />
                      <Label htmlFor={`type-${v}`}>
                        {v === 'managed' ? 'Managed by WURK' : 'Self-managed'}
                      </Label>
                    </div>
                  ))}
                </RadioGroup>
              )}
              <div className="flex items-center gap-3">
                <Switch
                  id="private"
                  checked={form.data.visibility === 'private'}
                  onCheckedChange={(v) => form.setData('visibility', v ? 'private' : 'public')}
                />
                <Label htmlFor="private">Private (only WURK staff can assign it)</Label>
              </div>
              <Field label="Display order" error={form.errors.display_order}>
                <Input
                  type="number"
                  value={form.data.display_order}
                  onChange={number('display_order')}
                />
              </Field>
            </CardContent>
          </Card>
          <Card>
            <CardHeader>
              <CardTitle>Price and limits</CardTitle>
              <CardDescription>Prices exclude VAT (15% is added at checkout).</CardDescription>
            </CardHeader>
            <CardContent className="grid grid-cols-1 gap-5 sm:grid-cols-3">
              <Field label="Monthly price (R)" error={form.errors.monthly_price} required>
                <Input
                  type="number"
                  step="0.01"
                  value={form.data.monthly_price}
                  onChange={number('monthly_price')}
                />
              </Field>
              <Field label="Yearly price (R)" error={form.errors.yearly_price} required>
                <Input
                  type="number"
                  step="0.01"
                  value={form.data.yearly_price}
                  onChange={number('yearly_price')}
                />
              </Field>
              <Field label="Free trial (days)" error={form.errors.trial_days} hint="0 = no trial">
                <Input type="number" value={form.data.trial_days} onChange={number('trial_days')} />
              </Field>
              <Field label="Storage (MB)" error={form.errors.storage_mb}>
                <Input type="number" value={form.data.storage_mb} onChange={number('storage_mb')} />
              </Field>
              <Field label="Team members" error={form.errors.staff_limit}>
                <Input
                  type="number"
                  value={form.data.staff_limit}
                  onChange={number('staff_limit')}
                />
              </Field>
              <Field label="Monthly WURK Credits" error={form.errors.monthly_credits}>
                <Input
                  type="number"
                  value={form.data.monthly_credits}
                  onChange={number('monthly_credits')}
                />
              </Field>
              {creditCosts && (
                <div className="sm:col-span-2">
                  <CostTip
                    credits={Number(form.data.monthly_credits) || 0}
                    monthlyPrice={Number(form.data.monthly_price) || 0}
                    costs={creditCosts}
                  />
                </div>
              )}
              <div className="flex items-center gap-3">
                <Switch
                  id="packs"
                  checked={form.data.credit_packs_allowed}
                  onCheckedChange={(v) => form.setData('credit_packs_allowed', v)}
                />
                <Label htmlFor="packs">Can buy credit packs</Label>
              </div>
              <div className="flex items-center gap-3">
                <Switch
                  id="multi"
                  checked={form.data.multi_branch}
                  onCheckedChange={(v) => form.setData('multi_branch', v)}
                />
                <Label htmlFor="multi">Multiple branches</Label>
              </div>
              <Field label="Max branches" error={form.errors.max_branches}>
                <Input
                  type="number"
                  value={form.data.max_branches}
                  onChange={number('max_branches')}
                  disabled={!form.data.multi_branch}
                />
              </Field>
              <div className="flex items-center gap-3">
                <Switch
                  id="domain"
                  checked={form.data.custom_domain}
                  onCheckedChange={(v) => form.setData('custom_domain', v)}
                />
                <Label htmlFor="domain">Own domain allowed</Label>
              </div>
              <Field
                label="Referral levels (max)"
                error={form.errors.referral_max_levels}
                hint="Self-cashback + up to this many levels"
              >
                <Input
                  type="number"
                  value={form.data.referral_max_levels}
                  onChange={number('referral_max_levels')}
                />
              </Field>
              <Field
                label="Referral payout cap (%)"
                error={form.errors.referral_max_commission}
                hint="Across all levels"
              >
                <Input
                  type="number"
                  step="0.5"
                  value={form.data.referral_max_commission}
                  onChange={number('referral_max_commission')}
                />
              </Field>
              {form.data.type === 'managed' && (
                <Field label="Managed fee (R / month)" error={form.errors.managed_fee}>
                  <Input
                    type="number"
                    step="0.01"
                    value={form.data.managed_fee ?? ''}
                    onChange={number('managed_fee')}
                  />
                </Field>
              )}
            </CardContent>
          </Card>
          <Card>
            <CardHeader>
              <CardTitle>Modules</CardTitle>
              <CardDescription>
                Modules not built yet can be included now; they switch on when they ship.
              </CardDescription>
            </CardHeader>
            <CardContent className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              {Object.entries(modules).map(([key, m]) => (
                <div key={key} className="flex items-center gap-3">
                  <Checkbox
                    id={`m-${key}`}
                    checked={form.data.modules.includes(key)}
                    onCheckedChange={(v) => toggleModule(key, v === true)}
                  />
                  <Label htmlFor={`m-${key}`} className="font-normal">
                    {m.name} {!m.installed && <Badge className="ml-1">Coming</Badge>}
                  </Label>
                </div>
              ))}
              {form.errors.modules && (
                <p className="text-danger-11 text-sm sm:col-span-2" role="alert">
                  {form.errors.modules}
                </p>
              )}
            </CardContent>
          </Card>
        </fieldset>
        {canManage && (
          <Button type="submit" size="lg" className="w-fit" loading={form.processing}>
            {pkg ? 'Save package' : 'Create package'}
          </Button>
        )}
        {versions.length > 0 && (
          <Table label="Version history">
            <TableHeader>
              <TableRow>
                <TableHead>Version</TableHead>
                <TableHead>Monthly</TableHead>
                <TableHead>Yearly</TableHead>
                <TableHead>Businesses on it</TableHead>
                <TableHead>Created</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {versions.map((v) => (
                <TableRow key={v.version}>
                  <TableCell className="font-medium">v{v.version}</TableCell>
                  <TableCell>{rand(v.monthly / 100)}</TableCell>
                  <TableCell>{rand(v.yearly / 100)}</TableCell>
                  <TableCell>{v.subscribers}</TableCell>
                  <TableCell className="text-fg-muted">
                    {v.created ? new Date(v.created).toLocaleDateString() : '—'}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
      </form>
    </AppShell>
  );
}
