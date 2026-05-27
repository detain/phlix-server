import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import { DataTable, type Column } from './DataTable';

interface Row {
  id: string;
  name: string;
  count: number | null;
}

const columns: Array<Column<Row>> = [
  { id: 'name', header: 'Name', key: 'name' },
  { id: 'count', header: 'Count', key: 'count' },
  { id: 'actions', header: 'Actions', render: (r) => <button type="button">edit {r.id}</button> },
];

describe('DataTable', () => {
  it('renders headers and rows', () => {
    const rows: Row[] = [
      { id: '1', name: 'Movies', count: 12 },
      { id: '2', name: 'Shows', count: 3 },
    ];
    render(<DataTable columns={columns} rows={rows} rowKey={(r) => r.id} />);

    expect(screen.getByRole('columnheader', { name: 'Name' })).toBeInTheDocument();
    expect(screen.getByText('Movies')).toBeInTheDocument();
    expect(screen.getByText('12')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'edit 1' })).toBeInTheDocument();
  });

  it('renders the empty message when there are no rows', () => {
    render(
      <DataTable columns={columns} rows={[]} rowKey={(r) => r.id} emptyMessage="Nothing here" />,
    );
    expect(screen.getByText('Nothing here')).toBeInTheDocument();
  });

  it('renders null/undefined field values as empty strings', () => {
    const rows: Row[] = [{ id: '1', name: 'NoCount', count: null }];
    render(<DataTable columns={columns} rows={rows} rowKey={(r) => r.id} />);
    const cells = screen.getAllByRole('cell');
    // name, count(empty), actions
    expect(cells[1]).toHaveTextContent('');
  });

  it('renders a caption when provided', () => {
    render(
      <DataTable columns={columns} rows={[]} rowKey={(r) => r.id} caption="Libraries" />,
    );
    expect(screen.getByText('Libraries')).toBeInTheDocument();
  });
});
