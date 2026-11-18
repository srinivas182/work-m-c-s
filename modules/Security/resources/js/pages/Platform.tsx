import { Head, useForm } from '@inertiajs/react';
import { Plus, X } from 'lucide-react';
import type { FormEvent } from 'react';
import {
  Button,
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
  Checkbox,
  Field,
  Input,
  Label,
  PageHeader,
} from '@/design-system';
import AppShell from '@/layouts/AppShell';
import { __ } from '@/i18n';

interface Props {
  allowlist: string[];
  yourIp: string;
  cashOtpRecipients: string[];
  cashReceiptRecipients: string[];
  staff: { id: string; name: string }[];
  contacts: { contact_email: string; support_email: string; security_email: string };
}

function ListEditor({
  label,
  values,
  onChange,
  placeholder,
  errors,
}: {
  label: string;
  values: string[];
  onChange: (v: string[]) => void;
  placeholder: string;
  errors: Record<string, string>;
}) {
  return (
    <div className="grid gap-2">
      {values.map((v, i) => (
        <div key={i} className="flex gap-2">
          <Input
            aria-label={`${label} ${i + 1}`}
            value={v}
            placeholder={placeholder}
            onChange={(e) => onChange(values.map((x, j) => (j === i ? e.target.value : x)))}
          />
          <Button
            type="button"
            variant="ghost"
            size="icon"
            aria-label={`Remove ${label} ${i + 1}`}
            onClick={() => onChange(values.filter((_, j) => j !== i))}
          >
            <X />
          </Button>
        </div>
      ))}
      {Object.entries(errors).map(([k, e]) => (
        <p key={k} className="text-danger-11 text-sm" role="alert">
          {e}
        </p>
      ))}
      <Button
        type="button"
        variant="secondary"
        size="sm"
        className="w-fit"
        onClick={() => onChange([...values, ''])}
      >
        <Plus /> {__('Add')}
      </Button>
    </div>
  );
}

export default function Platform({
  allowlist,
  yourIp,
  cashOtpRecipients,
  cashReceiptRecipients,
  staff,
  contacts,
}: Props) {
  const form = useForm({
    allowlist,
    cash_otp_recipients: cashOtpRecipients,
    cash_receipt_recipients: cashReceiptRecipients,
  });
  const errorsFor = (prefix: string) =>
    Object.fromEntries(
      Object.entries(form.errors).filter(([k]) => k === prefix || k.startsWith(prefix + '.')),
    );
  const submit = (e: FormEvent) => {
    e.preventDefault();
    form.put('/security', { preserveScroll: true });
  };

  return (
    <AppShell title={__('Security')}>
      <Head title={__('Security')} />
      <form onSubmit={submit} className="grid max-w-3xl gap-8">
        <PageHeader
          title={__('Security')}
          description={__(
            'Platform-wide protections. WURK staff always need an authenticator app, whatever is set here.',
          )}
        />
        <Card>
          <CardHeader>
            <CardTitle>{__('Admin access by network')}</CardTitle>
            <CardDescription>
              {__(
                'Leave empty to allow any network. When set, the WURK admin (including sign-in) only opens from these addresses. You’re on',
              )}{' '}
              <strong className="text-fg font-semibold">{yourIp}</strong>.
            </CardDescription>
          </CardHeader>
          <CardContent>
            <ListEditor
              label={__('IP address or range')}
              values={form.data.allowlist}
              placeholder="196.25.10.0/24"
              onChange={(v) => form.setData('allowlist', v)}
              errors={errorsFor('allowlist')}
            />
          </CardContent>
        </Card>
        <Card>
          <CardHeader>
            <CardTitle>{__('Cash payments')}</CardTitle>
            <CardDescription>
              {__(
                'When a cash payment is recorded (from Sprint 3), these people approve it with a one-time code, and receipts go to these addresses.',
              )}
            </CardDescription>
          </CardHeader>
          <CardContent className="grid gap-6">
            <fieldset className="grid gap-3">
              <legend className="mb-1 text-sm font-medium">{__('Approval codes go to')}</legend>
              {staff.map((s) => (
                <div key={s.id} className="flex items-center gap-3">
                  <Checkbox
                    id={`otp-${s.id}`}
                    checked={form.data.cash_otp_recipients.includes(s.id)}
                    onCheckedChange={(on) =>
                      form.setData(
                        'cash_otp_recipients',
                        on === true
                          ? [...form.data.cash_otp_recipients, s.id]
                          : form.data.cash_otp_recipients.filter((x) => x !== s.id),
                      )
                    }
                  />
                  <Label htmlFor={`otp-${s.id}`} className="font-normal">
                    {s.name}
                  </Label>
                </div>
              ))}
            </fieldset>
            <div className="grid gap-2">
              <p className="text-sm font-medium">{__('Receipts go to')}</p>
              <ListEditor
                label={__('Receipt email')}
                values={form.data.cash_receipt_recipients}
                placeholder={__('finance@wurk.co.za')}
                onChange={(v) => form.setData('cash_receipt_recipients', v)}
                errors={errorsFor('cash_receipt_recipients')}
              />
            </div>
          </CardContent>
        </Card>
        <Button type="submit" size="lg" className="w-fit" loading={form.processing}>
          {__('Save security settings')}
        </Button>
      </form>
      <Contacts contacts={contacts} />
    </AppShell>
  );
}

/** WURK's own contact addresses (19.6): set here, no longer only in the server's settings. */
function Contacts({ contacts }: { contacts: Props['contacts'] }) {
  const f = useForm(contacts);
  const fields: [keyof Props['contacts'], string, string][] = [
    [
      'contact_email',
      'Contact form messages go to',
      'Messages from wurk.co.za’s contact form and the sales assistant.',
    ],
    [
      'support_email',
      'Support address',
      'Given to businesses in emails, and where support emails go when no inbox is set.',
    ],
    [
      'security_email',
      'Security contact',
      'On security.txt and the Trust and Security pages, for reporting a problem.',
    ],
  ];
  return (
    <form
      aria-label={__('WURK’s contact addresses')}
      className="mt-8 grid max-w-3xl gap-4"
      onSubmit={(e) => {
        e.preventDefault();
        f.put('/security/contacts', { preserveScroll: true });
      }}
    >
      <Card>
        <CardHeader>
          <CardTitle>{__('WURK’s contact addresses')}</CardTitle>
          <CardDescription>
            {__('Where WURK can be reached, and where messages to WURK go.')}
          </CardDescription>
        </CardHeader>
        <CardContent className="grid gap-4">
          {fields.map(([key, label, hint]) => (
            <Field key={key} label={label} hint={hint} error={f.errors[key]}>
              <Input
                type="email"
                value={f.data[key]}
                onChange={(e) => f.setData(key, e.target.value)}
              />
            </Field>
          ))}
          <Button type="submit" className="w-fit" loading={f.processing}>
            {__('Save contact addresses')}
          </Button>
        </CardContent>
      </Card>
    </form>
  );
}
