import { cva, type VariantProps } from 'class-variance-authority';
import type { ComponentProps } from 'react';
import { cn } from '../lib/cn';

export const badgeVariants = cva(
  'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-medium whitespace-nowrap [&_svg]:size-3',
  {
    variants: {
      tone: {
        neutral: 'border-neutral-6 bg-neutral-3 text-neutral-11',
        brand: 'border-brand-6 bg-brand-3 text-brand-11',
        success: 'border-success-6 bg-success-3 text-success-11',
        warning: 'border-warning-6 bg-warning-3 text-warning-11',
        danger: 'border-danger-6 bg-danger-3 text-danger-11',
        info: 'border-info-6 bg-info-3 text-info-11',
      },
    },
    defaultVariants: { tone: 'neutral' },
  },
);

export interface BadgeProps extends ComponentProps<'span'>, VariantProps<typeof badgeVariants> {
  /** Show a small status dot before the label. */
  dot?: boolean;
}

export function Badge({ className, tone, dot, children, ...props }: BadgeProps) {
  return (
    <span data-slot="badge" className={cn(badgeVariants({ tone }), className)} {...props}>
      {dot && <span className="size-1.5 rounded-full bg-current" aria-hidden />}
      {children}
    </span>
  );
}
