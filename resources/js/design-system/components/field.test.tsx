import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { Field } from './field';
import { Input } from './input';

describe('Field', () => {
  it('labels a single control and wires hint text', () => {
    render(
      <Field label="Phone" hint="We send a WhatsApp confirmation.">
        <Input />
      </Field>,
    );
    const input = screen.getByLabelText('Phone');
    expect(input.getAttribute('aria-describedby')).toBeTruthy();
    expect(screen.getByText('We send a WhatsApp confirmation.').id).toBe(
      input.getAttribute('aria-describedby'),
    );
  });

  it('marks the control invalid and shows the error instead of the hint', () => {
    render(
      <Field label="Name" hint="Full name" error="Required">
        <Input />
      </Field>,
    );
    expect(screen.getByLabelText('Name').getAttribute('aria-invalid')).toBe('true');
    expect(screen.queryByText('Full name')).toBeNull();
  });

  it('supports composite controls through a render function', () => {
    render(<Field label="Service">{(control) => <button type="button" {...control} />}</Field>);
    expect(screen.getByRole('button', { name: 'Service' })).toBeTruthy();
  });
});
