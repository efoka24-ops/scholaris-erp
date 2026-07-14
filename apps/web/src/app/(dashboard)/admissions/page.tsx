"use client";

import { useCallback, useEffect, useState } from "react";
import type { ColumnDef } from "@tanstack/react-table";
import type { PaginatedResult } from "@scholaris/shared";
import { resourceClient } from "@/lib/api-client";
import {
  ADMISSION_STATUS_LABELS,
  ADMISSION_TYPE_LABELS,
  type AcademicYear,
  type AdmissionApplication,
  type AdmissionStatus,
  type AdmissionType,
} from "@/types/students";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { DataTable } from "@/components/shared/data-table";

export default function AdmissionsPage() {
  const [result, setResult] = useState<PaginatedResult<AdmissionApplication> | null>(null);
  const [years, setYears] = useState<AcademicYear[]>([]);
  const [statusFilter, setStatusFilter] = useState("");
  const [page, setPage] = useState(1);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [showForm, setShowForm] = useState(false);
  const [form, setForm] = useState({ applicantName: "", type: "DOSSIER" as AdmissionType, score: "", academicYearId: "" });
  const [isSubmitting, setIsSubmitting] = useState(false);

  const load = useCallback(() => {
    setIsLoading(true);
    resourceClient
      .get<PaginatedResult<AdmissionApplication>>("/admissions", {
        params: { page, limit: 20, ...(statusFilter ? { status: statusFilter } : {}) },
      })
      .then((response) => setResult(response.data))
      .finally(() => setIsLoading(false));
  }, [page, statusFilter]);

  useEffect(() => {
    resourceClient.get<AcademicYear[]>("/academic-years").then((response) => setYears(response.data));
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  async function decide(id: string, status: AdmissionStatus) {
    setError(null);
    try {
      await resourceClient.put(`/admissions/${id}/decision`, { status });
      load();
    } catch (decideError: any) {
      setError(decideError.response?.data?.message ?? "Décision impossible.");
    }
  }

  async function createApplication() {
    setError(null);
    setIsSubmitting(true);
    try {
      await resourceClient.post("/admissions", {
        applicantName: form.applicantName,
        type: form.type,
        academicYearId: form.academicYearId,
        ...(form.score ? { score: Number(form.score) } : {}),
      });
      setForm({ applicantName: "", type: "DOSSIER", score: "", academicYearId: form.academicYearId });
      setShowForm(false);
      load();
    } catch (createError: any) {
      setError(createError.response?.data?.message ?? "Création impossible.");
    } finally {
      setIsSubmitting(false);
    }
  }

  const columns: ColumnDef<AdmissionApplication>[] = [
    { accessorKey: "applicantName", header: "Candidat" },
    {
      accessorKey: "type",
      header: "Type",
      cell: ({ row }) => ADMISSION_TYPE_LABELS[row.original.type],
    },
    {
      accessorKey: "score",
      header: "Note",
      cell: ({ row }) => (row.original.score !== null ? row.original.score.toFixed(2) : "—"),
    },
    {
      accessorKey: "rank",
      header: "Rang",
      cell: ({ row }) => row.original.rank ?? "—",
    },
    {
      id: "academicYear",
      header: "Année",
      cell: ({ row }) => row.original.academicYear?.label ?? "—",
    },
    {
      accessorKey: "status",
      header: "Statut",
      cell: ({ row }) => ADMISSION_STATUS_LABELS[row.original.status],
    },
    {
      id: "actions",
      header: "Décision",
      cell: ({ row }) =>
        row.original.status === "PENDING" || row.original.status === "WAITLISTED" ? (
          <div className="flex gap-1.5">
            <Button size="sm" onClick={() => decide(row.original.id, "ACCEPTED")}>
              Accepter
            </Button>
            <Button size="sm" variant="destructive" onClick={() => decide(row.original.id, "REJECTED")}>
              Refuser
            </Button>
            {row.original.status === "PENDING" ? (
              <Button size="sm" variant="outline" onClick={() => decide(row.original.id, "WAITLISTED")}>
                Liste d'attente
              </Button>
            ) : null}
          </div>
        ) : null,
    },
  ];

  const fieldClass = "h-10 w-full rounded-md border border-border bg-background px-3 text-sm";

  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">Admissions</h1>
          <p className="text-sm text-muted-foreground">Candidatures : concours, dossiers, admissions directes</p>
        </div>
        <Button size="sm" onClick={() => setShowForm(!showForm)}>
          {showForm ? "Fermer" : "Nouvelle candidature"}
        </Button>
      </div>

      {showForm ? (
        <Card>
          <CardHeader>
            <CardTitle>Nouvelle candidature</CardTitle>
          </CardHeader>
          <CardContent className="grid grid-cols-2 gap-4 md:grid-cols-4">
            <div className="flex flex-col gap-1.5">
              <Label>Nom du candidat *</Label>
              <Input value={form.applicantName} onChange={(e) => setForm({ ...form, applicantName: e.target.value })} />
            </div>
            <div className="flex flex-col gap-1.5">
              <Label>Type *</Label>
              <select
                className={fieldClass}
                value={form.type}
                onChange={(e) => setForm({ ...form, type: e.target.value as AdmissionType })}
              >
                {(Object.keys(ADMISSION_TYPE_LABELS) as AdmissionType[]).map((type) => (
                  <option key={type} value={type}>
                    {ADMISSION_TYPE_LABELS[type]}
                  </option>
                ))}
              </select>
            </div>
            <div className="flex flex-col gap-1.5">
              <Label>Note (si concours)</Label>
              <Input type="number" step="0.01" value={form.score} onChange={(e) => setForm({ ...form, score: e.target.value })} />
            </div>
            <div className="flex flex-col gap-1.5">
              <Label>Année académique *</Label>
              <select
                className={fieldClass}
                value={form.academicYearId}
                onChange={(e) => setForm({ ...form, academicYearId: e.target.value })}
              >
                <option value="">Sélectionner…</option>
                {years.map((year) => (
                  <option key={year.id} value={year.id}>
                    {year.label}
                  </option>
                ))}
              </select>
            </div>
            <div className="col-span-2 md:col-span-4">
              <Button disabled={!form.applicantName || !form.academicYearId || isSubmitting} onClick={createApplication}>
                {isSubmitting ? "Enregistrement…" : "Enregistrer la candidature"}
              </Button>
            </div>
          </CardContent>
        </Card>
      ) : null}

      <div className="flex items-center gap-2">
        <label htmlFor="status-filter" className="text-sm text-muted-foreground">
          Statut
        </label>
        <select
          id="status-filter"
          className="h-9 rounded-md border border-border bg-background px-2 text-sm"
          value={statusFilter}
          onChange={(event) => {
            setStatusFilter(event.target.value);
            setPage(1);
          }}
        >
          <option value="">Tous</option>
          {(Object.keys(ADMISSION_STATUS_LABELS) as AdmissionStatus[]).map((status) => (
            <option key={status} value={status}>
              {ADMISSION_STATUS_LABELS[status]}
            </option>
          ))}
        </select>
      </div>

      {error ? <p className="text-sm font-medium text-destructive">{error}</p> : null}

      <DataTable
        columns={columns}
        data={result?.data ?? []}
        meta={result?.meta}
        isLoading={isLoading}
        onPageChange={setPage}
        emptyLabel="Aucune candidature"
      />
    </div>
  );
}
