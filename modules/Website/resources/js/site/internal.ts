import { ProductDetail } from './blocks/ProductDetail';
import type { BlockDefinition } from './types';

/**
 * Sections the renderer knows but the editor's library never lists — they only make sense where the
 * site context provides their data (e.g. a product page).
 */
export const INTERNAL_BLOCKS: Record<string, BlockDefinition<Record<string, never>>> = {
  ProductDetail,
};
