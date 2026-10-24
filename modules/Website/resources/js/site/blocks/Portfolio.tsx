import type { BlockDefinition } from '../types';
import { EmptyHint, Heading, PhotoPrompt, photoSrcSet, Section } from './shared';

export interface PortfolioProject {
  title: string;
  photo: string;
  photoAlt: string;
  category: string;
  story: string;
  outcome: string;
}

export interface PortfolioProps extends Record<string, unknown> {
  heading: string;
  intro: string;
  projects: PortfolioProject[];
}

export const Portfolio: BlockDefinition<PortfolioProps> = {
  type: 'Portfolio',
  version: 1,
  label: 'Portfolio',
  description: 'Projects you’re proud of: the work, the story and the result.',
  fields: {
    heading: { kind: 'text', inline: true, label: 'Heading', max: 60 },
    intro: { kind: 'text', inline: true, label: 'Introduction', max: 200 },
    projects: {
      kind: 'list',
      label: 'Projects',
      max: 12,
      item: {
        title: { kind: 'text', inline: true, label: 'Project', max: 60 },
        photo: { kind: 'image', label: 'Photo' },
        photoAlt: { kind: 'text', label: 'Describe the photo', max: 140 },
        category: { kind: 'text', inline: true, label: 'Type of work', max: 40 },
        story: { kind: 'text', inline: true, label: 'The story', multiline: true, max: 400 },
        outcome: { kind: 'text', inline: true, label: 'The result', max: 140 },
      },
    },
  },
  isEmpty: (p) => p.projects.length === 0,
  defaults: { heading: 'Our work', intro: '', projects: [] },
  render: (p, site) => (
    <Section>
      <Heading intro={p.intro}>{p.heading}</Heading>
      {p.projects.length === 0 ? (
        <div className="mt-8">
          <EmptyHint editing={site.editing}>Add a project you’re proud of.</EmptyHint>
        </div>
      ) : (
        <div className="mt-10 grid gap-10 md:grid-cols-2">
          {p.projects.map((project, i) => (
            <article key={i} className="grid gap-4">
              {project.photo ? (
                <img
                  src={project.photo}
                  srcSet={photoSrcSet(project.photo)}
                  sizes="(min-width: 768px) 50vw, 100vw"
                  alt={project.photoAlt}
                  loading="lazy"
                  decoding="async"
                  className="aspect-[4/3] w-full rounded-[var(--s-radius)] object-cover"
                />
              ) : (
                <PhotoPrompt editing={site.editing} className="aspect-[4/3] w-full" />
              )}
              <div>
                {project.category && (
                  <p className="text-sm font-semibold text-[var(--s-brand-text)]">
                    {project.category}
                  </p>
                )}
                <h3 className="mt-1 font-[family-name:var(--s-font-display)] text-2xl font-bold">
                  {project.title}
                </h3>
                {project.story && (
                  <p className="mt-3 leading-relaxed text-[var(--s-fg-muted)]">{project.story}</p>
                )}
                {project.outcome && (
                  <p className="mt-3 border-l-2 border-[var(--s-brand)] pl-3 font-medium">
                    {project.outcome}
                  </p>
                )}
              </div>
            </article>
          ))}
        </div>
      )}
    </Section>
  ),
};
