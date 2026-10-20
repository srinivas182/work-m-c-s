import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import { renderTokensCss } from './render';

describe('tokens.css', () => {
  it('is in sync with the palette engine (run `pnpm tokens` if this fails)', () => {
    const committed = readFileSync(resolve(__dirname, '../../../css/tokens.css'), 'utf8');
    expect(committed).toBe(renderTokensCss());
  });
});
