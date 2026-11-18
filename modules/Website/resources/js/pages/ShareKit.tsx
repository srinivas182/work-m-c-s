import { Head } from '@inertiajs/react';
import { Copy, Download, Image as ImageIcon, Share2 } from 'lucide-react';
import { useState } from 'react';
import { Button, Card, CardContent, CardHeader, CardTitle, PageHeader } from '@/design-system';
import AppShell from '@/layouts/AppShell';

type Source = 'status' | 'whatsapp' | 'facebook' | 'instagram' | 'share' | 'poster';
interface Item {
  key: string;
  kind: 'site' | 'book' | 'order' | 'product' | 'special' | 'chat';
  title: string;
  detail: string;
  url: string;
  links: Record<Source, string>;
}
interface Props {
  items: Item[];
  posters: { kind: string; label: string }[];
  sizes: { key: string; label: string }[];
}

const base = () => window.location.pathname.replace(/\/$/, '');

/** Opens the phone's share sheet (WhatsApp, Facebook, Instagram…), or copies the link where there is none. */
async function shareLink(title: string, url: string, copied: () => void) {
  if (navigator.share) {
    try {
      await navigator.share({ title, url });
      return;
    } catch {
      return; // cancelled
    }
  }
  await navigator.clipboard?.writeText(url);
  copied();
}

/** Shares the Status image straight to WhatsApp where the phone allows it; otherwise saves it to post as a Status. */
async function shareStatus(item: Item) {
  const res = await fetch(`${base()}/status/${item.key}`, { credentials: 'same-origin' });
  const blob = await res.blob();
  const file = new File([blob], `${item.key}-status.png`, { type: 'image/png' });
  if (navigator.canShare?.({ files: [file] })) {
    try {
      await navigator.share({ files: [file], text: item.links.status });
      return;
    } catch {
      return;
    }
  }
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = file.name;
  a.click();
  URL.revokeObjectURL(a.href);
}

function ItemRow({ item }: { item: Item }) {
  const [copied, setCopied] = useState(false);
  const done = () => {
    setCopied(true);
    window.setTimeout(() => setCopied(false), 2000);
  };
  const wa = `https://wa.me/?text=${encodeURIComponent(`${item.title} — ${item.links.whatsapp}`)}`;
  const fb = `https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(item.links.facebook)}`;
  return (
    <li className="border-border grid gap-2 border-b pb-4 last:border-0 last:pb-0">
      <div>
        <p className="font-medium">{item.title}</p>
        <p className="text-fg-muted text-sm">{item.detail}</p>
      </div>
      <div className="flex flex-wrap gap-2">
        <Button
          size="sm"
          onClick={() => void shareStatus(item)}
          aria-label={`WhatsApp Status image: ${item.title}`}
        >
          <ImageIcon aria-hidden className="size-4" /> WhatsApp Status
        </Button>
        {item.kind !== 'chat' && (
          <>
            <Button size="sm" variant="secondary" asChild>
              <a
                href={wa}
                target="_blank"
                rel="noopener noreferrer"
                aria-label={`WhatsApp chat: ${item.title}`}
              >
                WhatsApp chat
              </a>
            </Button>
            <Button size="sm" variant="secondary" asChild>
              <a
                href={fb}
                target="_blank"
                rel="noopener noreferrer"
                aria-label={`Facebook: ${item.title}`}
              >
                Facebook
              </a>
            </Button>
            <Button
              size="sm"
              variant="secondary"
              aria-label={`Instagram: ${item.title}`}
              onClick={() => void shareLink(item.title, item.links.instagram, done)}
            >
              Instagram
            </Button>
          </>
        )}
        <Button
          size="sm"
          variant="ghost"
          aria-label={`More ways to share: ${item.title}`}
          onClick={() => void shareLink(item.title, item.links.share, done)}
        >
          <Share2 aria-hidden className="size-4" /> More ways to share
        </Button>
        <Button
          size="sm"
          variant="ghost"
          aria-label={`${copied ? 'Copied' : 'Copy link'}: ${item.title}`}
          onClick={() => void navigator.clipboard?.writeText(item.links.share).then(done)}
        >
          <Copy aria-hidden className="size-4" /> {copied ? 'Copied' : 'Copy link'}
        </Button>
      </div>
    </li>
  );
}

export default function ShareKit({ items, posters, sizes }: Props) {
  return (
    <AppShell title="Share">
      <Head title="Share" />
      <div className="grid max-w-3xl gap-6">
        <PageHeader
          title="Share"
          description="Share your website, booking page, products and specials in one tap. Each link remembers where it was shared, so Reports → Website shows what brought customers."
        />
        <Card>
          <CardHeader>
            <CardTitle>Share a link</CardTitle>
          </CardHeader>
          <CardContent>
            {items.length === 0 ? (
              <p className="text-fg-muted text-sm">
                Publish your website first — then you can share it from here.
              </p>
            ) : (
              <ul className="grid gap-4">
                {items.map((item) => (
                  <ItemRow key={item.key} item={item} />
                ))}
              </ul>
            )}
          </CardContent>
        </Card>
        {posters.length > 0 && (
          <Card>
            <CardHeader>
              <CardTitle>Posters and table cards</CardTitle>
            </CardHeader>
            <CardContent className="grid gap-4 text-sm">
              <p className="text-fg-muted">
                Print these for your counter, window or tables. The QR code uses your colours and
                logo, and visits from it show as “Poster” in Reports.
              </p>
              <ul className="grid gap-3">
                {posters.map((p) => (
                  <li key={p.kind} className="grid gap-2">
                    <p className="font-medium">{p.label}</p>
                    <div className="flex flex-wrap gap-2">
                      {sizes.map((s) => (
                        <Button key={s.key} size="sm" variant="secondary" asChild>
                          <a
                            href={`${base()}/poster?kind=${p.kind}&size=${s.key}`}
                            aria-label={`${p.label}: ${s.label} (PDF)`}
                          >
                            <Download aria-hidden className="size-4" /> {s.label}
                          </a>
                        </Button>
                      ))}
                    </div>
                  </li>
                ))}
              </ul>
            </CardContent>
          </Card>
        )}
      </div>
    </AppShell>
  );
}
