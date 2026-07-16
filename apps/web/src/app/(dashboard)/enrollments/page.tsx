"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import type { ColumnDef } from "@tanstack/react-table";
import { GraduationCap, RefreshCw } from "lucide-react";
import { resourceClient } from "@/lib/api-client";
import type { ClassRoom } from "@/types/structure";
import {
  ENROLLMENT_STATUS_LABELS,
  ENROLLMENT_TYPE_LABELS,
  type AcademicYear,
  type Enrollment,
  type EnrollmentStatus,
} from "@/types/students";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { DataTable } from "@/components/shared/data-table";

const columns: ColumnDef<Enrollment>[] = [
  {
    id: "student",
    header: "Élève",
    cell: ({ row }) => (
      <Link href={`/students/${row.original.studentId}`} className="font-medium text-primary hover:underline">
        {row.original.studentId}
      </Link>
    ),
  },
  {
    id: "classroom",
    header: "Classe",
    cell: ({ row }) => row.original.classroom?.name ?? "—",
  },
  {
    id: "academicYear",
    header: "Année académique",
    cell: ({ row }) => row.original.academicYear?.label ?? "—",
  },
  {
    id: "type",
    header: "Type",
    cell: ({ row }) => ENROLLMENT_TYPE_LABELS[row.original.type],
  },
  {
    id: "regime",
    header: "Régime",
    cell: ({ row }) => row.original.regime,
  },
  {
    id: "status",
    header: "Statut",
    cell: ({ row }) => ENROLLMENT_STATUS_LABELS[row.original.status],
  },
  {
    id: "repeater",
    header: "Redoublant",
    cell: ({ row }) => (row.original.isRepeater ? "Oui" : "Non"),
  },
];

export default function EnrollmentsPage() {
  const [enrollments, setEnrollments] = useState<Enrollment[]>([]);
  const [classrooms, setClassrooms] = useState<ClassRoom[]>([]);
  const [years, setYears] = useState<AcademicYear[]>([]);
  const [classroomFilter, setClassroomFilter] = useState("");
  const [yearFilter, setYearFilter] = useState("");
  const [statusFilter, setStatusFilter] = useState<EnrollmentStatus | "">("");
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    resourceClient.get<ClassRoom[]>("/classrooms").then((response) => setClassrooms(response.data));
    resourceClient.get<AcademicYear[]>("/academic-years").then((response) => setYears(response.data));
  }, []);

  function load() {
    setIsLoading(true);
    setError(null);
    resourceClient
      .get<Enrollment[]>("/enrollments", {
        params: {
          ...(classroomFilter ? { classroomId: classroomFilter } : {}),
          ...(yearFilter ? { academicYearId: yearFilter } : {}),
          ...(statusFilter ? { status: statusFilter } : {}),
        },
      })
      .then((response) => setEnrollments(response.data))
      .catch((requestError: any) =>
        setError(requestError.response?.data?.message ?? "Impossible de charger les inscriptions."),
      )
      .finally(() => setIsLoading(false));
  }

  useEffect(() => {
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [classroomFilter, yearFilter, statusFilter]);

  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">Inscriptions</h1>
          <p className="text-sm text-muted-foreground">
            Gestion des inscriptions des élèves par année académique et classe
          </p>
        </div>
        <div className="flex gap-2">
          <Button asChild variant="outline">
            <Link href="/enrollments/re-enroll">
              <RefreshCw className="mr-2 h-4 w-4" />
              Réinscription en masse
            </Link>
          </Button>
          <Button asChild>
            <Link href="/students">
              <GraduationCap className="mr-2 h-4 w-4" />
              Nouvelle inscription
            </Link>
          </Button>
        </div>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Liste des inscriptions</CardTitle>
          <CardDescription>Filtrez et consultez les inscriptions par année, classe ou statut</CardDescription>
        </CardHeader>
        <CardContent className="flex flex-col gap-4">
          <div className="flex flex-wrap items-center gap-3">
            <select
              className="h-9 rounded-md border border-border bg-background px-2 text-sm"
              value={classroomFilter}
              onChange={(e) => setClassroomFilter(e.target.value)}
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
              value={yearFilter}
              onChange={(e) => setYearFilter(e.target.value)}
            >
              <option value="">Toutes les années</option>
              {years.map((year) => (
                <option key={year.id} value={year.id}>
                  {year.label}
                </option>
              ))}
            </select>
            <select
              className="h-9 rounded-md border border-border bg-background px-2 text-sm"
              value={statusFilter}
              onChange={(e) => setStatusFilter(e.target.value as EnrollmentStatus | "")}
            >
              <option value="">Tous les statuts</option>
              {(Object.keys(ENROLLMENT_STATUS_LABELS) as EnrollmentStatus[]).map((status) => (
                <option key={status} value={status}>
                  {ENROLLMENT_STATUS_LABELS[status]}
                </option>
              ))}
            </select>
          </div>

          {error ? <p className="text-sm font-medium text-destructive">{error}</p> : null}

          <DataTable
            columns={columns}
            data={enrollments}
            isLoading={isLoading}
            emptyLabel="Aucune inscription pour ces critères"
          />
        </CardContent>
      </Card>
    </div>
  );
}
