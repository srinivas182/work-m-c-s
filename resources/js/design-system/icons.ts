import {
  BarChart3,
  Building2,
  Calendar,
  Circle,
  Globe,
  KeyRound,
  HeartPulse,
  LayoutDashboard,
  Megaphone,
  MessageCircle,
  Palette,
  Settings,
  ShieldCheck,
  ShoppingBag,
  UserCog,
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
  building: Building2,
  'user-cog': UserCog,
  'key-round': KeyRound,
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
