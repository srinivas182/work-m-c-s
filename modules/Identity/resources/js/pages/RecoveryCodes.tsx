import { router } from '@inertiajs/react';
import { Copy, Download } from 'lucide-react';
import { Button, toast } from '@/design-system';
import AuthLayout from '@/layouts/AuthLayout';

interface Props {
  codes: string[];
  continueUrl: string;
}

export default function RecoveryCodes({ codes, continueUrl }: Props) {
  return (
    <AuthLayout
      title="Save your recovery codes"
      description="If you lose your phone, each of these codes lets you sign in once. Store them somewhere safe — they won’t be shown again."
    >
      <RecoveryCodeList codes={codes} />
      <Button size="lg" className="mt-6 w-full" onClick={() => router.post(continueUrl)}>
        I’ve saved them — continue
      </Button>
    </AuthLayout>
  );
}

export function RecoveryCodeList({ codes }: { codes: string[] }) {
  const text = codes.join('\n');
  const copy = async () => {
    await navigator.clipboard.writeText(text);
    toast.success('Recovery codes copied');
  };
  const download = () => {
    const url = URL.createObjectURL(
      new Blob([`WURK recovery codes\n\n${text}\n`], { type: 'text/plain' }),
    );
    const a = Object.assign(document.createElement('a'), {
      href: url,
      download: 'wurk-recovery-codes.txt',
    });
    a.click();
    URL.revokeObjectURL(url);
  };

  return (
    <div className="border-border bg-sunken rounded-2xl border p-5">
      <ul
        className="grid grid-cols-2 gap-x-6 gap-y-2 font-mono text-sm"
        aria-label="Recovery codes"
      >
        {codes.map((c) => (
          <li key={c}>{c}</li>
        ))}
      </ul>
      <div className="mt-4 flex gap-2">
        <Button variant="secondary" size="sm" onClick={copy}>
          <Copy /> Copy
        </Button>
        <Button variant="secondary" size="sm" onClick={download}>
          <Download /> Download
        </Button>
      </div>
    </div>
  );
}
