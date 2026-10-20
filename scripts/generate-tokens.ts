import { writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { renderTokensCss } from '../resources/js/design-system/tokens/render';

const target = resolve(import.meta.dirname, '../resources/css/tokens.css');
writeFileSync(target, renderTokensCss());
console.log(`Wrote ${target}`);
