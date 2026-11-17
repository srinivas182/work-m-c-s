import { Site, type SiteProps } from '../components/Site';

const SECTIONS: { title: string; points: string[] }[] = [
  {
    title: 'What we aim for',
    points: [
      'WURK aims to meet the Web Content Accessibility Guidelines (WCAG) 2.2 at level AA — the international standard — in the back office, in WURK’s admin, in signing in and signing up, and on the pages your customers use: your website, booking, the shop and checkout.',
      'We build it in from the start: plain words, large enough buttons, clear focus, and colours chosen so text is easy to read.',
    ],
  },
  {
    title: 'What works',
    points: [
      'Everything can be done with a keyboard alone, and focus never hides behind a header or menu bar.',
      'Screen readers (NVDA, JAWS, VoiceOver, TalkBack) announce headings, buttons, form labels, errors and changes such as “Saved”.',
      'Pages work zoomed in and on small phone screens, in light and dark mode, and respect “reduce motion”.',
      'Signing in never needs you to remember or retype a code: you can paste it, and password managers work.',
      'Nothing needs dragging. In the page editor, “Add section”, “Move up” and “Move down” do what dragging does.',
      'Every website design meets the contrast standard in its own colours — and in any colour you choose: WURK adjusts shades so text stays readable.',
    ],
  },
  {
    title: 'How we check',
    points: [
      'Every change to WURK is tested automatically before it goes live: every screen, in light and dark mode, on a computer and on a phone; the main tasks (signing in, booking, buying, editing a page, Ask WURK) with the keyboard alone; and all 24 website designs in many colours.',
      'Before each release, people also go through the main tasks with a screen reader on a computer and on a phone.',
    ],
  },
  {
    title: 'What we know isn’t perfect yet',
    points: [
      'The page editor’s preview of your page is built with another company’s editing tool. Screen readers can find and edit each section from the panel beside it, but the preview itself gives them little to work with.',
      'Invoices and statements downloaded as PDF files aren’t yet fully tagged for screen readers. The same details are on the screen in WURK.',
      'What a business adds itself — photo descriptions, video captions, the words it writes — is up to the business. WURK asks for a description with every photo, but can’t check what’s written.',
      'Paying online happens on the payment provider’s own page, which we don’t control.',
    ],
  },
];

/** wurk.co.za/accessibility (Sprint 18.5b): WURK's accessibility statement — in plain words. */
export default function Accessibility(props: SiteProps & { contact: string }) {
  return (
    <Site
      {...props}
      title="Accessibility"
      description="How WURK works for everyone — keyboard, screen readers, zoom and more — and how to tell us if something doesn’t."
    >
      <section className="mx-auto max-w-3xl px-5 pt-10 sm:px-8">
        <h1 className="font-display text-4xl font-extrabold tracking-[-0.03em]">
          WURK for everyone
        </h1>
        <p className="mt-4 text-[17px] leading-relaxed text-[#1B1640]/75">
          Your team and your customers should all be able to use WURK, however they use a computer
          or phone. Here’s what we do, and what we know still needs work.
        </p>
        {SECTIONS.map((s) => (
          <div key={s.title} className="mt-10">
            <h2 className="font-display text-2xl font-bold">{s.title}</h2>
            <ul className="mt-4 grid list-disc gap-2 pl-5 text-[17px] leading-relaxed text-[#1B1640]/80">
              {s.points.map((p) => (
                <li key={p}>{p}</li>
              ))}
            </ul>
          </div>
        ))}
        <h2 className="font-display mt-10 text-2xl font-bold">Something not working for you?</h2>
        <p className="mt-4 text-[17px] leading-relaxed text-[#1B1640]/80">
          Please tell us — what you were trying to do, and what you use (for example a screen
          reader, or a phone’s zoom). Email{' '}
          <a className="underline" href={`mailto:${props.contact}`}>
            {props.contact}
          </a>{' '}
          or use the contact form. We’ll help you get it done another way in the meantime, and fix
          it.
        </p>
        <p className="mt-6 text-sm text-[#1B1640]/70">
          This statement was written in November 2026 and is reviewed with every major release.
        </p>
      </section>
    </Site>
  );
}
