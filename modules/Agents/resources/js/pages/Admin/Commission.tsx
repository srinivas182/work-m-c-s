import { Head, useForm } from '@inertiajs/react';
import { Button } from '@/design-system/components/button';
import { Card, CardContent } from '@/design-system/components/card';
import { Field } from '@/design-system/components/field';
import { Input } from '@/design-system/components/input';
import { PageHeader } from '@/design-system/components/feedback';
import AppShell from '@/layouts/AppShell';
import { __ } from '@/i18n';

/** Percent ↔ basis points (1% = 100), two decimals, no rounding drift. */
const toPct = (bp: number) => String(Number((bp / 100).toFixed(2)));
const toBp = (pct: string) => (pct.trim() === '' ? 0 : Math.round(Number(pct) * 100));

/** Admin → Agents → Commission: what each level earns on a business's subscription (excluding VAT). */
export default function Commission({
  levelBp,
  maxLevels,
  refundDays,
  canManage,
}: {
  levelBp: number[];
  maxLevels: number;
  refundDays: number;
  canManage: boolean;
}) {
  const form = useForm<{ level_bp: number[] }>({ level_bp: levelBp.length ? levelBp : [0] });
  const errors = form.errors as Record<string, string | undefined>;
  const set = (i: number, bp: number) =>
    form.setData(
      'level_bp',
      form.data.level_bp.map((v, k) => (k === i ? bp : v)),
    );
  const total = form.data.level_bp.reduce((a, b) => a + b, 0);
  return (
    <AppShell title={__('Agent commission')}>
      <Head title={__('Agent commission')} />
      <div className="grid max-w-2xl gap-6">
        <PageHeader
          title={__('Agent commission')}
          description={`On each business’s WURK subscription, excluding VAT. Payable ${refundDays} days after the payment, when refunds close. Changes apply to payments from now on.`}
        />
        <Card>
          <CardContent className="pt-6">
            <form
              aria-label={__('Commission percentages')}
              className="grid gap-3"
              onSubmit={(e) => {
                e.preventDefault();
                form.put('/agents/commission', { preserveScroll: true });
              }}
            >
              <ol aria-label={__('Levels')} className="grid gap-3">
                {form.data.level_bp.map((bp, i) => (
                  <li key={i} className="flex items-end gap-2">
                    <Field
                      label={i === 0 ? 'The business’s own agent (%)' : `Level ${i} above (%)`}
                      error={errors[`level_bp.${i}`]}
                    >
                      <Input
                        type="number"
                        inputMode="decimal"
                        min="0"
                        max="100"
                        step="0.01"
                        disabled={!canManage}
                        value={toPct(bp)}
                        onChange={(e) => set(i, toBp(e.target.value))}
                      />
                    </Field>
                    {canManage && i > 0 && i === form.data.level_bp.length - 1 && (
                      <Button
                        variant="ghost"
                        size="sm"
                        aria-label={`Remove level ${i}`}
                        onClick={() => form.setData('level_bp', form.data.level_bp.slice(0, -1))}
                      >
                        {__('Remove')}
                      </Button>
                    )}
                  </li>
                ))}
              </ol>
              {errors.level_bp && <p className="text-danger text-sm">{errors.level_bp}</p>}
              <p className="text-fg-muted text-sm">
                {__('Total:')} {toPct(total)}
                {__('% of each subscription payment.')}
              </p>
              {canManage && (
                <div className="flex flex-wrap gap-2">
                  {form.data.level_bp.length <= maxLevels && (
                    <Button
                      variant="secondary"
                      onClick={() => form.setData('level_bp', [...form.data.level_bp, 0])}
                    >
                      {__('Add level')} {form.data.level_bp.length}
                    </Button>
                  )}
                  <Button type="submit" loading={form.processing}>
                    {__('Save')}
                  </Button>
                </div>
              )}
            </form>
          </CardContent>
        </Card>
      </div>
    </AppShell>
  );
}
