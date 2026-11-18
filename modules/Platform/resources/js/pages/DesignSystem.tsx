import { Head } from '@inertiajs/react';
import {
  CalendarDays,
  Check,
  Copy,
  Inbox,
  MoreHorizontal,
  Pencil,
  Plus,
  RotateCcw,
  ShoppingBag,
  Sparkles,
  Trash2,
  TrendingUp,
  Users,
  Wallet,
} from 'lucide-react';
import { useEffect, useMemo, useState, type ReactNode } from 'react';
import {
  AA_NON_TEXT,
  AA_TEXT,
  AAA_TEXT,
  applyBrandTheme,
  buildBrandTheme,
  clearBrandTheme,
  contrast,
  isHex,
} from '@/design-system/brand';
import { Avatar } from '@/design-system/components/avatar';
import { Badge } from '@/design-system/components/badge';
import { Button } from '@/design-system/components/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/design-system/components/card';
import { Checkbox, RadioGroup, RadioGroupItem, Switch } from '@/design-system/components/choice';
import { Dialog, DialogClose, DialogContent, DialogFooter, DialogTrigger, Sheet, SheetContent, SheetTrigger } from '@/design-system/components/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/design-system/components/dropdown-menu';
import { EmptyState, PageHeader, Skeleton, StatCard } from '@/design-system/components/feedback';
import { Field, Label } from '@/design-system/components/field';
import { Input, Textarea } from '@/design-system/components/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/design-system/components/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/design-system/components/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/design-system/components/tabs';
import { toast } from '@/design-system/components/toast';
import { Tooltip } from '@/design-system/components/tooltip';
import { useTheme } from '@/design-system/theme/use-theme';
import { DEFAULT_BRAND } from '@/design-system/tokens/defaults';
import AppShell from '@/layouts/AppShell';
import { __ } from '@/i18n';

const PRESETS = [
  { name: 'WURK violet', hex: DEFAULT_BRAND },
  { name: 'Salon gold', hex: '#c9a227' },
  { name: 'Dental teal', hex: '#0f9d9a' },
  { name: 'Spa sage', hex: '#7c9a6d' },
  { name: 'Retail orange', hex: '#ff6a13' },
  { name: 'Fitness red', hex: '#e11d48' },
  { name: 'Ocean blue', hex: '#0369a1' },
  { name: 'Lemon (stress test)', hex: '#ffff00' },
];

function Section({
  id,
  title,
  description,
  children,
}: {
  id: string;
  title: string;
  description: string;
  children: ReactNode;
}) {
  return (
    <section id={id} aria-labelledby={`${id}-title`} className="scroll-mt-24">
      <div className="mb-5">
        <h2 id={`${id}-title`} className="text-xl font-semibold tracking-tight">
          {title}
        </h2>
        <p className="text-fg-muted mt-1 text-sm">{description}</p>
      </div>
      {children}
    </section>
  );
}

const readableOn = (bg: string) =>
  contrast('#ffffff', bg) >= contrast('#0c0a14', bg) ? '#ffffff' : '#0c0a14';

function Swatch({ color, label }: { color: string; label: string }) {
  const textColor = readableOn(color);
  return (
    <div className="flex flex-col gap-1.5">
      <div
        className="grid h-14 place-items-center rounded-xl border border-black/5 text-xs font-semibold shadow-xs"
        style={{ background: color, color: textColor }}
      >
        {label}
      </div>
      <code className="text-fg-muted text-center text-[0.6875rem]">{color}</code>
    </div>
  );
}

function ContrastRow({
  label,
  fg,
  bg,
  min,
  nonText = false,
}: {
  label: string;
  fg: string;
  bg: string;
  min: number;
  nonText?: boolean;
}) {
  const ratio = contrast(fg, bg);
  const pass = ratio >= min;
  return (
    <TableRow>
      <TableCell>
        {nonText ? (
          <span
            aria-hidden
            className="inline-block h-8 w-14 rounded-md border-2"
            style={{ background: bg, borderColor: fg }}
          />
        ) : (
          <span
            className="inline-grid h-8 min-w-14 place-items-center rounded-md px-2 text-xs font-semibold"
            style={{ background: bg, color: fg }}
          >
            {__('Aa')}
          </span>
        )}
      </TableCell>
      <TableCell className="font-medium">{label}</TableCell>
      <TableCell className="tabular-nums">{ratio.toFixed(2)}:1</TableCell>
      <TableCell className="text-fg-muted tabular-nums">≥ {min}:1</TableCell>
      <TableCell>
        <Badge tone={pass ? 'success' : 'danger'} dot>
          {pass ? 'Pass' : 'Fail'}
        </Badge>
      </TableCell>
    </TableRow>
  );
}

function BrandLab() {
  const { resolved } = useTheme();
  const [hex, setHex] = useState(DEFAULT_BRAND);
  const [draft, setDraft] = useState(DEFAULT_BRAND);
  const theme = useMemo(() => buildBrandTheme(hex), [hex]);
  const mode = theme.brand[resolved];
  const neutral = theme.neutral[resolved];

  useEffect(() => {
    const root = document.documentElement;
    if (hex === DEFAULT_BRAND) clearBrandTheme(root);
    else applyBrandTheme(root, theme, resolved);
  }, [hex, theme, resolved]);

  useEffect(() => () => clearBrandTheme(document.documentElement), []);

  const choose = (value: string) => {
    const normalised = value.startsWith('#') ? value : `#${value}`;
    setDraft(normalised);
    if (isHex(normalised) && normalised.length === 7) setHex(normalised.toLowerCase());
  };

  return (
    <div className="grid grid-cols-1 items-start gap-6 lg:grid-cols-[minmax(0,22rem)_minmax(0,1fr)]">
      <Card>
        <CardHeader>
          <CardTitle>{__('Brand colour')}</CardTitle>
          <CardDescription>
            {__(
              'Pick any colour. The engine builds light and dark scales and fixes anything that would be hard to read.',
            )}
          </CardDescription>
        </CardHeader>
        <CardContent className="grid grid-cols-1 gap-5">
          <div className="flex items-center gap-3">
            <input
              type="color"
              value={hex}
              onChange={(e) => choose(e.target.value)}
              aria-label={__('Brand colour picker')}
              className="border-border bg-surface size-12 shrink-0 cursor-pointer rounded-xl border p-1"
            />
            <Input
              value={draft}
              onChange={(e) => choose(e.target.value)}
              aria-label={__('Brand colour hex')}
              data-testid="brand-hex"
              className="font-mono uppercase"
              maxLength={7}
            />
            <Tooltip content="Reset to WURK violet">
              <Button
                variant="secondary"
                size="icon"
                aria-label={__('Reset brand colour')}
                onClick={() => choose(DEFAULT_BRAND)}
              >
                <RotateCcw />
              </Button>
            </Tooltip>
          </div>
          <div className="flex flex-wrap gap-2">
            {PRESETS.map((preset) => (
              <Tooltip key={preset.hex} content={preset.name}>
                <button
                  type="button"
                  onClick={() => choose(preset.hex)}
                  aria-label={preset.name}
                  aria-pressed={hex === preset.hex}
                  className="border-surface ring-border grid size-9 cursor-pointer place-items-center rounded-full border-2 shadow-sm ring-1 transition-transform hover:scale-110"
                  style={{ background: preset.hex }}
                >
                  {hex === preset.hex && (
                    <Check
                      className="size-4"
                      style={{ color: readableOn(preset.hex) }}
                      aria-hidden
                    />
                  )}
                </button>
              </Tooltip>
            ))}
          </div>
          <div className="bg-sunken rounded-xl p-4 text-sm">
            <p className="font-medium">{__('Contrast guard')}</p>
            {theme.brand.adjustments.length === 0 ? (
              <p className="text-fg-muted mt-1">
                {__('No adjustments needed — this colour works as-is.')}
              </p>
            ) : (
              <ul className="text-fg-muted mt-2 grid gap-1.5">
                {theme.brand.adjustments.map((note) => (
                  <li key={note} className="flex gap-2">
                    <Sparkles className="text-accent-fg mt-0.5 size-3.5 shrink-0" aria-hidden />
                    {note}
                  </li>
                ))}
              </ul>
            )}
          </div>
        </CardContent>
      </Card>

      <div className="grid min-w-0 grid-cols-1 gap-6">
        <Card>
          <CardHeader>
            <CardTitle>
              {__('Generated scales (')}
              {resolved} {__('mode)')}
            </CardTitle>
            <CardDescription>
              {__(
                'Steps 1–2 backgrounds · 3–5 component fills · 6–8 borders and focus · 9–10 solid · 11–12 text',
              )}
            </CardDescription>
          </CardHeader>
          <CardContent className="grid grid-cols-1 gap-5">
            <div>
              <p className="mb-2 text-sm font-medium">{__('Brand')}</p>
              <div className="grid grid-cols-6 gap-2 sm:grid-cols-12" data-testid="brand-scale">
                {mode.scale.map((c, i) => (
                  <Swatch key={i} color={c} label={String(i + 1)} />
                ))}
              </div>
            </div>
            <div>
              <p className="mb-2 text-sm font-medium">{__('Neutral (tinted toward the brand)')}</p>
              <div className="grid grid-cols-6 gap-2 sm:grid-cols-12">
                {neutral.scale.map((c, i) => (
                  <Swatch key={i} color={c} label={String(i + 1)} />
                ))}
              </div>
            </div>
          </CardContent>
        </Card>

        <Table label={__('Contrast report')}>
          <TableHeader>
            <TableRow>
              <TableHead>{__('Sample')}</TableHead>
              <TableHead>{__('Pairing')}</TableHead>
              <TableHead>{__('Ratio')}</TableHead>
              <TableHead>{__('Needs')}</TableHead>
              <TableHead>{__('WCAG 2.2')}</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            <ContrastRow
              label={__('Button text on brand')}
              fg={mode.onSolid}
              bg={mode.scale[8]}
              min={AA_TEXT}
            />
            <ContrastRow
              label={__('Button text on hover')}
              fg={mode.onSolid}
              bg={mode.scale[9]}
              min={AA_TEXT}
            />
            <ContrastRow
              label={__('Brand text on tinted fill')}
              fg={mode.scale[10]}
              bg={mode.scale[2]}
              min={AA_TEXT}
            />
            <ContrastRow
              label={__('Body text on background')}
              fg={neutral.scale[11]}
              bg={neutral.scale[1]}
              min={AAA_TEXT}
            />
            <ContrastRow
              label={__('Muted text on background')}
              fg={neutral.scale[10]}
              bg={neutral.scale[1]}
              min={AA_TEXT}
            />
            <ContrastRow
              label={__('Focus ring on background')}
              fg={mode.scale[7]}
              bg={neutral.scale[0]}
              min={AA_NON_TEXT}
              nonText
            />
          </TableBody>
        </Table>
      </div>
    </div>
  );
}

function Buttons() {
  const [loading, setLoading] = useState(false);
  return (
    <Card>
      <CardContent className="grid grid-cols-1 gap-6">
        <div className="flex flex-wrap items-center gap-3">
          <Button>{__('Primary')}</Button>
          <Button variant="secondary">{__('Secondary')}</Button>
          <Button variant="soft">{__('Soft')}</Button>
          <Button variant="ghost">{__('Ghost')}</Button>
          <Button variant="danger">{__('Delete')}</Button>
          <Button variant="link">{__('Link')}</Button>
        </div>
        <div className="flex flex-wrap items-center gap-3">
          <Button size="sm">
            <Plus /> {__('Small')}
          </Button>
          <Button>
            <Plus /> {__('Medium')}
          </Button>
          <Button size="lg">
            <Plus /> {__('Large')}
          </Button>
          <Button size="icon" variant="secondary" aria-label={__('Edit')}>
            <Pencil />
          </Button>
          <Button
            loading={loading}
            onClick={() => {
              setLoading(true);
              setTimeout(() => setLoading(false), 1500);
            }}
          >
            {__('Save changes')}
          </Button>
          <Button disabled>{__('Disabled')}</Button>
        </div>
      </CardContent>
    </Card>
  );
}

function Forms() {
  const [errors, setErrors] = useState(false);
  return (
    <Card>
      <CardHeader>
        <CardTitle>{__('New booking')}</CardTitle>
        <CardDescription>
          {__('Fields wire up labels, hints and errors for screen readers automatically.')}
        </CardDescription>
      </CardHeader>
      <CardContent className="grid grid-cols-1 gap-5 sm:grid-cols-2">
        <Field
          label={__('Customer name')}
          required
          error={errors ? 'Please enter the customer’s name.' : undefined}
        >
          <Input placeholder={__('e.g. Thandi Mokoena')} />
        </Field>
        <Field label={__('Phone')} hint={__('We’ll send a WhatsApp confirmation.')}>
          <Input type="tel" placeholder="+27 82 000 0000" />
        </Field>
        <Field label={__('Service')}>
          {(control) => (
            <Select defaultValue="cut">
              <SelectTrigger {...control}>
                <SelectValue placeholder={__('Choose a service')} />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="cut">Cut &amp; blow-dry · 45 min</SelectItem>
                <SelectItem value="colour">{__('Full colour · 2 h')}</SelectItem>
                <SelectItem value="braids">{__('Braids · 3 h')}</SelectItem>
              </SelectContent>
            </Select>
          )}
        </Field>
        <Field label={__('Branch')}>
          <Input defaultValue="Sandton City" />
        </Field>
        <Field label={__('Notes')} className="sm:col-span-2">
          <Textarea placeholder={__('Anything the stylist should know?')} />
        </Field>
        <div className="grid grid-cols-1 gap-4 sm:col-span-2 sm:grid-cols-3">
          <div className="flex items-center gap-3">
            <Checkbox id="deposit" defaultChecked />
            <Label htmlFor="deposit">{__('Require deposit')}</Label>
          </div>
          <div className="flex items-center gap-3">
            <Switch id="reminder" defaultChecked />
            <Label htmlFor="reminder">{__('WhatsApp reminder')}</Label>
          </div>
          <RadioGroup defaultValue="online" className="flex gap-4" aria-label={__('Payment')}>
            <div className="flex items-center gap-2">
              <RadioGroupItem value="online" id="pay-online" />
              <Label htmlFor="pay-online">{__('Pay online')}</Label>
            </div>
            <div className="flex items-center gap-2">
              <RadioGroupItem value="store" id="pay-store" />
              <Label htmlFor="pay-store">{__('In store')}</Label>
            </div>
          </RadioGroup>
        </div>
      </CardContent>
      <CardFooter className="justify-end">
        <Button variant="secondary" onClick={() => setErrors((e) => !e)}>
          {errors ? 'Clear errors' : 'Show errors'}
        </Button>
        <Button
          onClick={() =>
            toast.success('Booking confirmed', {
              description: 'Thandi will get a WhatsApp reminder.',
            })
          }
        >
          {__('Confirm booking')}
        </Button>
      </CardFooter>
    </Card>
  );
}

const ORDERS = [
  {
    id: 'WK-1042',
    customer: 'Thandi Mokoena',
    total: 'R 1 250.00',
    status: 'Paid',
    tone: 'success' as const,
  },
  {
    id: 'WK-1041',
    customer: 'Sipho Dlamini',
    total: 'R 480.00',
    status: 'Awaiting payment',
    tone: 'warning' as const,
  },
  {
    id: 'WK-1040',
    customer: 'Lerato Khumalo',
    total: 'R 2 990.00',
    status: 'Refunded',
    tone: 'danger' as const,
  },
  {
    id: 'WK-1039',
    customer: 'Johan van Wyk',
    total: 'R 760.00',
    status: 'Shipped',
    tone: 'info' as const,
  },
];

function DataDisplay() {
  return (
    <div className="grid grid-cols-1 gap-6">
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard
          label={__('Sales this month')}
          value="R 84 210"
          change={12.4}
          icon={TrendingUp}
          hint={__('vs last month')}
        />
        <StatCard
          label={__('Bookings')}
          value="342"
          change={8.1}
          icon={CalendarDays}
          hint={__('vs last month')}
        />
        <StatCard
          label={__('New customers')}
          value="58"
          change={-3.2}
          icon={Users}
          hint={__('vs last month')}
        />
        <StatCard
          label={__('Wallet liability')}
          value="R 6 480"
          icon={Wallet}
          hint={__('owed to referrers')}
        />
      </div>
      <Table label={__('Recent orders')}>
        <TableHeader>
          <TableRow>
            <TableHead>{__('Order')}</TableHead>
            <TableHead>{__('Customer')}</TableHead>
            <TableHead className="text-right">{__('Total')}</TableHead>
            <TableHead>{__('Status')}</TableHead>
            <TableHead className="w-12">
              <span className="sr-only">{__('Actions')}</span>
            </TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {ORDERS.map((o) => (
            <TableRow key={o.id}>
              <TableCell className="font-medium">{o.id}</TableCell>
              <TableCell>
                <div className="flex items-center gap-3">
                  <Avatar name={o.customer} size="sm" />
                  {o.customer}
                </div>
              </TableCell>
              <TableCell className="text-right tabular-nums">{o.total}</TableCell>
              <TableCell>
                <Badge tone={o.tone} dot>
                  {o.status}
                </Badge>
              </TableCell>
              <TableCell>
                <DropdownMenu>
                  <DropdownMenuTrigger asChild>
                    <Button variant="ghost" size="icon-sm" aria-label={`Actions for ${o.id}`}>
                      <MoreHorizontal />
                    </Button>
                  </DropdownMenuTrigger>
                  <DropdownMenuContent align="end">
                    <DropdownMenuItem>
                      <Pencil /> {__('Edit')}
                    </DropdownMenuItem>
                    <DropdownMenuItem>
                      <Copy /> {__('Duplicate')}
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem destructive>
                      <Trash2 /> {__('Delete')}
                    </DropdownMenuItem>
                  </DropdownMenuContent>
                </DropdownMenu>
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  );
}

function Feedback() {
  return (
    <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
      <EmptyState
        icon={Inbox}
        title={__('No bookings yet')}
        description={__(
          'Share your booking link on WhatsApp and your first bookings will appear here.',
        )}
        action={
          <Button
            onClick={() =>
              toast('Booking link copied', { description: 'srini-salon.wurk.co.za/book' })
            }
          >
            <Copy /> {__('Copy booking link')}
          </Button>
        }
      />
      <Card>
        <CardHeader>
          <CardTitle>{__('Loading state')}</CardTitle>
          <CardDescription>
            {__('Skeletons keep the layout steady while data loads.')}
          </CardDescription>
        </CardHeader>
        <CardContent className="grid grid-cols-1 gap-4">
          {[0, 1, 2].map((i) => (
            <div key={i} className="flex items-center gap-4">
              <Skeleton className="size-10 rounded-full" />
              <div className="grid flex-1 grid-cols-1 gap-2">
                <Skeleton className="h-3.5 w-2/5" />
                <Skeleton className="h-3 w-3/5" />
              </div>
            </div>
          ))}
          <div className="flex flex-wrap gap-2 pt-2">
            <Badge>{__('Neutral')}</Badge>
            <Badge tone="brand">{__('Brand')}</Badge>
            <Badge tone="success" dot>
              {__('Active')}
            </Badge>
            <Badge tone="warning" dot>
              {__('Pending')}
            </Badge>
            <Badge tone="danger" dot>
              {__('Overdue')}
            </Badge>
            <Badge tone="info">{__('New')}</Badge>
          </div>
        </CardContent>
      </Card>
    </div>
  );
}

function Overlays() {
  return (
    <Card>
      <CardContent className="flex flex-wrap gap-3">
        <Dialog>
          <DialogTrigger asChild>
            <Button variant="secondary">{__('Open dialog')}</Button>
          </DialogTrigger>
          <DialogContent
            title={__('Cancel this booking?')}
            description={__(
              'Thandi will be notified by WhatsApp. Her deposit is refunded to her wallet.',
            )}
          >
            <DialogFooter>
              <DialogClose asChild>
                <Button variant="secondary">{__('Keep booking')}</Button>
              </DialogClose>
              <DialogClose asChild>
                <Button variant="danger" onClick={() => toast.success('Booking cancelled')}>
                  {__('Cancel booking')}
                </Button>
              </DialogClose>
            </DialogFooter>
          </DialogContent>
        </Dialog>
        <Sheet>
          <SheetTrigger asChild>
            <Button variant="secondary">{__('Open side panel')}</Button>
          </SheetTrigger>
          <SheetContent title={__('Order WK-1042')}>
            <div className="grid grid-cols-1 gap-4 p-6 text-sm">
              <p className="text-fg-muted">
                {__('Detail drawers keep context while showing more.')}
              </p>
              <div className="flex items-center gap-3">
                <ShoppingBag className="text-accent-fg size-5" aria-hidden />
                <span>{__('3 items · R 1 250.00')}</span>
              </div>
            </div>
          </SheetContent>
        </Sheet>
        <Tooltip content="Tooltips explain icon-only controls">
          <Button variant="secondary">{__('Hover me')}</Button>
        </Tooltip>
        <Button
          variant="secondary"
          onClick={() => toast.success('Saved', { description: 'Your changes are live.' })}
        >
          {__('Show toast')}
        </Button>
      </CardContent>
    </Card>
  );
}

function Typography() {
  return (
    <Card>
      <CardContent className="grid grid-cols-1 gap-4">
        <p className="font-display text-display-lg font-bold">{__('Grow with WURK')}</p>
        <p className="font-display text-display font-bold">{__('Display · Plus Jakarta Sans')}</p>
        <h3 className="text-2xl font-semibold tracking-tight">{__('Heading · 24px semibold')}</h3>
        <p className="max-w-prose text-base text-pretty">
          {__(
            'Body · Inter 16px. Bookings, orders and referrals in one calm, fast back office — designed to work beautifully on a phone in a busy salon.',
          )}
        </p>
        <p className="text-fg-muted text-sm">{__('Muted · 14px for supporting information.')}</p>
      </CardContent>
    </Card>
  );
}

export default function DesignSystem() {
  return (
    <AppShell
      title={__('Design system')}
      user={{ name: 'Thandi Mokoena', email: 'thandi@salon.co.za' }}
    >
      <Head title={__('Design system')} />
      <div className="grid grid-cols-1 gap-14">
        <PageHeader
          eyebrow="Sprint 0.3"
          title={__('WURK design system')}
          description={__(
            'Every token and component in one place for review. Switch light and dark from the top bar, and try any brand colour below.',
          )}
          actions={
            <Badge tone="brand" dot>
              {__('Internal · not in production')}
            </Badge>
          }
        />
        <Section
          id="brand"
          title={__('Brand engine')}
          description={__('One colour in, a complete accessible palette out.')}
        >
          <BrandLab />
        </Section>
        <Tabs defaultValue="buttons">
          <TabsList aria-label={__('Component groups')}>
            <TabsTrigger value="buttons">{__('Buttons')}</TabsTrigger>
            <TabsTrigger value="forms">{__('Forms')}</TabsTrigger>
            <TabsTrigger value="data">{__('Data')}</TabsTrigger>
            <TabsTrigger value="feedback">{__('Feedback')}</TabsTrigger>
            <TabsTrigger value="overlays">{__('Overlays')}</TabsTrigger>
            <TabsTrigger value="type">{__('Typography')}</TabsTrigger>
          </TabsList>
          <TabsContent value="buttons">
            <Buttons />
          </TabsContent>
          <TabsContent value="forms">
            <Forms />
          </TabsContent>
          <TabsContent value="data">
            <DataDisplay />
          </TabsContent>
          <TabsContent value="feedback">
            <Feedback />
          </TabsContent>
          <TabsContent value="overlays">
            <Overlays />
          </TabsContent>
          <TabsContent value="type">
            <Typography />
          </TabsContent>
        </Tabs>
      </div>
    </AppShell>
  );
}
