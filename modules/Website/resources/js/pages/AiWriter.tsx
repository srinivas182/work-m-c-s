import { Head, useForm } from '@inertiajs/react';
import { Plus, Sparkles, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { AiConfirm } from '@/components/AiConfirm';
import {
  Button,
  Card,
  CardContent,
  Field,
  Input,
  PageHeader,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Textarea,
} from '@/design-system';
import AppShell from '@/layouts/AppShell';

interface Props {
  business: string;
  base: string;
}

const TONES = [
  { value: 'warm', label: 'Warm and friendly' },
  { value: 'professional', label: 'Professional' },
  { value: 'playful', label: 'Playful' },
  { value: 'luxurious', label: 'Luxurious' },
];

export default function AiWriter({ business, base }: Props) {
  const [confirming, setConfirming] = useState(false);
  const form = useForm({
    confirmation: '',
    business,
    what: '',
    area: '',
    tone: 'warm',
    services: [] as { name: string; price: number | null }[],
  });
  const tenantBase = base.replace(/\/website\/ai$/, '');
  const errors = form.errors as Record<string, string>;

  return (
    <AppShell title="Write with AI">
      <Head title="Write with AI" />
      <div className="grid max-w-2xl gap-6">
        <PageHeader
          title="Write your website with AI"
          description="Tell WURK AI about your business. It writes your pages as drafts — you review everything before it goes live."
        />
        <Card>
          <CardContent className="grid gap-5 p-6">
            <Field label="Business name" error={form.errors.business}>
              <Input
                value={form.data.business}
                onChange={(e) => form.setData('business', e.target.value)}
                maxLength={80}
              />
            </Field>
            <Field
              label="What do you do?"
              hint="One or two sentences, e.g. “Braids and natural hair care for women and children.”"
              error={form.errors.what}
            >
              <Textarea
                value={form.data.what}
                onChange={(e) => form.setData('what', e.target.value)}
                maxLength={300}
                rows={3}
              />
            </Field>
            <Field label="Where are you? (optional)" error={form.errors.area}>
              <Input
                value={form.data.area}
                onChange={(e) => form.setData('area', e.target.value)}
                maxLength={80}
                placeholder="e.g. Durban North"
              />
            </Field>
            <Field label="Tone">
              <Select value={form.data.tone} onValueChange={(v) => form.setData('tone', v)}>
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {TONES.map((t) => (
                    <SelectItem key={t.value} value={t.value}>
                      {t.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </Field>
            <fieldset className="grid gap-3">
              <legend className="mb-1 text-sm font-medium">
                Your main services and prices (optional)
              </legend>
              <p className="text-fg-muted text-sm">
                Prices only ever come from you — WURK AI never makes them up.
              </p>
              {form.data.services.map((s, i) => (
                <div key={i} className="flex items-end gap-2">
                  <Field label="Service" className="flex-1">
                    <Input
                      value={s.name}
                      maxLength={60}
                      onChange={(e) =>
                        form.setData(
                          'services',
                          form.data.services.map((x, j) =>
                            j === i ? { ...x, name: e.target.value } : x,
                          ),
                        )
                      }
                    />
                  </Field>
                  <Field label="Price (R)" className="w-32">
                    <Input
                      inputMode="numeric"
                      value={s.price === null ? '' : String(s.price / 100)}
                      onChange={(e) => {
                        const rands = e.target.value.replace(/[^\d.]/g, '');
                        form.setData(
                          'services',
                          form.data.services.map((x, j) =>
                            j === i
                              ? {
                                  ...x,
                                  price: rands === '' ? null : Math.round(Number(rands) * 100),
                                }
                              : x,
                          ),
                        );
                      }}
                    />
                  </Field>
                  <Button
                    variant="ghost"
                    size="icon"
                    aria-label={`Remove ${s.name || 'service'}`}
                    onClick={() =>
                      form.setData(
                        'services',
                        form.data.services.filter((_, j) => j !== i),
                      )
                    }
                  >
                    <Trash2 />
                  </Button>
                </div>
              ))}
              {form.data.services.length < 20 && (
                <Button
                  variant="secondary"
                  size="sm"
                  className="justify-self-start"
                  onClick={() =>
                    form.setData('services', [...form.data.services, { name: '', price: null }])
                  }
                >
                  <Plus /> Add a service
                </Button>
              )}
            </fieldset>
            {errors.ai && (
              <p role="alert" className="text-danger-11 text-sm">
                {errors.ai}
              </p>
            )}
            <Button
              size="lg"
              disabled={form.data.what.trim() === ''}
              onClick={() => setConfirming(true)}
            >
              <Sparkles /> Write my website
            </Button>
          </CardContent>
        </Card>
      </div>
      <AiConfirm
        open={confirming}
        onOpenChange={setConfirming}
        quoteUrl={`${base}/quote?action=generate_site`}
        tenantBase={tenantBase}
        busy={form.processing}
        onConfirm={() => {
          form.transform((d) => ({ ...d, confirmation: crypto.randomUUID() }));
          form.post(`${base}/generate`, { onFinish: () => setConfirming(false) });
        }}
      />
    </AppShell>
  );
}
