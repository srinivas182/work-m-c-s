import { Link } from '@inertiajs/react';
import { Site, type SiteProps } from '../components/Site';

/** wurk.co.za/security (Sprint 18.1b): how to report a security problem, and what we promise in return. */
export default function Security(props: SiteProps & { contact: string }) {
  const mail = (
    <a className="underline" href={`mailto:${props.contact}`}>
      {props.contact}
    </a>
  );
  return (
    <Site
      {...props}
      title="Reporting a security problem"
      description="How to tell WURK about a security problem, and what we do when you do."
    >
      <section className="mx-auto grid max-w-3xl gap-8 px-5 pt-10 text-[17px] leading-relaxed text-[#1B1640]/80 sm:px-8">
        <div>
          <h1 className="font-display text-4xl font-extrabold tracking-[-0.03em] text-[#1B1640]">
            Reporting a security problem
          </h1>
          <p className="mt-4">
            If you think you’ve found a security problem in WURK, please tell us: email {mail}. We
            welcome reports from anyone, and we’ll work with you to fix it.
          </p>
        </div>
        <div>
          <h2 className="font-display text-2xl font-bold text-[#1B1640]">What to send</h2>
          <ul className="mt-3 grid list-disc gap-2 pl-5">
            <li>What you found and where (the page or address).</li>
            <li>Step-by-step how to see it, and what someone could do with it.</li>
            <li>Screenshots or a short recording if they help.</li>
          </ul>
        </div>
        <div>
          <h2 className="font-display text-2xl font-bold text-[#1B1640]">What we promise</h2>
          <ul className="mt-3 grid list-disc gap-2 pl-5">
            <li>We’ll reply within 3 working days to say we have it.</li>
            <li>
              We’ll tell you what we found within 10 working days, and keep you posted until it’s
              fixed.
            </li>
            <li>We fix serious problems first, and as fast as we safely can.</li>
            <li>With your permission, we’ll thank you by name once it’s fixed.</li>
            <li>
              If you act in good faith and follow this page, we won’t take legal action against you
              for your research.
            </li>
          </ul>
        </div>
        <div>
          <h2 className="font-display text-2xl font-bold text-[#1B1640]">Please</h2>
          <ul className="mt-3 grid list-disc gap-2 pl-5">
            <li>
              Test with your own WURK business (a free trial is fine). Never look at, change or keep
              anyone else’s data — stop as soon as you see something that isn’t yours, and tell us.
            </li>
            <li>Don’t try to slow down or stop WURK, send spam, or trick people (phishing).</li>
            <li>Give us a reasonable time to fix a problem before you tell anyone else.</li>
          </ul>
        </div>
        <div>
          <h2 className="font-display text-2xl font-bold text-[#1B1640]">Not covered</h2>
          <ul className="mt-3 grid list-disc gap-2 pl-5">
            <li>What a business itself publishes on its website.</li>
            <li>Other companies’ services that WURK connects to (payment providers, WhatsApp).</li>
            <li>Reports from automated scanners with no real way to cause harm.</li>
          </ul>
        </div>
        <p>
          How we look after businesses’ data: see{' '}
          <Link href="/trust" className="underline">
            trust and security
          </Link>
          . Our security contact is also published at{' '}
          <a className="underline" href="/.well-known/security.txt">
            /.well-known/security.txt
          </a>
          .
        </p>
      </section>
    </Site>
  );
}
