import { useState } from 'react';
import { Button, Card, CardContent } from '@/design-system';
import { AgentLayout } from '../../components/AgentLayout';

/** The agent's marketing kit: link, QR code, and a ready message to share. */
export default function AgentKit({
  link,
  code,
  message,
  whatsapp,
}: {
  link: string;
  code: string;
  message: string;
  whatsapp: string;
}) {
  const [copied, setCopied] = useState<string | null>(null);
  const copy = async (text: string, what: string) => {
    try {
      await navigator.clipboard.writeText(text);
      setCopied(what);
    } catch {
      setCopied(null);
    }
  };
  return (
    <AgentLayout title="Marketing kit">
      <Card>
        <CardContent className="grid gap-3 pt-6 text-sm">
          <p>
            Your link (code <span className="font-mono">{code}</span>):{' '}
            <span className="font-mono break-all">{link}</span>
          </p>
          <div className="flex flex-wrap gap-2">
            <Button size="sm" variant="secondary" onClick={() => copy(link, 'link')}>
              Copy link
            </Button>
            <Button size="sm" variant="secondary" asChild>
              <a href="/agent/qr.svg" download>
                Download QR code
              </a>
            </Button>
          </div>
        </CardContent>
      </Card>
      <Card>
        <CardContent className="grid gap-3 pt-6 text-sm">
          <p className="font-medium">A message to share</p>
          <p className="bg-surface rounded-md p-3">{message}</p>
          <div className="flex flex-wrap gap-2">
            <Button size="sm" asChild>
              <a href={whatsapp} target="_blank" rel="noopener noreferrer">
                Share on WhatsApp
              </a>
            </Button>
            <Button size="sm" variant="secondary" onClick={() => copy(message, 'message')}>
              Copy message
            </Button>
          </div>
        </CardContent>
      </Card>
      <p role="status" className="text-sm">
        {copied === 'link' ? 'Link copied.' : copied === 'message' ? 'Message copied.' : ''}
      </p>
    </AgentLayout>
  );
}
