import type { BlockDefinition } from '../types';
import { Section, SectionTitle } from './shared';

/** The cart and checkout (/cart) — internal: the editor's library never lists it. The island does the work. */
export const Checkout: BlockDefinition<Record<string, never>> = {
  type: 'Checkout',
  version: 1,
  label: 'Cart',
  description: 'Your cart and checkout.',
  fields: {},
  defaults: {},
  render: () => (
    <Section>
      <SectionTitle className="font-[family-name:var(--s-font-display)] text-3xl font-bold tracking-[-0.01em] sm:text-4xl">
        Your cart
      </SectionTitle>
      <div data-island="checkout" className="mt-8 grid max-w-2xl gap-6">
        <p>Your cart is empty. (Online ordering needs JavaScript switched on.)</p>
      </div>
    </Section>
  ),
};
