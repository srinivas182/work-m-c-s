import { Head, Link, router, useForm } from '@inertiajs/react';
import {
  Button,
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
  Field,
  Input,
  PageHeader,
  Textarea,
} from '@/design-system';
import AppShell from '@/layouts/AppShell';

interface Settings {
  targets: Record<string, number>;
  managed: number;
  other: number;
  business_hours: { tz: string; start: number; end: number; days: number[] };
  inbox_email: string | null;
}

interface Props {
  settings: Settings;
  fallbackEmail: string | null;
  canned: { id: string; title: string; body: string; uses: number }[];
}

const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

function CannedForm({ base, reply }: { base: string; reply?: Props['canned'][number] }) {
  const form = useForm({ title: reply?.title ?? '', body: reply?.body ?? '' });
  return (
    <form
      aria-label={reply ? `Canned reply: ${reply.title}` : 'New canned reply'}
      className="border-border grid gap-3 rounded-lg border p-3"
      onSubmit={(e) => {
        e.preventDefault();
        form.post(reply ? `${base}/canned/${reply.id}` : `${base}/canned`, {
          preserveScroll: true,
          onSuccess: () => {
            if (!reply) form.reset();
          },
        });
      }}
    >
      <Field label="Title" error={form.errors.title}>
        <Input
          maxLength={80}
          value={form.data.title}
          onChange={(e) => form.setData('title', e.target.value)}
        />
      </Field>
      <Field
        label="Reply"
        hint="{name} becomes the person’s first name, {business} the business’s name."
        error={form.errors.body}
      >
        <Textarea
          rows={4}
          value={form.data.body}
          onChange={(e) => form.setData('body', e.target.value)}
        />
      </Field>
      <div className="flex flex-wrap items-center gap-2">
        <Button
          type="submit"
          size="sm"
          variant={reply ? 'secondary' : 'primary'}
          loading={form.processing}
        >
          {reply ? 'Save' : 'Add canned reply'}
        </Button>
        {reply && (
          <>
            <Button
              type="button"
              size="sm"
              variant="ghost"
              onClick={() => router.delete(`${base}/canned/${reply.id}`, { preserveScroll: true })}
            >
              Delete
            </Button>
            <span className="text-fg-muted text-sm">Used {reply.uses} times</span>
          </>
        )}
      </div>
    </form>
  );
}

/** Admin → Support inbox → settings (Sprint 16.3b): first-reply targets, business hours, inbox email, canned replies. */
export default function SupportSettings({ settings, fallbackEmail, canned }: Props) {
  const base = window.location.pathname.replace(/\/settings\/?$/, '');
  const form = useForm<Settings>(settings);
  const hours = (label: string, value: number, set: (n: number) => void, error?: string) => (
    <Field label={label} error={error}>
      <Input
        inputMode="numeric"
        value={String(value)}
        onChange={(e) => set(Number(e.target.value) || 0)}
      />
    </Field>
  );
  return (
    <AppShell title="Support settings">
      <Head title="Support settings" />
      <div className="grid max-w-4xl gap-6">
        <PageHeader
          title="Support settings"
          description="How soon WURK replies first, when the clock runs, and ready-written replies."
          actions={
            <Button asChild variant="secondary">
              <Link href={base}>Inbox</Link>
            </Button>
          }
        />
        <form
          className="grid gap-6"
          onSubmit={(e) => {
            e.preventDefault();
            form.put(`${base}/settings`, { preserveScroll: true });
          }}
        >
          <Card>
            <CardHeader>
              <CardTitle>First-reply targets (business hours)</CardTitle>
              <CardDescription>
                Shown to businesses when they contact support. New tickets use these; open ones keep
                theirs.
              </CardDescription>
            </CardHeader>
            <CardContent className="grid gap-4 sm:grid-cols-3">
              {Object.entries(form.data.targets).map(([key, value]) =>
                hours(
                  `${key[0]?.toUpperCase()}${key.slice(1)} package`,
                  value,
                  (n) => form.setData('targets', { ...form.data.targets, [key]: n }),
                  form.errors[`targets.${key}` as keyof typeof form.errors],
                ),
              )}
              {hours(
                'Any managed package',
                form.data.managed,
                (n) => form.setData('managed', n),
                form.errors.managed,
              )}
              {hours(
                'Other packages',
                form.data.other,
                (n) => form.setData('other', n),
                form.errors.other,
              )}
            </CardContent>
          </Card>
          <Card>
            <CardHeader>
              <CardTitle>Business hours</CardTitle>
              <CardDescription>
                The target clock only runs in these hours ({settings.business_hours.tz}).
              </CardDescription>
            </CardHeader>
            <CardContent className="grid gap-4">
              <div className="grid gap-4 sm:grid-cols-2">
                {hours('Opens (hour, 0–23)', form.data.business_hours.start, (n) =>
                  form.setData('business_hours', { ...form.data.business_hours, start: n }),
                )}
                {hours(
                  'Closes (hour, 1–24)',
                  form.data.business_hours.end,
                  (n) => form.setData('business_hours', { ...form.data.business_hours, end: n }),
                  form.errors['business_hours.end' as keyof typeof form.errors],
                )}
              </div>
              <fieldset className="flex flex-wrap gap-3 text-sm">
                <legend className="mb-2 font-medium">Days</legend>
                {DAYS.map((d, i) => {
                  const day = i + 1;
                  const on = form.data.business_hours.days.includes(day);
                  return (
                    <label key={d} className="flex items-center gap-1">
                      <input
                        type="checkbox"
                        checked={on}
                        onChange={() =>
                          form.setData('business_hours', {
                            ...form.data.business_hours,
                            days: on
                              ? form.data.business_hours.days.filter((x) => x !== day)
                              : [...form.data.business_hours.days, day],
                          })
                        }
                      />
                      {d}
                    </label>
                  );
                })}
              </fieldset>
            </CardContent>
          </Card>
          <Card>
            <CardHeader>
              <CardTitle>Support inbox email</CardTitle>
              <CardDescription>
                New tickets and business replies are emailed here, and to whoever a ticket is
                assigned to.
              </CardDescription>
            </CardHeader>
            <CardContent>
              <Field
                label="Email"
                hint={`Leave empty to use ${fallbackEmail ?? 'the server’s setting'}.`}
                error={form.errors.inbox_email}
              >
                <Input
                  type="email"
                  value={form.data.inbox_email ?? ''}
                  onChange={(e) => form.setData('inbox_email', e.target.value || null)}
                />
              </Field>
            </CardContent>
          </Card>
          <Button type="submit" className="justify-self-start" loading={form.processing}>
            Save support settings
          </Button>
        </form>
        <Card>
          <CardHeader>
            <CardTitle>Canned replies</CardTitle>
            <CardDescription>
              Ready-written replies for common questions; the most used come first in the inbox.
            </CardDescription>
          </CardHeader>
          <CardContent className="grid gap-3">
            {canned.map((c) => (
              <CannedForm key={c.id} base={base} reply={c} />
            ))}
            <CannedForm base={base} />
          </CardContent>
        </Card>
      </div>
    </AppShell>
  );
}
