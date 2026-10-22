import { Link, router, usePage } from '@inertiajs/react';
import {
  Bell,
  ChevronsLeft,
  LogOut,
  Menu,
  Monitor,
  Moon,
  MoreHorizontal,
  Search,
  Sun,
  User,
} from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';
import {
  Avatar,
  Button,
  cn,
  Dialog,
  DialogContent,
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
  Input,
  Sheet,
  SheetContent,
  Toaster,
  toast,
  Tooltip,
  TooltipProvider,
  useTheme,
  type ThemePreference,
} from '@/design-system';
import { icon } from '@/design-system/icons';
import type { NavigationItem, SharedProps } from '@/types/global';

interface ShellUser {
  name: string;
  email: string;
}

interface AppShellProps {
  title: string;
  user?: ShellUser;
  children: ReactNode;
}

const SIDEBAR_KEY = 'wurk-sidebar-collapsed';

function groupBySection(items: NavigationItem[]) {
  const groups = new Map<string, NavigationItem[]>();
  for (const item of items) {
    groups.set(item.section, [...(groups.get(item.section) ?? []), item]);
  }
  return [...groups.entries()];
}

function Logo({ compact = false }: { compact?: boolean }) {
  return (
    <Link
      href="/"
      className="flex items-center gap-2.5 rounded-lg"
      aria-label={compact ? 'WURK home' : undefined}
    >
      <span
        aria-hidden
        className="from-brand-8 to-brand-10 font-display text-primary-fg grid size-8 shrink-0 place-items-center rounded-lg bg-gradient-to-br text-sm font-bold shadow-sm"
      >
        W
      </span>
      {!compact && <span className="font-display text-lg font-bold tracking-tight">WURK</span>}
    </Link>
  );
}

function NavLinks({ items, collapsed = false }: { items: NavigationItem[]; collapsed?: boolean }) {
  return (
    <nav aria-label="Main" className="flex flex-col gap-6">
      {groupBySection(items).map(([section, sectionItems]) => (
        <div key={section}>
          {!collapsed && (
            <p className="text-fg-muted mb-2 px-3 text-xs font-medium tracking-wide uppercase">
              {section}
            </p>
          )}
          <ul className="flex flex-col gap-0.5">
            {sectionItems.map((item) => {
              const Icon = icon(item.icon);
              const link = (
                <Link
                  href={item.href}
                  aria-current={item.active ? 'page' : undefined}
                  className={cn(
                    'group flex h-10 items-center gap-3 rounded-lg px-3 text-sm font-medium transition-colors',
                    item.active
                      ? 'bg-accent text-accent-fg'
                      : 'text-fg-muted hover:bg-neutral-4 hover:text-fg',
                    collapsed && 'justify-center px-0',
                  )}
                >
                  <Icon className="size-[1.125rem] shrink-0" aria-hidden />
                  {collapsed ? <span className="sr-only">{item.label}</span> : item.label}
                </Link>
              );
              return (
                <li key={item.href}>
                  {collapsed ? (
                    <Tooltip content={item.label} side="right">
                      {link}
                    </Tooltip>
                  ) : (
                    link
                  )}
                </li>
              );
            })}
          </ul>
        </div>
      ))}
    </nav>
  );
}

function ThemeMenu() {
  const { preference, setPreference } = useTheme();
  const options: { value: ThemePreference; label: string; Icon: typeof Sun }[] = [
    { value: 'light', label: 'Light', Icon: Sun },
    { value: 'dark', label: 'Dark', Icon: Moon },
    { value: 'system', label: 'System', Icon: Monitor },
  ];
  const Current = options.find((o) => o.value === preference)?.Icon ?? Monitor;

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button variant="ghost" size="icon" aria-label="Change theme">
          <Current />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end">
        <DropdownMenuLabel>Appearance</DropdownMenuLabel>
        {options.map(({ value, label, Icon }) => (
          <DropdownMenuItem
            key={value}
            onSelect={() => setPreference(value)}
            className={cn(preference === value && 'font-semibold')}
          >
            <Icon /> {label}
          </DropdownMenuItem>
        ))}
      </DropdownMenuContent>
    </DropdownMenu>
  );
}

function SearchButton() {
  const [open, setOpen] = useState(false);

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        setOpen(true);
      }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, []);

  return (
    <>
      <button
        type="button"
        onClick={() => setOpen(true)}
        className="border-border bg-surface text-fg-muted hover:border-border-strong hidden h-10 w-72 cursor-pointer items-center gap-2.5 rounded-lg border px-3 text-sm shadow-xs transition-colors md:flex"
      >
        <Search className="size-4" aria-hidden />
        <span className="flex-1 text-left">Search…</span>
        <kbd className="border-border bg-neutral-2 rounded-md border px-1.5 py-0.5 font-sans text-xs">
          ⌘K
        </kbd>
      </button>
      <Button
        variant="ghost"
        size="icon"
        className="md:hidden"
        aria-label="Search"
        onClick={() => setOpen(true)}
      >
        <Search />
      </Button>
      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent title="Search" description="Find customers, bookings, orders and settings.">
          <Input autoFocus placeholder="Type to search…" aria-label="Search" />
          <p className="text-fg-muted mt-4 text-sm">
            Global search becomes available as modules are added.
          </p>
        </DialogContent>
      </Dialog>
    </>
  );
}

function UserMenu({ user }: { user: ShellUser }) {
  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <button
          type="button"
          className="focus-visible:outline-ring cursor-pointer rounded-full focus-visible:outline-2 focus-visible:outline-offset-2"
          aria-label="Account menu"
        >
          <Avatar name={user.name} size="sm" />
        </button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-60">
        <DropdownMenuLabel>
          <span className="text-fg block text-sm font-semibold">{user.name}</span>
          <span className="block truncate font-normal">{user.email}</span>
        </DropdownMenuLabel>
        <DropdownMenuSeparator />
        <DropdownMenuItem asChild>
          <Link href="/account/security">
            <User /> Account &amp; security
          </Link>
        </DropdownMenuItem>
        <DropdownMenuSeparator />
        <DropdownMenuItem destructive onSelect={() => router.post('/sign-out')}>
          <LogOut /> Sign out
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  );
}

/** Mobile bottom navigation: first four items plus "More". */
function BottomNav({ items, onMore }: { items: NavigationItem[]; onMore: () => void }) {
  const primary = items.slice(0, 4);
  return (
    <nav
      aria-label="Quick"
      className="border-border bg-surface/90 fixed inset-x-0 bottom-0 z-40 border-t pb-[env(safe-area-inset-bottom)] backdrop-blur-lg md:hidden"
    >
      <ul className="grid grid-cols-5">
        {primary.map((item) => {
          const Icon = icon(item.icon);
          return (
            <li key={item.href}>
              <Link
                href={item.href}
                aria-current={item.active ? 'page' : undefined}
                className={cn(
                  'flex h-16 flex-col items-center justify-center gap-1 text-[0.6875rem] font-medium',
                  item.active ? 'text-accent-fg' : 'text-fg-muted',
                )}
              >
                <Icon className="size-5" aria-hidden />
                <span className="max-w-full truncate px-1">{item.label}</span>
              </Link>
            </li>
          );
        })}
        <li className="col-start-5">
          <button
            type="button"
            onClick={onMore}
            className="text-fg-muted flex h-16 w-full cursor-pointer flex-col items-center justify-center gap-1 text-[0.6875rem] font-medium"
          >
            <MoreHorizontal className="size-5" aria-hidden />
            More
          </button>
        </li>
      </ul>
    </nav>
  );
}

export default function AppShell({ title, user: userProp, children }: AppShellProps) {
  const { navigation, auth, flash, banner } = usePage<SharedProps>().props;
  const user = userProp ?? auth.user ?? undefined;

  useEffect(() => {
    if (flash.status) toast.success(flash.status);
    if (flash.warning) toast.warning(flash.warning);
  }, [flash.status, flash.warning]);
  const { resolved } = useTheme();
  const [mobileOpen, setMobileOpen] = useState(false);
  const [collapsed, setCollapsed] = useState(() => {
    try {
      return window.localStorage.getItem(SIDEBAR_KEY) === '1';
    } catch {
      return false;
    }
  });

  const toggleCollapsed = () => {
    setCollapsed((value) => {
      try {
        window.localStorage.setItem(SIDEBAR_KEY, value ? '0' : '1');
      } catch {
        // ignore unavailable storage
      }
      return !value;
    });
  };

  return (
    <TooltipProvider delayDuration={300}>
      <a
        href="#main"
        className="bg-surface sr-only z-50 rounded-lg px-4 py-2 focus:not-sr-only focus:fixed focus:top-3 focus:left-3"
      >
        Skip to content
      </a>
      <div className="flex min-h-dvh">
        <aside
          className={cn(
            'border-border bg-surface sticky top-0 hidden h-dvh shrink-0 flex-col border-r transition-[width] duration-300 ease-[var(--ease-out-expo)] md:flex',
            collapsed ? 'w-[4.5rem]' : 'w-64',
          )}
        >
          <div className={cn('flex h-16 items-center px-5', collapsed && 'justify-center px-0')}>
            <Logo compact={collapsed} />
          </div>
          <div className={cn('flex-1 overflow-y-auto px-3 py-4', collapsed && 'px-2')}>
            <NavLinks items={navigation} collapsed={collapsed} />
          </div>
          <div className={cn('border-border border-t p-3', collapsed && 'flex justify-center')}>
            <Button
              variant="ghost"
              size={collapsed ? 'icon' : 'sm'}
              onClick={toggleCollapsed}
              aria-label={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
              aria-expanded={!collapsed}
              className={cn(!collapsed && 'text-fg-muted w-full justify-start')}
            >
              <ChevronsLeft className={cn('transition-transform', collapsed && 'rotate-180')} />
              {!collapsed && 'Collapse'}
            </Button>
          </div>
        </aside>

        <div className="flex min-w-0 flex-1 flex-col">
          <header className="border-border bg-canvas/80 sticky top-0 z-30 flex h-16 items-center gap-3 border-b px-4 backdrop-blur-lg sm:px-6">
            <Button
              variant="ghost"
              size="icon"
              className="md:hidden"
              aria-label="Open menu"
              onClick={() => setMobileOpen(true)}
            >
              <Menu />
            </Button>
            <p className="font-display truncate text-base font-semibold md:hidden">{title}</p>
            <div className="hidden md:block">
              <SearchButton />
            </div>
            <div className="ml-auto flex items-center gap-1">
              <div className="md:hidden">
                <SearchButton />
              </div>
              <Tooltip content="Notifications">
                <Button variant="ghost" size="icon" aria-label="Notifications">
                  <Bell />
                </Button>
              </Tooltip>
              <ThemeMenu />
              {user && (
                <div className="ml-2">
                  <UserMenu user={user} />
                </div>
              )}
            </div>
          </header>

          {banner && (
            <div
              role="alert"
              className={`flex flex-wrap items-center justify-between gap-3 px-4 py-3 text-sm sm:px-6 lg:px-10 ${banner.tone === 'danger' ? 'bg-danger-3 text-danger-12' : banner.tone === 'warning' ? 'bg-warning-3 text-warning-12' : 'bg-accent-3 text-fg'}`}
            >
              <span className="font-medium">{banner.message}</span>
              {banner.action && (
                <Link
                  href={banner.action.href}
                  className="bg-surface text-fg hover:bg-surface-raised rounded-lg px-3 py-1.5 font-semibold shadow-xs"
                >
                  {banner.action.label}
                </Link>
              )}
            </div>
          )}
          <main id="main" className="flex-1 px-4 pt-6 pb-28 sm:px-6 md:pb-10 lg:px-10 lg:pt-10">
            <div className="mx-auto w-full max-w-7xl">{children}</div>
          </main>
        </div>
      </div>

      <BottomNav items={navigation} onMore={() => setMobileOpen(true)} />

      <Sheet open={mobileOpen} onOpenChange={setMobileOpen}>
        <SheetContent side="left" title="Menu" hideTitle>
          <div className="px-5 pt-5 pb-6">
            <Logo />
          </div>
          <div className="px-3 pb-6">
            <NavLinks items={navigation} />
          </div>
        </SheetContent>
      </Sheet>

      <Toaster theme={resolved} />
    </TooltipProvider>
  );
}
