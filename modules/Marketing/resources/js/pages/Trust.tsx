import { Link } from '@inertiajs/react';
import { Site, type SiteProps } from '../components/Site';

const SECTIONS: { title: string; points: string[] }[] = [
  {
    title: 'Your data stays in South Africa',
    points: [
      'WURK runs in South Africa, and your business’s data is stored here.',
      'Each business’s data is kept apart from every other business’s, and we test that automatically every time we change WURK.',
      'We never sell your data or your customers’ data, and never use it to advertise to them.',
    ],
  },
  {
    title: 'You decide who sees what',
    points: [
      'Your team sees only what their role allows — a branch manager only their branch.',
      'You can require two-step sign-in (an authenticator app) for everyone on your team.',
      'WURK’s own staff can’t open your business. If you ask support for help, they ask your permission: you choose “view only” or full access, for at most two hours, and you can end it at any time. Bank details, payouts, passwords and your team are never available to them, and everything they do is recorded in your activity log.',
    ],
  },
  {
    title: 'Your customers’ privacy (POPIA)',
    points: [
      'Customers get marketing messages only if they agreed, and every message has a way to stop them.',
      'Download everything WURK holds about your business — customers, bookings, orders, pages and files — whenever you like.',
      'Delete something by mistake? Put it back from “Recently deleted” for 30 days. A customer who asks to be forgotten is erased.',
      'Health and other sensitive records are encrypted, and every time someone opens one it’s recorded.',
    ],
  },
  {
    title: 'Payments go straight to you',
    points: [
      'Online payments go through your own payment provider into your own account. WURK never sees or stores card numbers.',
    ],
  },
  {
    title: 'How we keep WURK safe',
    points: [
      'Everything is sent over HTTPS, and browsers are told never to use anything else.',
      'Passwords are checked against lists of common ones, and too many wrong tries locks sign-in for a while.',
      'Uploaded files are checked for viruses before anyone can open them.',
      'Every change to WURK is checked for security problems, known weaknesses in the software it uses, and leaked secrets before it goes live.',
      'WURK is monitored around the clock; problems reach our engineers straight away.',
      'Independent security experts test WURK every year.',
    ],
  },
];

/** wurk.co.za/trust (Sprint 18.1b): how WURK looks after a business's data — in plain words. */
export default function Trust(props: SiteProps & { contact: string }) {
  return (
    <Site
      {...props}
      title="Trust and security"
      description="How WURK keeps your business’s data safe, private and in South Africa."
    >
      <section className="mx-auto max-w-3xl px-5 pt-10 sm:px-8">
        <h1 className="font-display text-4xl font-extrabold tracking-[-0.03em]">
          How we look after your data
        </h1>
        <p className="mt-4 text-[17px] leading-relaxed text-[#1B1640]/75">
          Your business runs on WURK, so your data — and your customers’ — has to be safe. Here’s
          what we do, in plain words.
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
        <p className="mt-10 text-[17px] leading-relaxed text-[#1B1640]/80">
          Found a security problem? Please tell us — see{' '}
          <Link href="/security" className="underline">
            reporting a security problem
          </Link>
          , or email{' '}
          <a className="underline" href={`mailto:${props.contact}`}>
            {props.contact}
          </a>
          . Questions about privacy: see our{' '}
          <Link href="/legal/privacy" className="underline">
            privacy notice
          </Link>
          .
        </p>
      </section>
    </Site>
  );
}
