import { Avatar as AvatarPrimitive } from 'radix-ui';
import { cn } from '../lib/cn';

interface AvatarProps {
  name: string;
  src?: string;
  size?: 'sm' | 'md' | 'lg';
  className?: string;
}

const sizes = { sm: 'size-8 text-xs', md: 'size-10 text-sm', lg: 'size-14 text-lg' };

export function initials(name: string): string {
  const parts = name.trim().split(/\s+/).filter(Boolean);
  const first = parts[0]?.[0] ?? '';
  const last = parts.length > 1 ? (parts[parts.length - 1]?.[0] ?? '') : '';
  return (first + last).toUpperCase() || '?';
}

/**
 * The full name is the accessible name. Initials are drawn with CSS so they never
 * compete with it (WCAG 2.5.3 label in name) when an avatar sits inside a labelled button.
 */
export function Avatar({ name, src, size = 'md', className }: AvatarProps) {
  return (
    <AvatarPrimitive.Root
      data-slot="avatar"
      role="img"
      aria-label={name}
      className={cn(
        'ring-surface relative inline-flex shrink-0 overflow-hidden rounded-full ring-2',
        sizes[size],
        className,
      )}
    >
      {src && <AvatarPrimitive.Image src={src} alt="" className="size-full object-cover" />}
      <AvatarPrimitive.Fallback
        aria-hidden
        data-initials={initials(name)}
        className="bg-brand-4 text-brand-11 grid size-full place-items-center font-semibold before:content-[attr(data-initials)]"
      />
    </AvatarPrimitive.Root>
  );
}
