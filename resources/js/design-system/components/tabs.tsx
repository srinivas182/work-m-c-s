import { Tabs as TabsPrimitive } from 'radix-ui';
import type { ComponentProps } from 'react';
import { cn } from '../lib/cn';

export const Tabs = TabsPrimitive.Root;

export function TabsList({ className, ...props }: ComponentProps<typeof TabsPrimitive.List>) {
  return (
    <TabsPrimitive.List
      className={cn(
        'bg-neutral-3 inline-flex max-w-full items-center gap-1 overflow-x-auto rounded-xl p-1',
        className,
      )}
      {...props}
    />
  );
}

export function TabsTrigger({ className, ...props }: ComponentProps<typeof TabsPrimitive.Trigger>) {
  return (
    <TabsPrimitive.Trigger
      className={cn(
        'text-fg-muted hover:text-fg data-[state=active]:bg-surface data-[state=active]:text-fg inline-flex h-8 cursor-pointer items-center gap-2 rounded-lg px-3 text-sm font-medium whitespace-nowrap transition-all duration-150 data-[state=active]:shadow-sm [&_svg]:size-4',
        className,
      )}
      {...props}
    />
  );
}

export function TabsContent({ className, ...props }: ComponentProps<typeof TabsPrimitive.Content>) {
  return (
    <TabsPrimitive.Content
      className={cn(
        'data-[state=active]:animate-fade-in mt-4 focus-visible:outline-none',
        className,
      )}
      {...props}
    />
  );
}
