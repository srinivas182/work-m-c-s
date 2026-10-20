import {
  Checkbox as CheckboxPrimitive,
  RadioGroup as RadioPrimitive,
  Switch as SwitchPrimitive,
} from 'radix-ui';
import { Check } from 'lucide-react';
import type { ComponentProps } from 'react';
import { cn } from '../lib/cn';

const choiceBase =
  'peer shrink-0 cursor-pointer border border-border-strong bg-surface shadow-xs transition-colors duration-150 hover:border-neutral-8 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring disabled:cursor-not-allowed disabled:opacity-50';

export function Checkbox({ className, ...props }: ComponentProps<typeof CheckboxPrimitive.Root>) {
  return (
    <CheckboxPrimitive.Root
      data-slot="checkbox"
      className={cn(
        choiceBase,
        'data-[state=checked]:border-primary data-[state=checked]:bg-primary data-[state=checked]:text-primary-fg size-5 rounded-md',
        className,
      )}
      {...props}
    >
      <CheckboxPrimitive.Indicator className="data-[state=checked]:animate-pop-in grid place-items-center">
        <Check className="size-3.5" strokeWidth={3} aria-hidden />
      </CheckboxPrimitive.Indicator>
    </CheckboxPrimitive.Root>
  );
}

export function Switch({ className, ...props }: ComponentProps<typeof SwitchPrimitive.Root>) {
  return (
    <SwitchPrimitive.Root
      data-slot="switch"
      className={cn(
        'peer bg-neutral-7 focus-visible:outline-ring data-[state=checked]:bg-primary inline-flex h-6 w-11 shrink-0 cursor-pointer items-center rounded-full border-2 border-transparent transition-colors duration-200 focus-visible:outline-2 focus-visible:outline-offset-2 disabled:cursor-not-allowed disabled:opacity-50',
        className,
      )}
      {...props}
    >
      <SwitchPrimitive.Thumb className="pointer-events-none block size-5 rounded-full bg-white shadow-md ring-0 transition-transform duration-200 ease-[var(--ease-out-expo)] data-[state=checked]:translate-x-5" />
    </SwitchPrimitive.Root>
  );
}

export function RadioGroup({ className, ...props }: ComponentProps<typeof RadioPrimitive.Root>) {
  return (
    <RadioPrimitive.Root
      data-slot="radio-group"
      className={cn('grid gap-3', className)}
      {...props}
    />
  );
}

export function RadioGroupItem({
  className,
  ...props
}: ComponentProps<typeof RadioPrimitive.Item>) {
  return (
    <RadioPrimitive.Item
      data-slot="radio"
      className={cn(
        choiceBase,
        'data-[state=checked]:border-primary size-5 rounded-full',
        className,
      )}
      {...props}
    >
      <RadioPrimitive.Indicator className="after:bg-primary data-[state=checked]:animate-pop-in grid place-items-center after:block after:size-2.5 after:rounded-full" />
    </RadioPrimitive.Item>
  );
}
