import type { BlockDefinition } from '../types';
import { EmptyHint, Heading, Section } from './shared';

export interface TeamProps extends Record<string, unknown> {
  heading: string;
  intro: string;
  people: { name: string; role: string; bio: string; photo: string }[];
}

export const Team: BlockDefinition<TeamProps> = {
  type: 'Team',
  version: 1,
  label: 'Team',
  description: 'The people customers will meet.',
  fields: {
    heading: { kind: 'text', inline: true, label: 'Heading', max: 60 },
    intro: { kind: 'text', inline: true, label: 'Introduction', max: 200 },
    people: {
      kind: 'list',
      label: 'People',
      max: 12,
      item: {
        name: { kind: 'text', label: 'Name', max: 60 },
        role: { kind: 'text', inline: true, label: 'Role', max: 60 },
        bio: { kind: 'text', inline: true, label: 'Short bio', multiline: true, max: 240 },
        photo: { kind: 'image', label: 'Photo' },
      },
    },
  },
  defaults: { heading: 'Meet the team', intro: '', people: [] },
  render: (p, site) => (
    <Section>
      <Heading intro={p.intro}>{p.heading}</Heading>
      {p.people.length === 0 ? (
        <div className="mt-10">
          <EmptyHint editing={site.editing}>Add the people customers will meet.</EmptyHint>
        </div>
      ) : (
        <ul className="mt-12 grid gap-x-8 gap-y-12 sm:grid-cols-2 lg:grid-cols-4">
          {p.people.map((m, i) => (
            <li key={i}>
              {m.photo ? (
                <img
                  src={m.photo}
                  alt=""
                  loading="lazy"
                  className="aspect-[4/5] w-full rounded-[var(--s-radius)] object-cover"
                />
              ) : (
                <div
                  aria-hidden
                  className="grid aspect-[4/5] w-full place-items-center rounded-[var(--s-radius)] bg-[var(--s-brand-soft)] font-[family-name:var(--s-font-display)] text-5xl font-bold text-[var(--s-brand-text)]"
                >
                  {m.name.trim().charAt(0).toUpperCase()}
                </div>
              )}
              <h3 className="mt-5 text-lg font-semibold">{m.name}</h3>
              {m.role && <p className="text-[var(--s-brand-text)]">{m.role}</p>}
              {m.bio && (
                <p className="mt-2 text-[15px] leading-relaxed text-[var(--s-fg-muted)]">{m.bio}</p>
              )}
            </li>
          ))}
        </ul>
      )}
    </Section>
  ),
};
