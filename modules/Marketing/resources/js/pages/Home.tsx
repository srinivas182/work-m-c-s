// Direct imports keep the public site's JavaScript small (no full design-system bundle).
import { Button } from '@/design-system/components/button';
import { PriceCards, type SalePackage } from '../components/PriceCards';
import { Site, type SiteProps } from '../components/Site';

const STORIES = [
  {
    who: 'A salon',
    example: 'Glow Salon, Soweto',
    title: 'Bookings come in while you sleep',
    body: 'Clients pick a stylist and a time on your site at any hour, pay a deposit if you ask for one, and get a WhatsApp reminder the day before. Saturday fills up without a single phone call.',
  },
  {
    who: 'A dental practice',
    example: 'Bright Smiles, Durban',
    title: 'Fewer empty chairs',
    body: 'Patients confirm or move appointments from a WhatsApp message instead of a missed call. Each branch keeps its own diary, and you see all of them in one place.',
  },
  {
    who: 'A gym',
    example: 'Kasi Fitness, Tembisa',
    title: 'Membership fees that arrive on time',
    body: 'Members sign up online and pay monthly by card or debit order. Your shop sells supplements and day passes, and the reports show who is behind on payments.',
  },
];

/** The product in use: a salon's booking page and the WhatsApp confirmation that follows. */
function PhoneDemo() {
  return (
    <div aria-hidden className="relative mx-auto w-full max-w-[19rem] pb-20 select-none">
      <div className="rounded-[2.4rem] bg-[#1B1640] p-2.5 shadow-[0_30px_60px_-20px_rgba(27,22,64,0.45)]">
        <div className="overflow-hidden rounded-[1.9rem] bg-white">
          <div className="bg-[#2A1E6B] px-5 pt-7 pb-5 text-white">
            <p className="text-xs text-white/70">glow-salon.wurk.co.za</p>
            <p className="font-display mt-1 text-xl font-bold">Glow Salon</p>
            <p className="text-sm text-white/80">Vilakazi Street, Soweto</p>
          </div>
          <div className="grid gap-3 px-5 py-5 text-[13px]">
            <p className="font-semibold">Knotless braids · 4 hrs · R650</p>
            <p className="text-[#1B1640]/65">With Lerato · Saturday</p>
            <div className="grid grid-cols-3 gap-2">
              {['08:00', '09:30', '13:00'].map((t) => (
                <span
                  key={t}
                  className={`rounded-lg py-2 text-center font-medium ${t === '09:30' ? 'bg-primary text-primary-fg' : 'bg-[#F1EFFA] text-[#1B1640]/80'}`}
                >
                  {t}
                </span>
              ))}
            </div>
            <span className="mt-1 rounded-xl bg-[#1B1640] py-3 text-center font-semibold text-white">
              Book and pay R100 deposit
            </span>
          </div>
        </div>
      </div>
      {/* The one moment of motion: the confirmation arriving. */}
      <div className="absolute -right-2 bottom-0 w-[15.5rem] rounded-2xl rounded-bl-md bg-[#DCF8C6] px-4 py-3 text-[13px] leading-snug text-[#123B12] shadow-[0_12px_28px_-10px_rgba(27,22,64,0.35)] motion-safe:animate-[arrive_600ms_cubic-bezier(.2,.8,.2,1)_900ms_both] sm:-right-14">
        <p className="font-semibold">Glow Salon</p>
        <p>You’re booked with Lerato on Saturday at 09:30. Reply 1 to change your time.</p>
        <p className="mt-1 text-right text-[11px] text-[#123B12]/70">09:31 ✓✓</p>
      </div>
    </div>
  );
}

export default function Home(props: SiteProps & { packages: SalePackage[] }) {
  return (
    <Site
      {...props}
      description="Websites, online bookings, a shop and WhatsApp reminders for South African businesses, priced in rand. Start a free trial today."
    >
      <style>
        {
          '@keyframes arrive { from { opacity: 0; transform: translateY(16px) scale(.96) } to { opacity: 1; transform: none } }'
        }
      </style>
      <section className="mx-auto grid max-w-6xl items-center gap-16 px-5 pt-10 pb-24 sm:px-8 lg:grid-cols-[1.15fr_1fr] lg:pt-16">
        <div>
          <h1 className="font-display text-[2.6rem] leading-[1.04] font-extrabold tracking-[-0.03em] sm:text-6xl">
            Your business online and taking bookings by tonight.
          </h1>
          <p className="mt-6 max-w-[34rem] text-lg leading-relaxed text-[#1B1640]/75">
            WURK gives salons, practices, gyms and shops a website, bookings, an online shop and
            WhatsApp reminders in one place. Priced in rand, hosted in South Africa.
          </p>
          <div className="mt-9 flex flex-wrap gap-3">
            <Button asChild size="lg">
              <a href={props.signupUrl}>Start your free trial</a>
            </Button>
            <Button asChild size="lg" variant="secondary">
              <a href="/pricing">See prices</a>
            </Button>
          </div>
          <p className="mt-4 text-sm text-[#1B1640]/65">No card needed for the trial.</p>
        </div>
        <PhoneDemo />
      </section>

      <section aria-labelledby="stories" className="bg-white py-24">
        <div className="mx-auto max-w-6xl px-5 sm:px-8">
          <h2
            id="stories"
            className="font-display max-w-2xl text-3xl font-bold tracking-tight sm:text-4xl"
          >
            What changes when you’re on WURK
          </h2>
          <div className="mt-14 grid gap-14">
            {STORIES.map((s) => (
              <article
                key={s.who}
                className="grid gap-3 border-t border-[#1B1640]/10 pt-8 md:grid-cols-[14rem_1fr] md:gap-12"
              >
                <p className="text-[15px] text-[#1B1640]/65">
                  <span className="block font-semibold text-[#1B1640]">{s.who}</span>
                  for example {s.example}
                </p>
                <div>
                  <h3 className="font-display text-2xl font-bold tracking-tight">{s.title}</h3>
                  <p className="mt-3 max-w-[38rem] text-[17px] leading-relaxed text-[#1B1640]/75">
                    {s.body}
                  </p>
                </div>
              </article>
            ))}
          </div>
        </div>
      </section>

      <section aria-labelledby="prices" className="mx-auto max-w-6xl px-5 pt-24 sm:px-8">
        <h2 id="prices" className="font-display text-3xl font-bold tracking-tight sm:text-4xl">
          Simple prices in rand
        </h2>
        <p className="mt-3 max-w-xl text-[17px] text-[#1B1640]/75">
          One monthly fee for the package, and WURK Credits for SMS and WhatsApp messages. Pay by
          card, debit order or cash at our office.
        </p>
        <div className="mt-10">
          <PriceCards
            packages={props.packages}
            moduleNames={props.moduleNames}
            signupUrl={props.signupUrl}
          />
        </div>
      </section>
    </Site>
  );
}
