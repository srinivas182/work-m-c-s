import { Checkout } from './blocks/Checkout';
import { ProductDetail } from './blocks/ProductDetail';
import type { BlockDefinition } from './types';

/**
 * Sections the renderer knows but the editor's library never lists — they only make sense where the
 * site context provides their data (a product page; the cart).
 */
export const INTERNAL_BLOCKS: Record<string, BlockDefinition<Record<string, never>>> = {
  ProductDetail,
  Checkout,
};
