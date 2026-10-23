import { Hero } from './blocks/Hero';
import { ServicesPrices } from './blocks/ServicesPrices';
import { Testimonials } from './blocks/Testimonials';
import type { BlockDefinition } from './types';

/** Every block a page may contain. Types must match the server's format rule (^[A-Z][A-Za-z0-9]+$). */
export const BLOCKS: Record<string, BlockDefinition<Record<string, unknown>>> = {
  Hero: Hero as unknown as BlockDefinition<Record<string, unknown>>,
  ServicesPrices: ServicesPrices as unknown as BlockDefinition<Record<string, unknown>>,
  Testimonials: Testimonials as unknown as BlockDefinition<Record<string, unknown>>,
};
