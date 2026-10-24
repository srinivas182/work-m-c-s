import { CallToAction } from './blocks/CallToAction';
import { Contact } from './blocks/Contact';
import { Faq } from './blocks/Faq';
import { FeatureGrid } from './blocks/FeatureGrid';
import { Footer } from './blocks/Footer';
import { Gallery } from './blocks/Gallery';
import { Header } from './blocks/Header';
import { Hero } from './blocks/Hero';
import { ImageText } from './blocks/ImageText';
import { OpeningHours } from './blocks/OpeningHours';
import { ServicesPrices } from './blocks/ServicesPrices';
import { Team } from './blocks/Team';
import { Testimonials } from './blocks/Testimonials';
import { Text } from './blocks/Text';
import { BeforeAfter } from './blocks/BeforeAfter';
import { Packages } from './blocks/Packages';
import { ClassTimetable } from './blocks/ClassTimetable';
import { FoodMenu } from './blocks/FoodMenu';
import { RoomsRates } from './blocks/RoomsRates';
import { Portfolio } from './blocks/Portfolio';
import { Accreditations } from './blocks/Accreditations';
import { ServiceAreas } from './blocks/ServiceAreas';
import { QuoteForm } from './blocks/QuoteForm';
import { PromotionBanner } from './blocks/PromotionBanner';
import type { BlockDefinition } from './types';

type AnyBlock = BlockDefinition<Record<string, unknown>>;
const as = (b: unknown) => b as AnyBlock;

/** Every block a page may contain. Types must match the server's format rule (^[A-Z][A-Za-z0-9]+$). */
export const BLOCKS: Record<string, AnyBlock> = Object.fromEntries(
  [
    Header,
    Hero,
    FeatureGrid,
    ServicesPrices,
    ImageText,
    Gallery,
    Testimonials,
    Team,
    OpeningHours,
    Contact,
    Faq,
    BeforeAfter,
    Packages,
    ClassTimetable,
    FoodMenu,
    RoomsRates,
    Portfolio,
    Accreditations,
    ServiceAreas,
    QuoteForm,
    PromotionBanner,
    Text,
    CallToAction,
    Footer,
  ].map((b) => [b.type, as(b)]),
);
