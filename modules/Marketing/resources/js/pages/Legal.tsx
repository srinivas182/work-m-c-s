import { Site, type SiteProps } from '../components/Site';

const TITLES = { terms: 'Terms of service', privacy: 'Privacy notice (POPIA)' } as const;

export default function Legal(props: SiteProps & { page: keyof typeof TITLES }) {
  const title = TITLES[props.page];
  return (
    <Site {...props} title={title} description={`WURK ${title.toLowerCase()}.`}>
      <section className="mx-auto max-w-3xl px-5 pt-10 sm:px-8">
        <h1 className="font-display text-4xl font-extrabold tracking-[-0.03em]">{title}</h1>
        <p className="mt-6 rounded-xl bg-white px-5 py-4 text-[17px] leading-relaxed text-[#1B1640]/75 ring-1 ring-[#1B1640]/10">
          WURK’s legal advisers are finalising this text. It will be published here before public
          launch.
        </p>
      </section>
    </Site>
  );
}
