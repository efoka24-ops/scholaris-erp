"use client";

import { useEffect, useState } from "react";
import type { ColumnDef } from "@tanstack/react-table";
import type { PaginationMeta } from "@scholaris/shared";
import { resourceClient } from "@/lib/api-client";
import { DataTable } from "@/components/shared/data-table";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import type { AuditLog } from "@/types/settings";

const columns: ColumnDef<AuditLog>[] = [
  {
    accessorKey: "timestamp",
    header: "Date",
    cell: ({ row }) => new Date(row.original.timestamp).toLocaleString("fr-FR"),
  },
  {
    id: "user",
    header: "Utilisateur",
    cell: ({ row }) => {
      const user = row.original.user;
      return user ? `${user.firstName} ${user.lastName} (${user.email})` : "Système";
    },
  },
  { accessorKey: "action", header: "Action" },
  { accessorKey: "resource", header: "Ressource" },
  { accessorKey: "resourceId", header: "ID ressource", cell: ({ row }) => row.original.resourceId ?? "—" },
  { accessorKey: "ipAddress", header: "IP", cell: ({ row }) => row.original.ipAddress ?? "—" },
];

interface Filters {
  userId: string;
  action: string;
  resource: string;
  dateFrom: string;
  dateTo: string;
}

const EMPTY_FILTERS: Filters = { userId: "", action: "", resource: "", dateFrom: "", dateTo: "" };

export default function AuditLogsPage() {
  const [logs, setLogs] = useState<AuditLog[]>([]);
  const [meta, setMeta] = useState<PaginationMeta | undefined>(undefined);
  const [page, setPage] = useState(1);
  const [isLoading, setIsLoading] = useState(true);
  const [filters, setFilters] = useState<Filters>(EMPTY_FILTERS);
  const [appliedFilters, setAppliedFilters] = useState<Filters>(EMPTY_FILTERS);

  useEffect(() => {
    let cancelled = false;
    setIsLoading(true);
    const params: Record<string, string | number> = { page, limit: 20 };
    for (const [key, value] of Object.entries(appliedFilters)) {
      if (value) params[key] = value;
    }
    resourceClient
      .get("/audit-logs", { params })
      .then(({ data }) => {
        if (cancelled) return;
        setLogs(data?.data ?? []);
        setMeta(data?.meta);
      })
      .finally(() => {
        if (!cancelled) setIsLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [page, appliedFilters]);

  function applyFilters() {
    setPage(1);
    setAppliedFilters(filters);
  }

  function resetFilters() {
    setFilters(EMPTY_FILTERS);
    setAppliedFilters(EMPTY_FILTERS);
    setPage(1);
  }

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-2xl font-semibold">Journal d'audit</h1>
        <p className="text-sm text-muted-foreground">
          Créations, modifications et suppressions sensibles — utilisateur, IP, anciennes/nouvelles valeurs.
        </p>
      </div>

      <div className="flex flex-wrap items-end gap-3 rounded-md border border-border p-4">
        <div className="flex flex-col gap-1">
          <label className="text-xs text-muted-foreground" htmlFor="filter-user">
            Utilisateur (ID)
          </label>
          <Input
            id="filter-user"
            className="w-48"
            value={filters.userId}
            onChange={(e) => setFilters((f) => ({ ...f, userId: e.target.value }))}
          />
        </div>
        <div className="flex flex-col gap-1">
          <label className="text-xs text-muted-foreground" htmlFor="filter-action">
            Action
          </label>
          <Input
            id="filter-action"
            className="w-36"
            placeholder="create, update…"
            value={filters.action}
            onChange={(e) => setFilters((f) => ({ ...f, action: e.target.value }))}
          />
        </div>
        <div className="flex flex-col gap-1">
          <label className="text-xs text-muted-foreground" htmlFor="filter-resource">
            Ressource
          </label>
          <Input
            id="filter-resource"
            className="w-44"
            placeholder="tenants, periods…"
            value={filters.resource}
            onChange={(e) => setFilters((f) => ({ ...f, resource: e.target.value }))}
          />
        </div>
        <div className="flex flex-col gap-1">
          <label className="text-xs text-muted-foreground" htmlFor="filter-from">
            Depuis
          </label>
          <Input
            id="filter-from"
            type="date"
            value={filters.dateFrom}
            onChange={(e) => setFilters((f) => ({ ...f, dateFrom: e.target.value }))}
          />
        </div>
        <div className="flex flex-col gap-1">
          <label className="text-xs text-muted-foreground" htmlFor="filter-to">
            Jusqu'à
          </label>
          <Input
            id="filter-to"
            type="date"
            value={filters.dateTo}
            onChange={(e) => setFilters((f) => ({ ...f, dateTo: e.target.value }))}
          />
        </div>
        <Button size="sm" onClick={applyFilters}>
          Filtrer
        </Button>
        <Button size="sm" variant="outline" onClick={resetFilters}>
          Réinitialiser
        </Button>
      </div>

      <DataTable
        columns={columns}
        data={logs}
        meta={meta}
        isLoading={isLoading}
        onPageChange={setPage}
        emptyLabel="Aucune entrée dans le journal d'audit"
      />
    </div>
  );
}
