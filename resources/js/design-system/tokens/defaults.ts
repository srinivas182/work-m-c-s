/** Platform default colours. Tenants override `brand` at runtime; status colours stay fixed. */
export const DEFAULT_BRAND = '#6d4aff';

export const STATUS_COLORS = {
  success: '#1f9d55',
  warning: '#f5a524',
  danger: '#e5484d',
  info: '#0091ff',
} as const;

export type StatusTone = keyof typeof STATUS_COLORS;
