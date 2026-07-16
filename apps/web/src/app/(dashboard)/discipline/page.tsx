"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import type { ColumnDef } from "@tanstack/react-table";
import { Plus, ShieldAlert } from "lucide-react";
import { resourceClient } from "@/lib/api-client";
import {
  SEVERITY_LABELS,
  type Incident,
  type IncidentSeverity,
} from "@/types/discipline";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { DataTable } from "@/components/shared/data-table";

interface IncidentsPage {
  items: Incident[];
  total: number;
  page: number;
  limit: number;
  totalPages: number;
}

const SEVERITIES = Object.keys(SEVERITY_LABELS) as IncidentSeverity[];

export default function DisciplinePage() {
  const [incidents, setIncidents] = useState<Incident[]>([]);
  const [severityFilter, setSeverityFilter] = useState<IncidentSeverity | "">("");
  const [page, setPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    setIsLoading(true);
    setError(null);
    resourceClient
      .get<IncidentsPage>("/discipline/incidents", {
        params: { page, limit: 20, ...(severityFilter ? { severity: severityFilter } : {}) },
      })
      .then((response) => {
        setIncidents(response.data.items);
        setTotalPages(response.data.totalPages);
      })
      .catch((requestError: any) =>
        setError(requestError.response?.data?.message ?? "Impossible de charger les incidents."),
      )
      .finally(() => setIsLoading(false));
  }, [page, severityFilter]);

  const columns: ColumnDef<Incident>[] = [
    {
      id: "date",
      header: "Date",
      cell: ({ row }) => new Date(row.original.incidentDate).toLocaleDateString("fr-FR"),
    },
    {
      id: "student",
      header: "Élève",
      cell: ({ row }) =>
        row.original.student
          ? `${row.original.student.lastName} ${row.original.student.firstName}`
          : row.original.studentId,
    },
    { accessorKey: "type", header: "Type" },
    {
      id: "severity",
      header: "Gravité",
      cell: ({ row }) => SEVERITY_LABELS[row.original.severity],
    },
    {
      id: "description",
      header: "Description",
      cell: ({ row }) => <span className="line-clamp-1 max-w-md text-muted-foreground">{row.original.description}</span>,
    },
    {
      id: "actions",
      header: "",
      cell: ({ row }) => (
        <Button asChild variant="outline" size="sm">
          <Link href={`/discipline/${row.original.id}`}>Détail</Link>
        </Button>
      ),
    },
  ];

  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">Discipline</h1>
          <p className="text-sm text-muted-foreground">Gestion des incidents et sanctions disciplinaires</p>
        </div>
        <Button asChild>
          <Link href="/discipline/new">
            <Plus className="mr-2 h-4 w-4" />
            Signaler un incident
          </Link>
        </Button>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Registre disciplinaire</CardTitle>
          <CardDescription>Incidents, sanctions et suivi comportemental des élèves</CardDescription>
        </CardHeader>
        <CardContent className="flex flex-col gap-4">
          <div className="flex items-center gap-2">
            <label htmlFor="severity-filter" className="text-sm text-muted-foreground">
              Filtrer par gravité
            </label>
            <select
              id="severity-filter"
              className="h-9 rounded-md border border-border bg-background px-2 text-sm"
              value={severityFilter}
              onChange={(event) => {
                setPage(1);
                setSeverityFilter(event.target.value as IncidentSeverity | "");
              }}
            >
              <option value="">Toutes les gravités</option>
              {SEVERITIES.map((severity) => (
                <option key={severity} value={severity}>
                  {SEVERITY_LABELS[severity]}
                </option>
              ))}
            </select>
          </div>

          {error ? <p className="text-sm font-medium text-destructive">{error}</p> : null}

          {incidents.length === 0 && !isLoading && !error ? (
            <div className="flex flex-col items-center justify-center py-12 text-center">
              <ShieldAlert className="mb-4 h-12 w-12 text-muted-foreground" />
              <p className="text-sm text-muted-foreground">Aucun incident enregistré pour ces critères.</p>
            </div>
          ) : (
            <DataTable
              columns={columns}
              data={incidents}
              meta={{ page, limit: 20, total: incidents.length, totalPages }}
              isLoading={isLoading}
              onPageChange={setPage}
              emptyLabel="Aucun incident pour ces critères"
            />
          )}
        </CardContent>
      </Card>
    </div>
  );
}
