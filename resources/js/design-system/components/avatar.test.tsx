import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { Avatar, initials } from './avatar';

describe('Avatar', () => {
  it('derives initials from first and last name', () => {
    expect(initials('Thandi Mokoena')).toBe('TM');
    expect(initials('  sipho  ')).toBe('S');
    expect(initials('Lerato Naledi Khumalo')).toBe('LK');
    expect(initials('')).toBe('?');
  });

  it('exposes the full name, not the initials, to assistive technology', () => {
    render(<Avatar name="Thandi Mokoena" />);
    expect(screen.getByRole('img', { name: 'Thandi Mokoena' })).toBeTruthy();
    expect(screen.queryByText('TM')).toBeNull();
  });
});
