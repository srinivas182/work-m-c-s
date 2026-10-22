import { PriceCards, type SalePackage } from '../components/PriceCards';
import { Site, type SiteProps } from '../components/Site';

export default function Pricing(props: SiteProps & { packages: SalePackage[] }) {
  return (
    <Site
      {...props}
      title="Pricing"
      description="WURK packages in rand: website, bookings, shop and WhatsApp reminders. Monthly or yearly, with a free trial."
    >
      <section className="mx-auto max-w-6xl px-5 pt-10 sm:px-8">
        <h1 className="font-display text-4xl font-extrabold tracking-[-0.03em] sm:text-5xl">
          Pricing
        </h1>
        <p className="mt-4 max-w-2xl text-lg text-[#1B1640]/75">
          Prices exclude VAT; the VAT-inclusive amount is shown under each. Upgrade any time and pay
          only the difference for the rest of your billing period. Each package includes WURK
          Credits for SMS and WhatsApp messages every month.
        </p>
        <div className="mt-12">
          <PriceCards
            headingLevel="h2"
            packages={props.packages}
            moduleNames={props.moduleNames}
            signupUrl={props.signupUrl}
          />
        </div>
      </section>
    </Site>
  );
}
