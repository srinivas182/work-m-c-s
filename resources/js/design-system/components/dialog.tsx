import { Dialog as DialogPrimitive } from 'radix-ui';
import { X } from 'lucide-react';
import type { ComponentProps, ReactNode } from 'react';
import { cn } from '../lib/cn';

export const Dialog = DialogPrimitive.Root;
export const DialogTrigger = DialogPrimitive.Trigger;
export const DialogClose = DialogPrimitive.Close;

function Overlay() {
  return (
    <DialogPrimitive.Overlay className="bg-overlay data-[state=closed]:animate-fade-out data-[state=open]:animate-fade-in fixed inset-0 z-50 backdrop-blur-[2px]" />
  );
}

function CloseButton() {
  return (
    <DialogPrimitive.Close className="text-fg-muted hover:bg-neutral-4 hover:text-fg absolute top-4 right-4 grid size-8 cursor-pointer place-items-center rounded-lg transition-colors">
      <X className="size-4" aria-hidden />
      <span className="sr-only">Close</span>
    </DialogPrimitive.Close>
  );
}

interface DialogContentProps extends ComponentProps<typeof DialogPrimitive.Content> {
  title: string;
  description?: ReactNode;
}

/** Centred modal on desktop; bottom sheet on small screens. */
export function DialogContent({
  className,
  title,
  description,
  children,
  ...props
}: DialogContentProps) {
  return (
    <DialogPrimitive.Portal>
      <Overlay />
      <DialogPrimitive.Content
        data-slot="dialog"
        className={cn(
          'border-border bg-surface-raised data-[state=closed]:animate-slide-out-bottom data-[state=open]:animate-slide-in-bottom sm:data-[state=closed]:animate-pop-out sm:data-[state=open]:animate-pop-in fixed inset-x-0 bottom-0 z-50 max-h-[90dvh] overflow-y-auto rounded-t-3xl border p-6 pb-[max(1.5rem,env(safe-area-inset-bottom))] shadow-xl sm:inset-auto sm:top-1/2 sm:left-1/2 sm:w-full sm:max-w-lg sm:-translate-x-1/2 sm:-translate-y-1/2 sm:rounded-2xl sm:pb-6',
          className,
        )}
        {...props}
      >
        <div className="pr-8">
          <DialogPrimitive.Title className="font-display text-lg font-semibold tracking-tight">
            {title}
          </DialogPrimitive.Title>
          {description ? (
            <DialogPrimitive.Description className="text-fg-muted mt-1.5 text-sm">
              {description}
            </DialogPrimitive.Description>
          ) : (
            <DialogPrimitive.Description className="sr-only">{title}</DialogPrimitive.Description>
          )}
        </div>
        <div className="mt-5">{children}</div>
        <CloseButton />
      </DialogPrimitive.Content>
    </DialogPrimitive.Portal>
  );
}

export function DialogFooter({ className, ...props }: ComponentProps<'div'>) {
  return (
    <div
      className={cn('mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end', className)}
      {...props}
    />
  );
}

interface SheetContentProps extends ComponentProps<typeof DialogPrimitive.Content> {
  title: string;
  side?: 'left' | 'right';
  hideTitle?: boolean;
}

/** Side panel — used for mobile navigation and detail drawers. */
export function SheetContent({
  className,
  title,
  side = 'right',
  hideTitle = false,
  children,
  ...props
}: SheetContentProps) {
  return (
    <DialogPrimitive.Portal>
      <Overlay />
      <DialogPrimitive.Content
        data-slot="sheet"
        className={cn(
          'border-border bg-surface-raised fixed inset-y-0 z-50 flex w-[min(22rem,88vw)] flex-col shadow-xl',
          side === 'right'
            ? 'data-[state=closed]:animate-slide-out-right data-[state=open]:animate-slide-in-right right-0 border-l'
            : 'data-[state=closed]:animate-slide-out-left data-[state=open]:animate-slide-in-left left-0 border-r',
          className,
        )}
        {...props}
      >
        <DialogPrimitive.Title
          className={cn('font-display px-6 pt-6 text-lg font-semibold', hideTitle && 'sr-only')}
        >
          {title}
        </DialogPrimitive.Title>
        <DialogPrimitive.Description className="sr-only">{title}</DialogPrimitive.Description>
        <div className="flex-1 overflow-y-auto">{children}</div>
        <CloseButton />
      </DialogPrimitive.Content>
    </DialogPrimitive.Portal>
  );
}

export const Sheet = DialogPrimitive.Root;
export const SheetTrigger = DialogPrimitive.Trigger;
export const SheetClose = DialogPrimitive.Close;
