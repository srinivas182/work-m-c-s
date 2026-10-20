import { Label as LabelPrimitive } from 'radix-ui';
import { useId, type ComponentProps, type ReactElement, cloneElement } from 'react';
import { cn } from '../lib/cn';

export function Label({ className, ...props }: ComponentProps<typeof LabelPrimitive.Root>) {
  return (
    <LabelPrimitive.Root
      data-slot="label"
      className={cn(
        'text-fg text-sm leading-none font-medium select-none peer-disabled:opacity-60',
        className,
      )}
      {...props}
    />
  );
}

interface FieldProps {
  label: string;
  hint?: string;
  error?: string;
  required?: boolean;
  className?: string;
  /** A single form control; it receives id and ARIA wiring automatically. */
  children: ReactElement<Record<string, unknown>>;
}

/** Label + control + hint/error, with accessible associations handled for you. */
export function Field({ label, hint, error, required, className, children }: FieldProps) {
  const id = useId();
  const hintId = hint ? `${id}-hint` : undefined;
  const errorId = error ? `${id}-error` : undefined;
  const describedBy = [errorId, hintId].filter(Boolean).join(' ') || undefined;

  return (
    <div data-slot="field" className={cn('grid gap-2', className)}>
      <Label htmlFor={id}>
        {label}
        {required && (
          <span className="text-danger-11 ml-0.5" aria-hidden>
            *
          </span>
        )}
      </Label>
      {cloneElement(children, {
        id,
        'aria-describedby': describedBy,
        'aria-invalid': error ? true : undefined,
        'aria-required': required || undefined,
      })}
      {error ? (
        <p id={errorId} className="text-danger-11 text-sm">
          {error}
        </p>
      ) : (
        hint && (
          <p id={hintId} className="text-fg-muted text-sm">
            {hint}
          </p>
        )
      )}
    </div>
  );
}
