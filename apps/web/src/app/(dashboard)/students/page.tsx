"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import type { ColumnDef } from "@tanstack/react-table";
import { FileUp, Plus } from "lucide-react";
import type { PaginatedResult } from "@scholaris/shared";
import { resourceClient } from "@/lib/api-client";
import { downloadCsv, openPrintable } from "@/lib/download";
import type { ClassRoom } from "@/types/structure";
import { STUDENT_STATUS_LABELS, type Student, type StudentStatus } from "@/types/students";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { DataTable } from "@/components/shared/data-table";

const columns: ColumnDef<Student>[] = [
  { accessorKey: "matricule", header: "Matricule" },
  {
    id: "name",
    header: "Nom et prénom",
    cell: ({ row }) => (
      <Link href={`/students/${row.original.id}`} className="font-medium text-primary hover:underline">
        {row.original.lastName} {row.original.firstName}
      </Link>
    ),
  },
  {
    id: "classroom",
    header: "Classe",
    cell: ({ row }) => row.original.enrollments?.[0]?.classroom?.name ?? "—",
  },
  {
    accessorKey: "dateOfBirth",
    header: "Né(e) le",
    cell: ({ row }) => new Date(row.original.dateOfBirth).toLocaleDateString("fr-FR"),
  },
  {
    accessorKey: "status",
    header: "Statut",
    cell: ({ row }) => STUDENT_STATUS_LABELS[row.original.status],
  },
  {
    id: "actions",
    header: "",
    cell: ({ row }) => (
      <Button asChild variant="outline" size="sm">
        <Link href={`/students/${row.original.id}`}>Dossier</Link>
      </Button>
    ),
  },
];

export default function StudentsPage() {
  const [result, setResult] = useState<PaginatedResult<Student> | null>(null);
  const [classrooms, setClassrooms] = useState<ClassRoom[]>([]);
  const [search, setSearch] = useState("");
  const [classroomFilter, setClassroomFilter] = useState("");
  const [statusFilter, setStatusFilter] = useState("");
  const [page, setPage] = useState(1);
  const [isLoading, setIsLoading] = useState(true);

  useEffect(() => {
    resourceClient.get<ClassRoom[]>("/classrooms").then((response) => setClassrooms(response.data));
  }, []);

  useEffect(() => {
    const timer = setTimeout(() => {
      setIsLoading(true);
      resourceClient
        .get<PaginatedResult<Student>>("/students", {
          params: {
            page,
            limit: 20,
            ...(search ? { search } : {}),
            ...(classroomFilter ? { classroomId: classroomFilter } : {}),
            ...(statusFilter ? { status: statusFilter } : {}),
          },
        })
        .then((response) => setResult(response.data))
        .finally(() => setIsLoading(false));
    }, 300);
    return () => clearTimeout(timer);
  }, [search, classroomFilter, statusFilter, page]);

  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">Élèves</h1>
          <p className="text-sm text-muted-foreground">Dossiers des élèves de l'établissement</p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Button variant="outline" size="sm" onClick={() => downloadCsv("/students/export/csv", "eleves.csv")}>
            Export CSV
          </Button>
          <Button variant="outline" size="sm" onClick={() => openPrintable("/students/print/class-list")}>
            Imprimer la liste
          </Button>
          <Button asChild variant="outline" size="sm">
            <Link href="/students/import">
              <FileUp className="mr-2 h-4 w-4" />
              Importer (Excel)
            </Link>
          </Button>
          <Button asChild size="sm">
            <Link href="/students/new">
              <Plus className="mr-2 h-4 w-4" />
              Nouvel élève
            </Link>
          </Button>
        </div>
      </div>

      <div className="flex flex-wrap items-center gap-3">
        <Input
          placeholder="Rechercher (nom, prénom, matricule)…"
          className="max-w-xs"
          value={search}
          onChange={(event) => {
            setSearch(event.target.value);
            setPage(1);
          }}
        />
        <select
          className="h-9 rounded-md border border-border bg-background px-2 text-sm"
          value={classroomFilter}
          onChange={(event) => {
            setClassroomFilter(event.target.value);
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
          onChange={(event) => {
            setStatusFilter(event.target.value);
            setPage(1);
          }}
        >
          <option value="">Tous les statuts</option>
          {(Object.keys(STUDENT_STATUS_LABELS) as StudentStatus[]).map((status) => (
            <option key={status} value={status}>
              {STUDENT_STATUS_LABELS[status]}
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
        emptyLabel="Aucun élève pour ces critères"
      />
    </div>
  );
}
