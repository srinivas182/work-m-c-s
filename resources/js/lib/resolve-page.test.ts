import { describe, expect, it } from 'vitest';
import { pagePath, resolvePage } from './resolve-page';

describe('pagePath', () => {
  it('maps module pages to the module directory', () => {
    expect(pagePath('Health::Status')).toBe('../../modules/Health/resources/js/pages/Status.tsx');
  });

  it('supports nested module pages', () => {
    expect(pagePath('Bookings::Admin/List')).toBe(
      '../../modules/Bookings/resources/js/pages/Admin/List.tsx',
    );
  });

  it('maps platform pages to resources/js/pages', () => {
    expect(pagePath('Dashboard')).toBe('./pages/Dashboard.tsx');
  });

  it('rejects malformed names', () => {
    expect(() => pagePath('bad-module::Page')).toThrow();
    expect(() => pagePath('Health::')).toThrow();
  });
});

describe('resolvePage', () => {
  it('loads a registered page', async () => {
    const pages = { '../../modules/Health/resources/js/pages/Status.tsx': async () => 'component' };
    await expect(resolvePage('Health::Status', pages)).resolves.toBe('component');
  });

  it('throws a helpful error for a missing page', () => {
    expect(() => resolvePage('Health::Missing', {})).toThrow(/Health::Missing/);
  });
});
