import type { BlockDefinition } from '../types';
import { EmptyHint, Heading, Section } from './shared';

const DAYS = [
  'Monday',
  'Tuesday',
  'Wednesday',
  'Thursday',
  'Friday',
  'Saturday',
  'Sunday',
] as const;

export interface TimetableClass {
  day: (typeof DAYS)[number];
  start: string;
  end: string;
  name: string;
  instructor: string;
  level: string;
}

export interface ClassTimetableProps extends Record<string, unknown> {
  heading: string;
  intro: string;
  classes: TimetableClass[];
  note: string;
  bookLabel: string;
  bookHref: string;
}

function ClassCard({ c }: { c: TimetableClass }) {
  return (
    <li className="rounded-[var(--s-radius)] border border-[var(--s-border)] bg-[var(--s-bg)] p-3">
      <span className="block text-sm font-semibold text-[var(--s-brand-text)] tabular-nums">
        {c.start}
        {c.end ? `–${c.end}` : ''}
      </span>
      <span className="block font-semibold">{c.name}</span>
      {(c.instructor || c.level) && (
        <span className="block text-sm text-[var(--s-fg-muted)]">
          {c.instructor}
          {c.instructor && c.level ? ' · ' : ''}
          {c.level}
        </span>
      )}
    </li>
  );
}

export const ClassTimetable: BlockDefinition<ClassTimetableProps> = {
  type: 'ClassTimetable',
  version: 1,
  label: 'Class timetable',
  description: 'Your weekly classes. Bookable once Appointments is switched on.',
  fields: {
    heading: { kind: 'text', inline: true, label: 'Heading', max: 60 },
    intro: { kind: 'text', inline: true, label: 'Introduction', max: 200 },
    classes: {
      kind: 'list',
      label: 'Classes',
      max: 60,
      item: {
        day: { kind: 'select', label: 'Day', options: DAYS.map((d) => ({ value: d, label: d })) },
        start: { kind: 'text', label: 'Starts (e.g. 06:00)', max: 5 },
        end: { kind: 'text', label: 'Ends (e.g. 07:00)', max: 5 },
        name: { kind: 'text', inline: true, label: 'Class', max: 40 },
        instructor: { kind: 'text', inline: true, label: 'Instructor', max: 40 },
        level: {
          kind: 'select',
          label: 'Level',
          options: ['All levels', 'Beginner', 'Intermediate', 'Advanced'].map((l) => ({
            value: l,
            label: l,
          })),
        },
      },
    },
    note: { kind: 'text', inline: true, label: 'Note (e.g. public holiday times)', max: 160 },
    bookLabel: { kind: 'text', label: 'Booking button text', max: 30 }, // panel only: the button is hidden until it has a link
    bookHref: { kind: 'link', label: 'Booking button goes to (leave empty to hide it)' },
  },
  isEmpty: (p) => p.classes.length === 0,
  defaults: {
    heading: 'Class timetable',
    intro: '',
    classes: [],
    note: '',
    bookLabel: 'Book a place',
    bookHref: '',
  },
  render: (p, site) => {
    const byDay = DAYS.map((day) => ({
      day,
      classes: p.classes
        .filter((c) => c.day === day)
        .sort((a, b) => String(a.start).localeCompare(String(b.start))),
    }));
    return (
      <Section tone="surface">
        <Heading intro={p.intro}>{p.heading}</Heading>
        {p.classes.length === 0 ? (
          <div className="mt-8">
            <EmptyHint editing={site.editing}>Add your weekly classes.</EmptyHint>
          </div>
        ) : (
          <>
            {/* Desktop: the whole week at a glance. */}
            <div className="mt-10 hidden grid-cols-7 gap-3 lg:grid">
              {byDay.map(({ day, classes }) => (
                <div key={day}>
                  <h3 className="mb-3 text-sm font-semibold tracking-wide text-[var(--s-fg-muted)] uppercase">
                    {day.slice(0, 3)}
                  </h3>
                  <ul className="grid gap-2">
                    {classes.map((c, i) => (
                      <ClassCard key={i} c={c} />
                    ))}
                  </ul>
                </div>
              ))}
            </div>
            {/* Phones: only days with classes, one after another. */}
            <div className="mt-8 grid gap-6 lg:hidden">
              {byDay
                .filter((d) => d.classes.length > 0)
                .map(({ day, classes }) => (
                  <div key={day}>
                    <h3 className="mb-2 font-semibold">{day}</h3>
                    <ul className="grid gap-2">
                      {classes.map((c, i) => (
                        <ClassCard key={i} c={c} />
                      ))}
                    </ul>
                  </div>
                ))}
            </div>
          </>
        )}
        {p.note && <p className="mt-6 text-[var(--s-fg-muted)]">{p.note}</p>}
        {p.bookHref && p.classes.length > 0 && (
          <a
            href={p.bookHref}
            className="mt-6 inline-flex min-h-11 items-center rounded-[var(--s-radius)] bg-[var(--s-brand)] px-6 font-semibold text-[var(--s-on-brand)]"
          >
            {p.bookLabel || 'Book a place'}
          </a>
        )}
      </Section>
    );
  },
};
