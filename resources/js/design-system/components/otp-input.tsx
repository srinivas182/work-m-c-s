import { useRef, type ClipboardEvent, type KeyboardEvent } from 'react';
import { cn } from '../lib/cn';

interface OtpInputProps {
  value: string;
  onChange: (value: string) => void;
  /** Called once all digits are entered (typed or pasted). */
  onComplete?: (value: string) => void;
  length?: number;
  invalid?: boolean;
  disabled?: boolean;
  describedBy?: string;
  autoFocus?: boolean;
}

/**
 * Six separate digit boxes that behave like one field: typing moves forward,
 * backspace moves back, paste fills every box, and phones can autofill the
 * code from an SMS (autocomplete="one-time-code").
 */
export function OtpInput({
  value,
  onChange,
  onComplete,
  length = 6,
  invalid = false,
  disabled = false,
  describedBy,
  autoFocus = false,
}: OtpInputProps) {
  const refs = useRef<(HTMLInputElement | null)[]>([]);
  const digits = Array.from({ length }, (_, i) => value[i] ?? '');

  const update = (next: string) => {
    const clean = next.replace(/\D/g, '').slice(0, length);
    onChange(clean);
    if (clean.length === length) onComplete?.(clean);
    return clean;
  };

  const setDigit = (index: number, raw: string) => {
    const incoming = raw.replace(/\D/g, '');
    if (incoming.length > 1) {
      // Autofill or fast typing delivered several digits into one box.
      const clean = update(value.slice(0, index) + incoming);
      refs.current[Math.min(clean.length, length - 1)]?.focus();
      return;
    }
    const chars = digits.slice();
    chars[index] = incoming;
    update(chars.join(''));
    if (incoming && index < length - 1) refs.current[index + 1]?.focus();
  };

  const onKeyDown = (index: number, e: KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Backspace' && !digits[index] && index > 0) {
      refs.current[index - 1]?.focus();
    } else if (e.key === 'ArrowLeft' && index > 0) {
      refs.current[index - 1]?.focus();
    } else if (e.key === 'ArrowRight' && index < length - 1) {
      refs.current[index + 1]?.focus();
    }
  };

  const onPaste = (e: ClipboardEvent<HTMLInputElement>) => {
    e.preventDefault();
    const clean = update(e.clipboardData.getData('text'));
    refs.current[Math.min(clean.length, length - 1)]?.focus();
  };

  return (
    <div className="flex gap-2 sm:gap-3" role="group" aria-label="One-time code">
      {digits.map((digit, i) => (
        <input
          key={i}
          ref={(el) => {
            refs.current[i] = el;
          }}
          value={digit}
          onChange={(e) => setDigit(i, e.target.value)}
          onKeyDown={(e) => onKeyDown(i, e)}
          onPaste={onPaste}
          onFocus={(e) => e.target.select()}
          inputMode="numeric"
          autoComplete={i === 0 ? 'one-time-code' : 'off'}
          maxLength={i === 0 ? length : 1}
          disabled={disabled}
          autoFocus={autoFocus && i === 0}
          aria-label={`Digit ${i + 1} of ${length}`}
          aria-invalid={invalid || undefined}
          aria-describedby={describedBy}
          className={cn(
            'border-border-strong bg-surface font-display text-fg focus-visible:border-ring focus-visible:ring-ring/20 h-14 w-full min-w-0 rounded-xl border text-center text-2xl font-semibold shadow-xs transition-[border-color,box-shadow] duration-150 focus-visible:ring-4 focus-visible:outline-none disabled:opacity-60 sm:h-16',
            invalid && 'border-danger-9',
          )}
        />
      ))}
    </div>
  );
}
