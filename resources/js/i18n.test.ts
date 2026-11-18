import { afterEach, describe, expect, it } from 'vitest';
import { money, t } from './i18n';

describe('i18n (24.1)', () => {
  afterEach(() => {
    delete window.__wurkI18n;
  });

  it('shows the translation, or the English text when there is none', () => {
    window.__wurkI18n = { 'Language saved.': 'Ulimi lugciniwe.', 'Hi :name': 'Sawubona :name' };
    expect(t('Language saved.')).toBe('Ulimi lugciniwe.');
    expect(t('Hi :name', { name: 'Thandi' })).toBe('Sawubona Thandi');
    expect(t('Not translated yet')).toBe('Not translated yet');
  });

  it('writes rand exactly as the server does', () => {
    expect(money(138000, 'en')).toBe('R1 380.00');
    expect(money(138000, 'zu')).toBe('R1 380.00');
    expect(money(138000, 'fr')).toBe('1 380,00 R');
    expect(money(-5000, 'en')).toBe('-R50.00');
    expect(money(123456789, 'pt')).toBe('1 234 567,89 R');
  });
});
