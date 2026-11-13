import { Info } from 'lucide-react';
import { useId, useState } from 'react';

/** A small "What's this?" for a field (Sprint 16.1b): keyboard- and screen-reader-friendly. */
export function HelpTip({
  children,
  label = 'What’s this?',
}: {
  children: string;
  label?: string;
}) {
  const [open, setOpen] = useState(false);
  const id = useId();
  return (
    <span className="inline-grid gap-1 align-middle">
      <button
        type="button"
        className="text-fg-muted hover:text-fg inline-flex items-center"
        aria-label={label}
        aria-expanded={open}
        aria-controls={id}
        onClick={() => setOpen(!open)}
      >
        <Info className="size-4" aria-hidden="true" />
      </button>
      <span
        id={id}
        role="note"
        hidden={!open}
        className="bg-surface text-fg rounded-md p-2 text-xs"
      >
        {children}
      </span>
    </span>
  );
}
