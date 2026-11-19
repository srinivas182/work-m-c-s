import { afterEach, describe, expect, it } from 'vitest';
import { money, setCurrency, t, zar } from './i18n';

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

describe('money in the business’s currency (25.1b)', () => {
  const kes = {
    code: 'KES',
    symbol: 'KSh',
    decimals: 2,
    decimal: '.',
    thousands: ',',
    after: false,
  };
  const xof = {
    code: 'XOF',
    symbol: 'CFA',
    decimals: 0,
    decimal: ',',
    thousands: ' ',
    after: true,
  };

  it('writes each country’s way, with hundredths stored for every currency', () => {
    expect(money(138000, 'en', kes)).toBe('KSh1,380.00');
    expect(money(500000, 'fr', xof)).toBe('5 000 CFA');
    expect(money(-500050, 'en', xof)).toBe('-5 001 CFA');
    expect(zar(138000, 'en')).toBe('R1 380.00');
  });

  it('follows the page’s currency once set, and goes back to rand without one', () => {
    setCurrency(kes);
    expect(money(2500, 'en')).toBe('KSh25.00');
    expect(money(2500, 'fr')).toBe('25,00 KSh');
    setCurrency(null);
    expect(money(2500, 'en')).toBe('R25.00');
  });
});
