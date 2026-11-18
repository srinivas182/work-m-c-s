import { Head, router, useForm } from '@inertiajs/react';
import { Check, Link2, Mic, Square } from 'lucide-react';
import { useRef, useState } from 'react';
import {
  Button,
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  Field,
  Input,
  PageHeader,
  Textarea,
} from '@/design-system';
import AppShell from '@/layouts/AppShell';

interface Question {
  key: string;
  question: string;
  answer: string | null;
  understood: string | null;
}
interface Props {
  questions: Question[];
  next: string | null;
  preview: string[] | null;
  started: boolean;
  /** 23.2: the business's own photos found online; only chosen ones are used. */
  photos: { source: string; chosen: boolean }[];
}

const base = () => window.location.pathname.replace(/\/$/, '');

/** Record a voice note in the browser and send it as the answer. */
function VoiceButton({ question }: { question: string }) {
  const [recording, setRecording] = useState(false);
  const [sending, setSending] = useState(false);
  const recorder = useRef<MediaRecorder | null>(null);
  const chunks = useRef<Blob[]>([]);
  if (typeof window === 'undefined' || !('MediaRecorder' in window)) {
    return null;
  }
  const start = async () => {
    const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
    const r = new MediaRecorder(stream);
    chunks.current = [];
    r.ondataavailable = (e) => chunks.current.push(e.data);
    r.onstop = () => {
      stream.getTracks().forEach((t) => t.stop());
      const audio = new File(chunks.current, 'answer.webm', { type: r.mimeType || 'audio/webm' });
      setSending(true);
      router.post(
        `${base()}/voice`,
        { key: question, audio },
        { forceFormData: true, preserveScroll: true, onFinish: () => setSending(false) },
      );
    };
    recorder.current = r;
    r.start();
    setRecording(true);
  };
  const stop = () => {
    recorder.current?.stop();
    setRecording(false);
  };
  return recording ? (
    <Button type="button" variant="secondary" onClick={stop}>
      <Square aria-hidden /> Stop and send
    </Button>
  ) : (
    <Button type="button" variant="secondary" onClick={start} loading={sending}>
      <Mic aria-hidden /> Answer with a voice note
    </Button>
  );
}

function AnswerForm({
  q,
  again = false,
  done,
}: {
  q: Question;
  again?: boolean;
  done?: () => void;
}) {
  const form = useForm({ key: q.key, text: again ? (q.answer ?? '') : '' });
  return (
    <form
      aria-label={`Answer: ${q.question}`}
      className="grid gap-3"
      onSubmit={(e) => {
        e.preventDefault();
        form.post(`${base()}/answer`, {
          preserveScroll: true,
          onSuccess: () => {
            form.reset('text');
            done?.();
          },
        });
      }}
    >
      <Field
        label={q.question}
        error={form.errors.text ?? (form.errors as Record<string, string>).answer}
      >
        <Textarea
          rows={3}
          value={form.data.text}
          onChange={(e) => form.setData('text', e.target.value)}
          placeholder="Type it the way you'd say it"
        />
      </Field>
      <div className="flex flex-wrap gap-2">
        <Button type="submit" loading={form.processing}>
          {again ? 'Save' : 'Next'}
        </Button>
        <VoiceButton question={q.key} />
      </div>
    </form>
  );
}

/** 23.2: start from a Google listing, Facebook page, Instagram profile or website. */
function ImportForm() {
  const form = useForm({ link: '' });
  return (
    <form
      aria-label="Start from what you already have online"
      className="grid gap-3"
      onSubmit={(e) => {
        e.preventDefault();
        form.post(`${base()}/import`, { preserveScroll: true, onSuccess: () => form.reset() });
      }}
    >
      <Field
        label="Your Google listing, Facebook page, Instagram or website — or your business name and area"
        error={form.errors.link}
      >
        <Input
          value={form.data.link}
          onChange={(e) => form.setData('link', e.target.value)}
          placeholder="e.g. https://facebook.com/glowsalon or Glow Salon Soweto"
        />
      </Field>
      <Button
        type="submit"
        variant="secondary"
        className="justify-self-start"
        loading={form.processing}
      >
        <Link2 aria-hidden /> Fill in from there
      </Button>
    </form>
  );
}

/** Pick which of the photos found online go on the website (thumbnails come through WURK). */
function PhotoPicker({ photos }: { photos: Props['photos'] }) {
  const chosen = photos.flatMap((p, i) => (p.chosen ? [i] : []));
  const toggle = (i: number) =>
    router.post(
      `${base()}/photos`,
      { chosen: chosen.includes(i) ? chosen.filter((c) => c !== i) : [...chosen, i] },
      { preserveScroll: true },
    );
  return (
    <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3">
      {photos.map((p, i) => (
        <li key={i}>
          <label className="border-border has-[:checked]:border-brand grid cursor-pointer gap-2 rounded-md border p-2 text-xs">
            <img
              src={`${base()}/photo/${i}`}
              alt={`Photo ${i + 1} from ${p.source}`}
              className="aspect-[4/3] w-full rounded object-cover"
              loading="lazy"
            />
            <span className="flex items-center gap-2">
              <input type="checkbox" checked={p.chosen} onChange={() => toggle(i)} />
              Use photo {i + 1}
            </span>
          </label>
        </li>
      ))}
    </ul>
  );
}

export default function SetupTalk({ questions, next, preview, started, photos }: Props) {
  const [changing, setChanging] = useState<string | null>(null);
  const [applying, setApplying] = useState(false);
  const answered = questions.filter((q) => q.answer !== null);
  const current = questions.find((q) => q.key === next);
  return (
    <AppShell title="Set up by talking">
      <Head title="Set up by talking" />
      <div className="grid max-w-3xl gap-6">
        <PageHeader
          title="Set up by talking"
          description="Answer six short questions, typed or as voice notes, in any language. WURK sets up your website, services and hours from exactly what you say — nothing is invented, and nothing goes live until you publish."
        />
        {!started && (
          <Card>
            <CardHeader>
              <CardTitle>Already online? Start from there</CardTitle>
            </CardHeader>
            <CardContent className="grid gap-2 text-sm">
              <p className="text-fg-muted">
                WURK reads only what’s public, and you check every answer before anything is set up.
                Reviews aren’t copied.
              </p>
              <ImportForm />
            </CardContent>
          </Card>
        )}
        {answered.length > 0 && (
          <Card>
            <CardHeader>
              <CardTitle>What WURK understood</CardTitle>
            </CardHeader>
            <CardContent>
              <ol className="grid gap-3 text-sm">
                {answered.map((q) => (
                  <li key={q.key} className="grid gap-1">
                    <p className="text-fg-muted">{q.question}</p>
                    <p className="flex flex-wrap items-center gap-2">
                      <Check aria-hidden className="text-success size-4" />
                      <span>{q.understood}</span>
                      <Button
                        size="sm"
                        variant="ghost"
                        aria-label={`Change: ${q.question}`}
                        onClick={() => setChanging(changing === q.key ? null : q.key)}
                      >
                        Change
                      </Button>
                    </p>
                    {changing === q.key && (
                      <AnswerForm q={q} again done={() => setChanging(null)} />
                    )}
                  </li>
                ))}
              </ol>
            </CardContent>
          </Card>
        )}
        {photos.length > 0 && (
          <Card>
            <CardHeader>
              <CardTitle>Your photos</CardTitle>
            </CardHeader>
            <CardContent className="grid gap-3 text-sm">
              <p className="text-fg-muted">
                These are photos you put online yourself. Tick the ones to use on your website —
                only use photos of your own business and work.
              </p>
              <PhotoPicker photos={photos} />
            </CardContent>
          </Card>
        )}
        {current && (
          <Card>
            <CardHeader>
              <CardTitle>
                Question {answered.length + 1} of {questions.length}
              </CardTitle>
            </CardHeader>
            <CardContent>
              <AnswerForm key={current.key} q={current} />
            </CardContent>
          </Card>
        )}
        {preview && (
          <Card>
            <CardHeader>
              <CardTitle>Ready to set up</CardTitle>
            </CardHeader>
            <CardContent className="grid gap-4 text-sm">
              <p>This is exactly what will happen:</p>
              <ul className="list-disc space-y-1 pl-5">
                {preview.map((line) => (
                  <li key={line}>{line}</li>
                ))}
              </ul>
              <div className="flex flex-wrap gap-2">
                <Button
                  loading={applying}
                  onClick={() => {
                    setApplying(true);
                    router.post(`${base()}/apply`, {}, { onFinish: () => setApplying(false) });
                  }}
                >
                  Set it up
                </Button>
                <Button
                  variant="ghost"
                  onClick={() => router.post(`${base()}/restart`, {}, { preserveScroll: true })}
                >
                  Start again
                </Button>
              </div>
            </CardContent>
          </Card>
        )}
      </div>
    </AppShell>
  );
}
