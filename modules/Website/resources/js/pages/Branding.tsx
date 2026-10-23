import '../site/fonts';
import { Head, router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import {
  Button,
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
  Input,
  PageHeader,
} from '@/design-system';
import { generatePalette } from '@/design-system/brand/palette';
import AppShell from '@/layouts/AppShell';
import { PhotoPicker } from '../editor/PhotoPicker';
import { SiteRenderer } from '../site/SiteRenderer';
import { FONT_PAIRINGS } from '../site/theme';
import type { Branding as BrandingValue, ContentDocument } from '../site/types';

interface Props {
  branding: BrandingValue;
  font_pairings: Record<string, { label: string }>;
  corner_styles: string[];
  businessName: string;
  photosUrl: string;
  base: string;
}

const PREVIEW: ContentDocument = {
  schema: 1,
  blocks: [
    { id: 'h', type: 'Header', version: 1, props: { buttonLabel: 'Book now' } },
    {
      id: 'hero',
      type: 'Hero',
      version: 1,
      props: {
        layout: 'centered',
        heading: 'This is how your website looks',
        text: 'Your colours, fonts and corners — change them on the left and watch this update.',
        facts: [{ text: 'Open today until 18:00' }],
      },
    },
  ],
};

const HEX = /^#[0-9A-Fa-f]{6}$/;

function ColourField({
  label,
  value,
  onChange,
  optional,
}: {
  label: string;
  value: string | null;
  onChange: (v: string | null) => void;
  optional?: boolean;
}) {
  const [text, setText] = useState(value ?? '');
  return (
    <div className="grid gap-1.5">
      <span className="text-sm font-medium">{label}</span>
      <div className="flex items-center gap-2">
        <input
          type="color"
          aria-label={`${label} picker`}
          value={value ?? '#6D4AFF'}
          onChange={(e) => {
            setText(e.target.value.toUpperCase());
            onChange(e.target.value.toUpperCase());
          }}
          className="border-border bg-surface size-11 cursor-pointer rounded-lg border p-1"
        />
        <Input
          aria-label={`${label} hex code`}
          value={text}
          placeholder={optional ? 'None' : '#6D4AFF'}
          className="w-32 font-mono uppercase"
          onChange={(e) => {
            setText(e.target.value);
            if (HEX.test(e.target.value)) onChange(e.target.value.toUpperCase());
            if (optional && e.target.value === '') onChange(null);
          }}
        />
        {optional && value && (
          <Button
            variant="ghost"
            size="sm"
            onClick={() => {
              setText('');
              onChange(null);
            }}
          >
            Remove
          </Button>
        )}
      </div>
    </div>
  );
}

function Swatches({ colour }: { colour: string }) {
  const scale = useMemo(() => generatePalette(colour).light.scale, [colour]);
  return (
    <div className="flex overflow-hidden rounded-lg ring-1 ring-black/5" aria-hidden>
      {scale.map((c, i) => (
        <span key={i} className="h-6 flex-1" style={{ background: c }} />
      ))}
    </div>
  );
}

export default function Branding({
  branding,
  font_pairings,
  corner_styles,
  businessName,
  photosUrl,
  base,
}: Props) {
  const [data, setData] = useState<BrandingValue>(branding);
  const [saving, setSaving] = useState(false);
  const errors = usePage().props.errors as Record<string, string>;
  const set = <K extends keyof BrandingValue>(k: K, v: BrandingValue[K]) =>
    setData((d) => ({ ...d, [k]: v }));
  const dirty = JSON.stringify(data) !== JSON.stringify(branding);

  return (
    <AppShell title="Branding">
      <Head title="Branding" />
      <div className="grid gap-8">
        <PageHeader
          title="Branding"
          description="Your logo, colours and fonts on every page. Colours are adjusted automatically so text always stays readable."
          actions={
            <Button
              disabled={!dirty}
              loading={saving}
              onClick={() =>
                router.put(base, data as never, {
                  preserveScroll: true,
                  onStart: () => setSaving(true),
                  onFinish: () => setSaving(false),
                })
              }
            >
              Save branding
            </Button>
          }
        />
        <div className="grid items-start gap-8 lg:grid-cols-[minmax(0,26rem)_1fr]">
          <div className="grid gap-6">
            <Card>
              <CardHeader>
                <CardTitle>Logo and icon</CardTitle>
                <CardDescription>
                  A wide logo works best. The icon shows in browser tabs.
                </CardDescription>
              </CardHeader>
              <CardContent className="grid gap-5">
                <div className="grid gap-1.5">
                  <span className="text-sm font-medium">Logo</span>
                  <PhotoPicker
                    max={1}
                    withAlt={false}
                    photosUrl={photosUrl}
                    value={data.logo ? [{ src: data.logo, alt: '' }] : []}
                    onChange={(n) => set('logo', n[0]?.src ?? null)}
                  />
                  {errors.logo && (
                    <p role="alert" className="text-danger-11 text-sm">
                      {errors.logo}
                    </p>
                  )}
                </div>
                <div className="grid gap-1.5">
                  <span className="text-sm font-medium">Browser icon</span>
                  <PhotoPicker
                    max={1}
                    withAlt={false}
                    photosUrl={photosUrl}
                    value={data.favicon ? [{ src: data.favicon, alt: '' }] : []}
                    onChange={(n) => set('favicon', n[0]?.src ?? null)}
                  />
                </div>
              </CardContent>
            </Card>
            <Card>
              <CardHeader>
                <CardTitle>Colours</CardTitle>
                <CardDescription>
                  Your main colour drives buttons and accents; we create the lighter and darker
                  shades.
                </CardDescription>
              </CardHeader>
              <CardContent className="grid gap-5">
                <ColourField
                  label="Main colour"
                  value={data.primary}
                  onChange={(v) => v && set('primary', v)}
                />
                <Swatches colour={data.primary} />
                {data.secondary ? (
                  <ColourField
                    label="Second colour"
                    optional
                    value={data.secondary}
                    onChange={(v) => set('secondary', v)}
                  />
                ) : (
                  // A colour input can't be empty, so an unset second colour is a button, not a misleading swatch.
                  <Button
                    variant="secondary"
                    className="w-fit"
                    onClick={() => set('secondary', '#F59E0B')}
                  >
                    Add a second colour
                  </Button>
                )}
                {data.secondary && <Swatches colour={data.secondary} />}
                {(errors.primary || errors.secondary) && (
                  <p role="alert" className="text-danger-11 text-sm">
                    {errors.primary ?? errors.secondary}
                  </p>
                )}
              </CardContent>
            </Card>
            <Card>
              <CardHeader>
                <CardTitle>Fonts</CardTitle>
              </CardHeader>
              <CardContent>
                <div role="radiogroup" aria-label="Font pairing" className="grid grid-cols-2 gap-3">
                  {Object.entries(font_pairings).map(([key, pairing]) => {
                    const fonts = FONT_PAIRINGS[key];
                    const selected = data.font_pairing === key;
                    return (
                      <button
                        key={key}
                        type="button"
                        role="radio"
                        aria-checked={selected}
                        onClick={() => set('font_pairing', key)}
                        className={`grid gap-1 rounded-xl border p-3 text-left transition ${selected ? 'border-primary ring-primary/30 ring-2' : 'border-border hover:border-fg-subtle'}`}
                      >
                        <span
                          className="text-2xl leading-none font-bold"
                          style={{ fontFamily: fonts?.display }}
                        >
                          Aa
                        </span>
                        <span className="text-sm" style={{ fontFamily: fonts?.body }}>
                          {pairing.label.split(' — ')[0]}
                        </span>
                      </button>
                    );
                  })}
                </div>
              </CardContent>
            </Card>
            <Card>
              <CardHeader>
                <CardTitle>Corners</CardTitle>
              </CardHeader>
              <CardContent>
                <div role="radiogroup" aria-label="Corners" className="flex gap-3">
                  {corner_styles.map((c) => (
                    <button
                      key={c}
                      type="button"
                      role="radio"
                      aria-checked={data.corners === c}
                      onClick={() => set('corners', c as BrandingValue['corners'])}
                      className={`flex-1 border px-3 py-2.5 text-sm font-medium capitalize ${data.corners === c ? 'border-primary ring-primary/30 ring-2' : 'border-border'} ${c === 'square' ? 'rounded-sm' : c === 'soft' ? 'rounded-xl' : 'rounded-full'}`}
                    >
                      {c}
                    </button>
                  ))}
                </div>
              </CardContent>
            </Card>
          </div>
          <div className="lg:sticky lg:top-6">
            <p className="text-fg-muted mb-2 text-sm font-medium">
              Live preview{dirty ? ' — not saved yet' : ''}
            </p>
            <div className="border-border overflow-hidden rounded-2xl border shadow-sm">
              <SiteRenderer
                document={PREVIEW}
                branding={data}
                site={{
                  businessName,
                  editing: false,
                  logo: data.logo,
                  menus: {
                    header: [
                      { label: 'Prices', href: '#' },
                      { label: 'Contact', href: '#' },
                    ],
                    footer: [],
                  },
                }}
              />
            </div>
          </div>
        </div>
      </div>
    </AppShell>
  );
}
