import type { ComponentProps } from 'react';
import { cn } from '../lib/cn';

interface TableProps extends ComponentProps<'table'> {
  /**
   * Accessible name for the table. It also labels the scroll container, which is
   * keyboard-focusable so wide tables can be scrolled without a mouse.
   */
  label: string;
}

export function Table({ className, label, ...props }: TableProps) {
  return (
    <div
      role="region"
      aria-label={label}
      tabIndex={0}
      className="border-border bg-surface focus-visible:outline-ring relative w-full overflow-x-auto rounded-2xl border focus-visible:outline-2 focus-visible:outline-offset-2"
    >
      <table
        aria-label={label}
        className={cn('w-full caption-bottom text-sm', className)}
        {...props}
      />
    </div>
  );
}

export function TableHeader({ className, ...props }: ComponentProps<'thead'>) {
  return <thead className={cn('bg-neutral-2 [&_tr]:border-b', className)} {...props} />;
}

export function TableBody({ className, ...props }: ComponentProps<'tbody'>) {
  return <tbody className={cn('[&_tr:last-child]:border-0', className)} {...props} />;
}

export function TableRow({ className, ...props }: ComponentProps<'tr'>) {
  return (
    <tr
      className={cn('border-border hover:bg-neutral-2 border-b transition-colors', className)}
      {...props}
    />
  );
}

export function TableHead({ className, ...props }: ComponentProps<'th'>) {
  return (
    <th
      className={cn(
        'text-fg-muted h-11 px-4 text-left align-middle text-xs font-medium tracking-wide whitespace-nowrap uppercase',
        className,
      )}
      {...props}
    />
  );
}

export function TableCell({ className, ...props }: ComponentProps<'td'>) {
  return <td className={cn('px-4 py-3.5 align-middle whitespace-nowrap', className)} {...props} />;
}
