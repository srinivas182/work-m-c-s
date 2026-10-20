import type { ComponentProps } from 'react';
import { cn } from '../lib/cn';

export const fieldControlClasses =
  'w-full rounded-lg border border-border-strong bg-surface text-fg shadow-xs transition-[border-color,box-shadow] duration-150 placeholder:text-fg-subtle hover:border-neutral-8 focus-visible:border-ring focus-visible:ring-4 focus-visible:ring-ring/20 focus-visible:outline-none disabled:cursor-not-allowed disabled:bg-sunken disabled:opacity-60 aria-invalid:border-danger-9 aria-invalid:focus-visible:ring-danger-9/20';

export function Input({ className, type = 'text', ...props }: ComponentProps<'input'>) {
  return (
    <input
      type={type}
      data-slot="input"
      className={cn(fieldControlClasses, 'h-10 px-3 text-sm', className)}
      {...props}
    />
  );
}

export function Textarea({ className, ...props }: ComponentProps<'textarea'>) {
  return (
    <textarea
      data-slot="textarea"
      className={cn(fieldControlClasses, 'min-h-24 px-3 py-2.5 text-sm', className)}
      {...props}
    />
  );
}
