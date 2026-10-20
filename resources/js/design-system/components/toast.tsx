import { Toaster as Sonner, toast } from 'sonner';
import type { ResolvedTheme } from '../theme/theme';

/** Mount once in the app shell. Use `toast()` / `toast.success()` anywhere. */
export function Toaster({ theme }: { theme: ResolvedTheme }) {
  return (
    <Sonner
      theme={theme}
      position="bottom-right"
      closeButton
      toastOptions={{
        classNames: {
          toast:
            '!rounded-xl !border !border-border !bg-surface-raised !text-fg !shadow-lg !font-sans',
          description: '!text-fg-muted',
          actionButton: '!bg-primary !text-primary-fg',
        },
      }}
    />
  );
}

export { toast };
