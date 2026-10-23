import {
  Menu,
  TriangleAlert,
  Receipt,
  CreditCard,
  BarChart3,
  Building2,
  Calendar,
  Circle,
  Coins,
  Flag,
  Globe,
  KeyRound,
  HeartPulse,
  LayoutDashboard,
  LockKeyhole,
  Megaphone,
  MessageCircle,
  Package,
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
  'triangle-alert': TriangleAlert,
  receipt: Receipt,
  'credit-card': CreditCard,
  coins: Coins,
  'lock-keyhole': LockKeyhole,
  'user-cog': UserCog,
  'key-round': KeyRound,
  calendar: Calendar,
  circle: Circle,
  flag: Flag,
  globe: Globe,
  menu: Menu,
  'heart-pulse': HeartPulse,
  'layout-dashboard': LayoutDashboard,
  megaphone: Megaphone,
  package: Package,
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
