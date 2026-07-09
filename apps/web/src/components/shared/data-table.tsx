"use client";

import { flexRender, getCoreRowModel, useReactTable, type ColumnDef } from "@tanstack/react-table";
import type { PaginationMeta } from "@scholaris/shared";
import { Button } from "@/components/ui/button";
import { LoadingSpinner } from "./loading-spinner";

interface DataTableProps<TData> {
  columns: ColumnDef<TData, any>[];
  data: TData[];
  meta?: PaginationMeta;
  isLoading?: boolean;
  onPageChange?: (page: number) => void;
  emptyLabel?: string;
}

/** Table paginée générique — chaque module (élèves, notes, ...) lui passe ses colonnes. */
export function DataTable<TData>({
  columns,
  data,
  meta,
  isLoading,
  onPageChange,
  emptyLabel = "Aucune donnée",
}: DataTableProps<TData>) {
  const table = useReactTable({ data, columns, getCoreRowModel: getCoreRowModel() });

  return (
    <div className="flex flex-col gap-3">
      <div className="overflow-x-auto rounded-md border border-border">
        <table className="w-full text-sm">
          <thead className="bg-secondary/60 text-left">
            {table.getHeaderGroups().map((headerGroup) => (
              <tr key={headerGroup.id}>
                {headerGroup.headers.map((header) => (
                  <th key={header.id} className="px-4 py-2 font-medium text-secondary-foreground">
                    {header.isPlaceholder ? null : flexRender(header.column.columnDef.header, header.getContext())}
                  </th>
                ))}
              </tr>
            ))}
          </thead>
          <tbody>
            {isLoading ? (
              <tr>
                <td colSpan={columns.length} className="px-4 py-6">
                  <LoadingSpinner label="Chargement…" />
                </td>
              </tr>
            ) : table.getRowModel().rows.length === 0 ? (
              <tr>
                <td colSpan={columns.length} className="px-4 py-6 text-center text-muted-foreground">
                  {emptyLabel}
                </td>
              </tr>
            ) : (
              table.getRowModel().rows.map((row) => (
                <tr key={row.id} className="border-t border-border">
                  {row.getVisibleCells().map((cell) => (
                    <td key={cell.id} className="px-4 py-2">
                      {flexRender(cell.column.columnDef.cell, cell.getContext())}
                    </td>
                  ))}
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>

      {meta ? (
        <div className="flex items-center justify-between text-sm text-muted-foreground">
          <span>
            Page {meta.page} / {meta.totalPages} — {meta.total} résultat(s)
          </span>
          <div className="flex gap-2">
            <Button
              variant="outline"
              size="sm"
              disabled={meta.page <= 1}
              onClick={() => onPageChange?.(meta.page - 1)}
            >
              Précédent
            </Button>
            <Button
              variant="outline"
              size="sm"
              disabled={meta.page >= meta.totalPages}
              onClick={() => onPageChange?.(meta.page + 1)}
            >
              Suivant
            </Button>
          </div>
        </div>
      ) : null}
    </div>
  );
}
