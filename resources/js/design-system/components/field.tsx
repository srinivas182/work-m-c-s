import { Label as LabelPrimitive } from 'radix-ui';
import {
  cloneElement,
  isValidElement,
  useId,
  type ComponentProps,
  type ReactElement,
  type ReactNode,
} from 'react';
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

/** Props a Field hands to its control so label, hint and error are announced. */
export interface FieldControlProps {
  id: string;
  'aria-describedby'?: string;
  'aria-invalid'?: true;
  'aria-required'?: true;
}

interface FieldProps {
  label: string;
  hint?: string;
  error?: string;
  required?: boolean;
  className?: string;
  /**
   * Either a single control element (receives the props automatically), or a
   * function for composite controls such as Select: `{(control) => <Select>
   * <SelectTrigger {...control} /> … </Select>}`.
   */
  children: ReactElement<Record<string, unknown>> | ((control: FieldControlProps) => ReactNode);
}

/** Label + control + hint/error, with accessible associations handled for you. */
export function Field({ label, hint, error, required, className, children }: FieldProps) {
  const id = useId();
  const hintId = hint ? `${id}-hint` : undefined;
  const errorId = error ? `${id}-error` : undefined;
  const control: FieldControlProps = {
    id,
    'aria-describedby': [errorId, hintId].filter(Boolean).join(' ') || undefined,
    'aria-invalid': error ? true : undefined,
    'aria-required': required ? true : undefined,
  };

  return (
    <div data-slot="field" className={cn('grid content-start gap-2', className)}>
      <Label htmlFor={id}>
        {label}
        {required && (
          <span className="text-danger-11 ml-0.5" aria-hidden>
            *
          </span>
        )}
      </Label>
      {typeof children === 'function'
        ? children(control)
        : isValidElement(children)
          ? cloneElement(children, { ...control })
          : children}
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
