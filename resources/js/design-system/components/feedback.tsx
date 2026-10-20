import { ArrowDownRight, ArrowUpRight, type LucideIcon } from 'lucide-react';
import type { ComponentProps, ReactNode } from 'react';
import { cn } from '../lib/cn';

export function Skeleton({ className, ...props }: ComponentProps<'div'>) {
  return (
    <div
      data-slot="skeleton"
      aria-hidden
      className={cn(
        'animate-shimmer rounded-lg bg-[linear-gradient(90deg,var(--wurk-neutral-3)_0%,var(--wurk-neutral-4)_50%,var(--wurk-neutral-3)_100%)] bg-[length:200%_100%]',
        className,
      )}
      {...props}
    />
  );
}

interface EmptyStateProps {
  icon: LucideIcon;
  title: string;
  description: string;
  action?: ReactNode;
  className?: string;
}

/** Never a blank screen: explain what goes here and offer the next step. */
export function EmptyState({ icon: Icon, title, description, action, className }: EmptyStateProps) {
  return (
    <div
      className={cn(
        'border-border-strong bg-surface flex flex-col items-center rounded-2xl border border-dashed px-6 py-14 text-center',
        className,
      )}
    >
      <div className="relative mb-5">
        <div
          className="bg-brand-4 absolute inset-0 scale-150 rounded-full opacity-60 blur-xl"
          aria-hidden
        />
        <div className="border-brand-6 bg-brand-3 text-brand-11 relative grid size-14 place-items-center rounded-2xl border shadow-sm">
          <Icon className="size-6" aria-hidden />
        </div>
      </div>
      <h3 className="text-base font-semibold tracking-tight">{title}</h3>
      <p className="text-fg-muted mt-1.5 max-w-sm text-sm text-pretty">{description}</p>
      {action && <div className="mt-6">{action}</div>}
    </div>
  );
}

interface StatCardProps {
  label: string;
  value: string;
  /** Change versus previous period, e.g. 12.5 for +12.5% */
  change?: number;
  icon?: LucideIcon;
  hint?: string;
}

export function StatCard({ label, value, change, icon: Icon, hint }: StatCardProps) {
  const up = (change ?? 0) >= 0;
  return (
    <div className="border-border bg-surface rounded-2xl border p-5 shadow-xs">
      <div className="flex items-center justify-between">
        <p className="text-fg-muted text-sm font-medium">{label}</p>
        {Icon && (
          <span className="bg-brand-3 text-brand-11 grid size-8 place-items-center rounded-lg">
            <Icon className="size-4" aria-hidden />
          </span>
        )}
      </div>
      <p className="font-display mt-3 text-3xl font-semibold tracking-tight tabular-nums">
        {value}
      </p>
      {(change !== undefined || hint) && (
        <div className="mt-2 flex items-center gap-2 text-sm">
          {change !== undefined && (
            <span
              className={cn(
                'inline-flex items-center gap-0.5 font-medium tabular-nums',
                up ? 'text-success-11' : 'text-danger-11',
              )}
            >
              {up ? (
                <ArrowUpRight className="size-4" aria-hidden />
              ) : (
                <ArrowDownRight className="size-4" aria-hidden />
              )}
              {Math.abs(change).toFixed(1)}%
              <span className="sr-only">{up ? 'increase' : 'decrease'}</span>
            </span>
          )}
          {hint && <span className="text-fg-muted">{hint}</span>}
        </div>
      )}
    </div>
  );
}

interface PageHeaderProps {
  title: string;
  description?: string;
  actions?: ReactNode;
  eyebrow?: string;
}

export function PageHeader({ title, description, actions, eyebrow }: PageHeaderProps) {
  return (
    <header className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
      <div className="min-w-0">
        {eyebrow && <p className="text-accent-fg mb-1.5 text-sm font-medium">{eyebrow}</p>}
        <h1 className="text-2xl font-semibold tracking-tight text-balance sm:text-3xl">{title}</h1>
        {description && <p className="text-fg-muted mt-1.5 max-w-2xl text-pretty">{description}</p>}
      </div>
      {actions && <div className="flex shrink-0 flex-wrap gap-2">{actions}</div>}
    </header>
  );
}
