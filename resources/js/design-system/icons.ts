import {
  BarChart3,
  Calendar,
  Circle,
  Globe,
  HeartPulse,
  LayoutDashboard,
  Megaphone,
  MessageCircle,
  Palette,
  Settings,
  ShieldCheck,
  ShoppingBag,
  Users,
  Wallet,
  type LucideIcon,
} from 'lucide-react';

/**
 * Icons that modules may reference by name from PHP menu() declarations.
 * Curated (rather than importing every Lucide icon) to keep the bundle small.
 */
export const icons: Record<string, LucideIcon> = {
  'bar-chart': BarChart3,
  calendar: Calendar,
  circle: Circle,
  globe: Globe,
  'heart-pulse': HeartPulse,
  'layout-dashboard': LayoutDashboard,
  megaphone: Megaphone,
  'message-circle': MessageCircle,
  palette: Palette,
  settings: Settings,
  'shield-check': ShieldCheck,
  'shopping-bag': ShoppingBag,
  users: Users,
  wallet: Wallet,
};

export function icon(name: string): LucideIcon {
  return icons[name] ?? Circle;
}
