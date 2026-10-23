import { describe, expect, it } from 'vitest';
import { photoSrcSet } from './shared';

describe('photoSrcSet', () => {
  it('offers the 640px file to phones for uploads wider than 640px', () => {
    expect(photoSrcSet('/storage/sites/t/01abc-1600.webp')).toBe(
      '/storage/sites/t/01abc-640.webp 640w, /storage/sites/t/01abc-1600.webp 1600w',
    );
    expect(photoSrcSet('/storage/sites/t/01abc-1200.webp')).toBe(
      '/storage/sites/t/01abc-640.webp 640w, /storage/sites/t/01abc-1200.webp 1200w',
    );
  });

  it('leaves small uploads and other images alone (no file that does not exist)', () => {
    expect(photoSrcSet('/storage/sites/t/01abc-480.webp')).toBeUndefined();
    expect(photoSrcSet('/images/board-standins/salon-hero.jpg')).toBeUndefined();
    expect(photoSrcSet('')).toBeUndefined();
  });
});
