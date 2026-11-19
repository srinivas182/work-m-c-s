import { Head, router, useForm } from '@inertiajs/react';
import { Star } from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/design-system/components/badge';
import { Button } from '@/design-system/components/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/design-system/components/card';
import { Switch } from '@/design-system/components/choice';
import { EmptyState, PageHeader } from '@/design-system/components/feedback';
import { Field } from '@/design-system/components/field';
import { Input, Textarea } from '@/design-system/components/input';
import AppShell from '@/layouts/AppShell';
import { __ } from '@/i18n';

interface Review {
  id: string;
  name: string;
  title: string;
  rating: number;
  comment: string | null;
  private_note: string | null;
  reply: string | null;
  hidden_reason: string | null;
  date: string;
}

interface Props {
  summary: { count: number; average: number | null };
  needsReply: number;
  reviews: Review[];
  asked: { month: number; answered: number };
  settings: { requests_on: boolean; google_url: string | null };
  hideReasons: Record<string, string>;
}

const tenantBase = () => '/' + window.location.pathname.split('/')[1];
const stars = (n: number) => '★'.repeat(n) + '☆'.repeat(5 - n);

function ReviewCard({
  review,
  hideReasons,
}: {
  review: Review;
  hideReasons: Props['hideReasons'];
}) {
  const [replying, setReplying] = useState(false);
  const [reason, setReason] = useState('');
  const form = useForm({ reply: review.reply ?? '' });
  const url = `${tenantBase()}/reviews/${review.id}`;
  return (
    <li className="border-border grid gap-2 rounded-lg border p-4">
      <div className="flex flex-wrap items-center gap-2">
        <span
          aria-label={__(':n out of 5 stars', { n: review.rating })}
          className="text-warning-11 text-lg"
        >
          {stars(review.rating)}
        </span>
        <strong>{review.name}</strong>
        <span className="text-fg-muted text-sm">
          {review.title} · {review.date}
        </span>
        {review.hidden_reason && (
          <Badge>
            {__('Hidden: :reason', {
              reason: hideReasons[review.hidden_reason] ?? review.hidden_reason,
            })}
          </Badge>
        )}
        {review.rating <= 3 && !review.reply && !review.hidden_reason && (
          <Badge tone="warning">{__('Needs a reply')}</Badge>
        )}
      </div>
      {review.comment && <p className="whitespace-pre-line">{review.comment}</p>}
      {review.private_note && (
        <p className="bg-neutral-3 rounded-md p-3 text-sm">
          <strong>{__('Only for you:')}</strong> {review.private_note}
        </p>
      )}
      {review.reply && !replying && (
        <p className="border-border border-l-2 pl-3 text-sm">
          <strong>{__('Your reply:')}</strong> {review.reply}
        </p>
      )}
      {replying ? (
        <form
          className="grid gap-2"
          onSubmit={(e) => {
            e.preventDefault();
            form.put(`${url}/reply`, { preserveScroll: true, onSuccess: () => setReplying(false) });
          }}
        >
          <Textarea
            aria-label={__('Your reply, shown under the review')}
            maxLength={1000}
            value={form.data.reply}
            onChange={(e) => form.setData('reply', e.target.value)}
          />
          <div className="flex gap-2">
            <Button size="sm" type="submit" loading={form.processing}>
              {__('Save reply')}
            </Button>
            <Button size="sm" variant="ghost" type="button" onClick={() => setReplying(false)}>
              {__('Cancel')}
            </Button>
          </div>
        </form>
      ) : (
        <div className="flex flex-wrap items-center gap-2">
          <Button size="sm" variant="secondary" onClick={() => setReplying(true)}>
            {review.reply ? __('Change reply') : __('Reply')}
          </Button>
          {review.hidden_reason ? (
            <Button
              size="sm"
              variant="ghost"
              onClick={() => router.put(`${url}/show`, {}, { preserveScroll: true })}
            >
              {__('Show on my website again')}
            </Button>
          ) : (
            <span className="flex items-center gap-2">
              <select
                aria-label={__('Why hide it?')}
                className="border-border bg-bg h-8 rounded-md border px-2 text-sm"
                value={reason}
                onChange={(e) => setReason(e.target.value)}
              >
                <option value="">{__('Hide it because…')}</option>
                {Object.entries(hideReasons).map(([key, label]) => (
                  <option key={key} value={key}>
                    {label}
                  </option>
                ))}
              </select>
              <Button
                size="sm"
                variant="ghost"
                disabled={reason === ''}
                onClick={() => router.put(`${url}/hide`, { reason }, { preserveScroll: true })}
              >
                {__('Hide')}
              </Button>
            </span>
          )}
        </div>
      )}
    </li>
  );
}

/** Customers → Reviews (Sprint 25.3). */
export default function Index({
  summary,
  needsReply,
  reviews,
  asked,
  settings,
  hideReasons,
}: Props) {
  const form = useForm({
    requests_on: settings.requests_on,
    google_url: settings.google_url ?? '',
  });
  return (
    <AppShell title={__('Reviews')}>
      <Head title={__('Reviews')} />
      <div className="grid max-w-3xl gap-6">
        <PageHeader
          title={__('Reviews')}
          description={__(
            'After each visit or order, customers are asked how it was. Everyone who answers is offered your Google review link and a private word with you — never only the happy ones.',
          )}
        />
        <div className="grid gap-4 sm:grid-cols-3">
          <Card>
            <CardContent className="pt-5">
              <p className="text-fg-muted text-sm">{__('Average')}</p>
              <p className="text-2xl font-semibold tabular-nums">
                {summary.average === null ? '—' : `${summary.average} ★`}
              </p>
              <p className="text-fg-muted text-sm">{__(':n reviews', { n: summary.count })}</p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="pt-5">
              <p className="text-fg-muted text-sm">{__('Asked this month')}</p>
              <p className="text-2xl font-semibold tabular-nums">{asked.month}</p>
              <p className="text-fg-muted text-sm">{__(':n answered', { n: asked.answered })}</p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="pt-5">
              <p className="text-fg-muted text-sm">{__('Waiting for your reply')}</p>
              <p className="text-2xl font-semibold tabular-nums">{needsReply}</p>
              <p className="text-fg-muted text-sm">{__('3 stars or fewer')}</p>
            </CardContent>
          </Card>
        </div>

        {reviews.length === 0 ? (
          <EmptyState
            icon={Star}
            title={__('No reviews yet')}
            description={__('Customers are asked a couple of hours after their visit or order.')}
          />
        ) : (
          <ul aria-label={__('Reviews')} className="grid gap-3">
            {reviews.map((r) => (
              <ReviewCard key={r.id} review={r} hideReasons={hideReasons} />
            ))}
          </ul>
        )}

        <Card>
          <CardHeader>
            <CardTitle>{__('Settings')}</CardTitle>
          </CardHeader>
          <CardContent>
            <form
              className="grid gap-4"
              onSubmit={(e) => {
                e.preventDefault();
                form.put(`${tenantBase()}/reviews/settings`, { preserveScroll: true });
              }}
            >
              <label className="flex items-center gap-3">
                <Switch
                  checked={form.data.requests_on}
                  onCheckedChange={(v) => form.setData('requests_on', v)}
                  aria-label={__('Ask customers how it was')}
                />
                {__('Ask customers how it was (at most once every 90 days each)')}
              </label>
              <Field
                label={__('Your Google review link')}
                hint={__(
                  'From your Google Business Profile: “Ask for reviews”, then copy the link.',
                )}
                error={form.errors.google_url}
              >
                <Input
                  type="url"
                  inputMode="url"
                  placeholder="https://g.page/r/…/review"
                  value={form.data.google_url}
                  onChange={(e) => form.setData('google_url', e.target.value)}
                />
              </Field>
              <div>
                <Button type="submit" loading={form.processing}>
                  {__('Save')}
                </Button>
              </div>
            </form>
          </CardContent>
        </Card>
      </div>
    </AppShell>
  );
}
