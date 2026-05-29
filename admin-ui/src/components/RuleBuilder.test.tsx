import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { RuleBuilder } from './RuleBuilder';
import type { RuleGroup } from '../api/smartPlaylists';

const defaultGroup: RuleGroup = {
  logic: 'and',
  rules: [
    { field: 'title', op: 'contains', value: 'Action' },
    { field: 'year', op: 'gte', value: 2020 },
  ],
};

function renderBuilder(
  value: RuleGroup = defaultGroup,
  onChange: (g: RuleGroup) => void = vi.fn(),
  disabled?: boolean,
) {
  return render(
    <RuleBuilder value={value} onChange={onChange} disabled={disabled} />,
  );
}

describe('RuleBuilder', () => {
  it('renders the rule builder with initial rules', () => {
    renderBuilder();

    expect(screen.getByTestId('rule-builder')).toBeInTheDocument();
    expect(screen.getByRole('combobox', { name: /logic operator/i })).toHaveValue('and');
    expect(screen.getAllByRole('combobox', { name: /rule field/i })).toHaveLength(2);
    expect(screen.getAllByRole('combobox', { name: /rule operator/i })).toHaveLength(2);
  });

  it('renders rule field options correctly', () => {
    renderBuilder();

    const fieldSelects = screen.getAllByRole('combobox', { name: /rule field/i });
    expect(fieldSelects.length).toBeGreaterThan(0);

    const options = ['title', 'year', 'genre', 'rating', 'runtime', 'added_at', 'play_count', 'media_type'];
    options.forEach((opt) => {
      expect(screen.getAllByRole('option', { name: opt.replace('_', ' ') }).length).toBeGreaterThan(0);
    });
  });

  it('changes logic operator', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    renderBuilder(defaultGroup, onChange);

    const logicSelect = screen.getByRole('combobox', { name: /logic operator/i });
    await user.selectOptions(logicSelect, 'or');

    expect(onChange).toHaveBeenCalledWith(
      expect.objectContaining({ logic: 'or' }),
    );
  });

  it('adds a new rule when clicking "Add rule"', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    renderBuilder(defaultGroup, onChange);

    expect(screen.getAllByRole('combobox', { name: /rule field/i })).toHaveLength(2);

    await user.click(screen.getByRole('button', { name: '+ Add rule' }));

    expect(onChange).toHaveBeenCalledWith(
      expect.objectContaining({
        rules: expect.arrayContaining([
          expect.any(Object),
          expect.any(Object),
          expect.objectContaining({ field: 'title', op: 'contains', value: '' }),
        ]),
      }),
    );
  });

  it('deletes a rule when clicking delete button', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    renderBuilder(defaultGroup, onChange);

    const deleteButtons = screen.getAllByRole('button', { name: 'Delete rule' });
    await user.click(deleteButtons[0]!);

    expect(onChange).toHaveBeenCalledWith(
      expect.objectContaining({
        rules: expect.arrayContaining([
          { field: 'year', op: 'gte', value: 2020 },
        ]),
      }),
    );
  });

  it('disables all controls when disabled prop is true', () => {
    renderBuilder(defaultGroup, vi.fn(), true);

    expect(screen.getByRole('combobox', { name: /logic operator/i })).toBeDisabled();
    screen.getAllByRole('combobox', { name: /rule field/i }).forEach((select) => {
      expect(select).toBeDisabled();
    });
    screen.getAllByRole('combobox', { name: /rule operator/i }).forEach((select) => {
      expect(select).toBeDisabled();
    });
    screen.getAllByRole('textbox', { name: /rule value/i }).forEach((input) => {
      expect(input).toBeDisabled();
    });
    expect(screen.getByRole('button', { name: '+ Add rule' })).toBeDisabled();
  });

  it('renders string operators for string fields', () => {
    renderBuilder();

    const fieldSelects = screen.getAllByRole('combobox', { name: /rule field/i });
    // First rule has field='title' which is a string field
    expect(fieldSelects[0]).toHaveValue('title');

    const opSelects = screen.getAllByRole('combobox', { name: /rule operator/i });
    const stringOps = ['contains', 'equals', 'starts_with', 'ends_with'];
    stringOps.forEach((op) => {
      expect(opSelects[0]!.querySelector(`option[value="${op}"]`)).toBeInTheDocument();
    });
  });

  it('renders numeric operators for numeric fields', () => {
    renderBuilder();

    const fieldSelects = screen.getAllByRole('combobox', { name: /rule field/i });
    // Second rule has field='year' which is a numeric field
    expect(fieldSelects[1]).toHaveValue('year');

    const opSelects = screen.getAllByRole('combobox', { name: /rule operator/i });
    const numericOps = ['eq', 'ne', 'gt', 'gte', 'lt', 'lte'];
    numericOps.forEach((op) => {
      expect(opSelects[1]!.querySelector(`option[value="${op}"]`)).toBeInTheDocument();
    });
  });

  it('renders empty state with no rules', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    renderBuilder({ logic: 'and', rules: [] }, onChange);

    expect(screen.getByRole('combobox', { name: /logic operator/i })).toHaveValue('and');
    await user.click(screen.getByRole('button', { name: '+ Add rule' }));

    expect(onChange).toHaveBeenCalledWith(
      expect.objectContaining({
        rules: expect.arrayContaining([
          expect.objectContaining({ field: 'title', op: 'contains', value: '' }),
        ]),
      }),
    );
  });

  it('changes field and updates available operators', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    // Start with a string field (title)
    const group: RuleGroup = { logic: 'and', rules: [{ field: 'title', op: 'contains', value: 'Test' }] };
    renderBuilder(group, onChange);

    const fieldSelect = screen.getByRole('combobox', { name: /rule field/i });
    const opSelect = screen.getByRole('combobox', { name: /rule operator/i });

    // Initially string operators
    expect(opSelect).toHaveValue('contains');

    // Change to numeric field (year)
    await user.selectOptions(fieldSelect, 'year');

    expect(onChange).toHaveBeenLastCalledWith(
      expect.objectContaining({
        rules: expect.arrayContaining([
          expect.objectContaining({ field: 'year', op: 'eq' }),
        ]),
      }),
    );
  });
});
