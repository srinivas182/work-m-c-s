import type { ComponentProps } from 'react';
import { cn } from '../lib/cn';

export function Table({ className, ...props }: ComponentProps<'table'>) {
  return (
    <div className="border-border bg-surface relative w-full overflow-x-auto rounded-2xl border">
      <table className={cn('w-full caption-bottom text-sm', className)} {...props} />
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
  return <td className={cn('px-4 py-3.5 align-middle', className)} {...props} />;
}
