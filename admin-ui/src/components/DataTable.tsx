/**
 * DataTable — a small, typed, generic table component.
 *
 * Given a column spec and a row array, renders an accessible `<table>`.
 * Each column declares a `header` and either a `key` (read a field) or a
 * `render` function (compute a cell). Cell values are rendered as React
 * children — strings become text nodes, so untrusted data cannot inject
 * markup.
 */
import { type ReactNode } from 'react';

export interface Column<Row> {
  /** Stable column id (used as React key). */
  id: string;
  /** Header label. */
  header: string;
  /** Field accessor; ignored when `render` is provided. */
  key?: keyof Row;
  /** Custom cell renderer. */
  render?: (row: Row) => ReactNode;
}

export interface DataTableProps<Row> {
  columns: Array<Column<Row>>;
  rows: Row[];
  /** Stable per-row key extractor. */
  rowKey: (row: Row, index: number) => string | number;
  /** Message shown when `rows` is empty. */
  emptyMessage?: string;
  caption?: string;
}

export function DataTable<Row>({
  columns,
  rows,
  rowKey,
  emptyMessage = 'No data',
  caption,
}: DataTableProps<Row>): JSX.Element {
  return (
    <table className="data-table">
      {caption ? <caption>{caption}</caption> : null}
      <thead>
        <tr>
          {columns.map((col) => (
            <th key={col.id} scope="col">
              {col.header}
            </th>
          ))}
        </tr>
      </thead>
      <tbody>
        {rows.length === 0 ? (
          <tr>
            <td colSpan={columns.length} className="data-table__empty">
              {emptyMessage}
            </td>
          </tr>
        ) : (
          rows.map((row, index) => (
            <tr key={rowKey(row, index)}>
              {columns.map((col) => (
                <td key={col.id}>{renderCell(col, row)}</td>
              ))}
            </tr>
          ))
        )}
      </tbody>
    </table>
  );
}

function renderCell<Row>(col: Column<Row>, row: Row): ReactNode {
  if (col.render) {
    return col.render(row);
  }
  if (col.key !== undefined) {
    const value = row[col.key];
    return value === null || value === undefined ? '' : String(value);
  }
  return '';
}
