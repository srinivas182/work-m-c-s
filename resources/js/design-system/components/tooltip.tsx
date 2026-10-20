import { Tooltip as TooltipPrimitive } from 'radix-ui';
import type { ReactNode } from 'react';

export const TooltipProvider = TooltipPrimitive.Provider;

interface TooltipProps {
  content: ReactNode;
  side?: 'top' | 'right' | 'bottom' | 'left';
  children: ReactNode;
}

export function Tooltip({ content, side = 'top', children }: TooltipProps) {
  return (
    <TooltipPrimitive.Root>
      <TooltipPrimitive.Trigger asChild>{children}</TooltipPrimitive.Trigger>
      <TooltipPrimitive.Portal>
        <TooltipPrimitive.Content
          side={side}
          sideOffset={6}
          className="bg-neutral-12 text-neutral-1 data-[state=closed]:animate-fade-out data-[state=delayed-open]:animate-pop-in z-50 max-w-xs rounded-lg px-2.5 py-1.5 text-xs font-medium shadow-md"
        >
          {content}
        </TooltipPrimitive.Content>
      </TooltipPrimitive.Portal>
    </TooltipPrimitive.Root>
  );
}
