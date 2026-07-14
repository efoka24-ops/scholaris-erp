"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import type { ColumnDef } from "@tanstack/react-table";
import type { PaginatedResult } from "@scholaris/shared";
import { resourceClient } from "@/lib/api-client";
import type { ClassRoom } from "@/types/structure";
import type { AcademicYear } from "@/types/students";
import { formatAmount, INVOICE_STATUS_LABELS, type GenerateBatchReport, type Invoice, type InvoiceStatus } from "@/types/finance";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { DataTable } from "@/components/shared/data-table";

const columns: ColumnDef<Invoice>[] = [
  {
    id: "student",
    header: "Élève",
    cell: ({ row }) => (
      <Link href={`/finance/invoices/${row.original.id}`} className="font-medium text-primary hover:underline">
        {row.original.student ? `${row.original.student.lastName} ${row.original.student.firstName}` : "—"}
      </Link>
    ),
  },
  {
    id: "classroom",
    header: "Classe",
    cell: ({ row }) => row.original.enrollment?.classroom?.name ?? "—",
  },
  {
    id: "totalAmount",
    header: "Montant total",
    cell: ({ row }) => formatAmount(row.original.totalAmount),
  },
  {
    id: "paidAmount",
    header: "Payé",
    cell: ({ row }) => formatAmount(row.original.paidAmount),
  },
  {
    id: "balance",
    header: "Solde",
    cell: ({ row }) => formatAmount(row.original.balance),
  },
  {
    id: "status",
    header: "Statut",
    cell: ({ row }) => INVOICE_STATUS_LABELS[row.original.status],
  },
  {
    id: "actions",
    header: "",
    cell: ({ row }) => (
      <Button asChild variant="outline" size="sm">
        <Link href={`/finance/invoices/${row.original.id}`}>Détail</Link>
      </Button>
    ),
  },
];

export default function InvoicesPage() {
  const [result, setResult] = useState<PaginatedResult<Invoice> | null>(null);
  const [classrooms, setClassrooms] = useState<ClassRoom[]>([]);
  const [years, setYears] = useState<AcademicYear[]>([]);
  const [classroomFilter, setClassroomFilter] = useState("");
  const [statusFilter, setStatusFilter] = useState<InvoiceStatus | "">("");
  const [page, setPage] = useState(1);
  const [isLoading, setIsLoading] = useState(true);

  const [batchClassroomId, setBatchClassroomId] = useState("");
  const [batchYearId, setBatchYearId] = useState("");
  const [batchReport, setBatchReport] = useState<GenerateBatchReport | null>(null);
  const [batchError, setBatchError] = useState<string | null>(null);
  const [isGenerating, setIsGenerating] = useState(false);

  useEffect(() => {
    resourceClient.get<ClassRoom[]>("/classrooms").then((response) => setClassrooms(response.data));
    resourceClient.get<AcademicYear[]>("/academic-years").then((response) => setYears(response.data));
  }, []);

  function load() {
    setIsLoading(true);
    resourceClient
      .get<PaginatedResult<Invoice>>("/invoices", {
        params: {
          page,
          limit: 20,
          ...(classroomFilter ? { classroomId: classroomFilter } : {}),
          ...(statusFilter ? { status: statusFilter } : {}),
        },
      })
      .then((response) => setResult(response.data))
      .finally(() => setIsLoading(false));
  }

  useEffect(() => {
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [classroomFilter, statusFilter, page]);

  async function generateBatch() {
    setBatchError(null);
    setBatchReport(null);
    setIsGenerating(true);
    try {
      const { data } = await resourceClient.post<GenerateBatchReport>(
        `/invoices/generate-batch/${batchClassroomId}`,
        { academicYearId: batchYearId },
      );
      setBatchReport(data);
      load();
    } catch (error: any) {
      setBatchError(error.response?.data?.message ?? "Impossible de générer les factures.");
    } finally {
      setIsGenerating(false);
    }
  }

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-2xl font-semibold">Factures</h1>
        <p className="text-sm text-muted-foreground">Facturation des frais de scolarité</p>
      </div>

      <Card>
        <CardHeader>
          <CardTitle className="text-base">Générer les factures d'une classe</CardTitle>
          <CardDescription>Une facture est créée pour chaque inscription active, depuis la grille tarifaire applicable.</CardDescription>
        </CardHeader>
        <CardContent className="flex flex-col gap-3">
          <div className="flex flex-wrap items-end gap-3">
            <select
              className="h-10 rounded-md border border-border bg-background px-3 text-sm"
              value={batchClassroomId}
              onChange={(e) => setBatchClassroomId(e.target.value)}
            >
              <option value="">Choisir une classe…</option>
              {classrooms.map((classroom) => (
                <option key={classroom.id} value={classroom.id}>
                  {classroom.name}
                </option>
              ))}
            </select>
            <select
              className="h-10 rounded-md border border-border bg-background px-3 text-sm"
              value={batchYearId}
              onChange={(e) => setBatchYearId(e.target.value)}
            >
              <option value="">Choisir une année…</option>
              {years.map((year) => (
                <option key={year.id} value={year.id}>
                  {year.label}
                </option>
              ))}
            </select>
            <Button disabled={!batchClassroomId || !batchYearId || isGenerating} onClick={generateBatch}>
              {isGenerating ? "Génération…" : "Générer les factures"}
            </Button>
          </div>
          {batchError ? <p className="text-sm font-medium text-destructive">{batchError}</p> : null}
          {batchReport ? (
            <div className="rounded-md border border-border bg-secondary/40 p-3 text-sm">
              <p className="font-medium">{batchReport.generated} facture(s) générée(s)</p>
              {batchReport.skipped.length > 0 ? (
                <ul className="mt-1 list-inside list-disc text-muted-foreground">
                  {batchReport.skipped.map((skip, index) => (
                    <li key={index}>
                      {skip.studentName} — {skip.reason}
                    </li>
                  ))}
                </ul>
              ) : null}
            </div>
          ) : null}
        </CardContent>
      </Card>

      <div className="flex flex-wrap items-center gap-3">
        <select
          className="h-9 rounded-md border border-border bg-background px-2 text-sm"
          value={classroomFilter}
          onChange={(e) => {
            setClassroomFilter(e.target.value);
            setPage(1);
          }}
        >
          <option value="">Toutes les classes</option>
          {classrooms.map((classroom) => (
            <option key={classroom.id} value={classroom.id}>
              {classroom.name}
            </option>
          ))}
        </select>
        <select
          className="h-9 rounded-md border border-border bg-background px-2 text-sm"
          value={statusFilter}
          onChange={(e) => {
            setStatusFilter(e.target.value as InvoiceStatus | "");
            setPage(1);
          }}
        >
          <option value="">Tous les statuts</option>
          {(Object.keys(INVOICE_STATUS_LABELS) as InvoiceStatus[]).map((status) => (
            <option key={status} value={status}>
              {INVOICE_STATUS_LABELS[status]}
            </option>
          ))}
        </select>
      </div>

      <DataTable
        columns={columns}
        data={result?.data ?? []}
        meta={result?.meta}
        isLoading={isLoading}
        onPageChange={setPage}
        emptyLabel="Aucune facture pour ces critères"
      />
    </div>
  );
}
