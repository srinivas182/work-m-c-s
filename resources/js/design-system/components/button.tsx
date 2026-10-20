import { cva, type VariantProps } from 'class-variance-authority';
import { Slot } from 'radix-ui';
import { Loader2 } from 'lucide-react';
import type { ComponentProps } from 'react';
import { cn } from '../lib/cn';

export const buttonVariants = cva(
  'relative inline-flex shrink-0 cursor-pointer items-center justify-center gap-2 font-medium whitespace-nowrap transition-[background-color,color,box-shadow,transform] duration-150 select-none active:scale-[0.98] disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg]:shrink-0',
  {
    variants: {
      variant: {
        primary: 'bg-primary text-primary-fg shadow-sm shadow-brand-9/25 hover:bg-primary-hover',
        secondary:
          'border border-border bg-surface text-fg shadow-xs hover:border-border-strong hover:bg-neutral-3',
        soft: 'bg-accent text-accent-fg hover:bg-accent-hover',
        ghost: 'text-fg hover:bg-neutral-4',
        danger: 'bg-danger-9 text-danger-contrast shadow-sm hover:bg-danger-10',
        link: 'h-auto px-0 text-accent-fg underline-offset-4 hover:underline active:scale-100',
      },
      size: {
        sm: 'h-8 rounded-md px-3 text-sm [&_svg]:size-4',
        md: 'h-10 rounded-lg px-4 text-sm [&_svg]:size-4',
        lg: 'h-12 rounded-xl px-6 text-base [&_svg]:size-5',
        icon: 'size-10 rounded-lg [&_svg]:size-[1.125rem]',
        'icon-sm': 'size-8 rounded-md [&_svg]:size-4',
      },
    },
    defaultVariants: { variant: 'primary', size: 'md' },
  },
);

export interface ButtonProps extends ComponentProps<'button'>, VariantProps<typeof buttonVariants> {
  /** Render the child element (e.g. an Inertia Link) with button styles. */
  asChild?: boolean;
  loading?: boolean;
}

export function Button({
  className,
  variant,
  size,
  asChild = false,
  loading = false,
  disabled,
  children,
  ...props
}: ButtonProps) {
  const Comp = asChild ? Slot.Root : 'button';
  return (
    <Comp
      data-slot="button"
      className={cn(buttonVariants({ variant, size }), className)}
      disabled={disabled || loading}
      aria-busy={loading || undefined}
      {...props}
    >
      {loading && !asChild ? (
        <>
          <Loader2 className="absolute animate-spin" aria-hidden />
          <span className="invisible inline-flex items-center gap-2">{children}</span>
        </>
      ) : (
        children
      )}
    </Comp>
  );
}
