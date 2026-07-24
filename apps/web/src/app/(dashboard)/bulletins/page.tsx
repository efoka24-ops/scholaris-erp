"use client";

import { useEffect, useState } from "react";
import type { ColumnDef } from "@tanstack/react-table";
import type { PaginatedResult } from "@scholaris/shared";
import { Download } from "lucide-react";
import { resourceClient } from "@/lib/api-client";
import { openPrintable } from "@/lib/download";
import type { ClassRoom } from "@/types/structure";
import type { Period } from "@/types/settings";
import type { Student } from "@/types/students";
import { BULLETIN_STATUS_LABELS, type Bulletin, type GenerateBatchResult } from "@/types/bulletins";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { DataTable } from "@/components/shared/data-table";

export default function BulletinsPage() {
  const [classrooms, setClassrooms] = useState<ClassRoom[]>([]);
  const [periods, setPeriods] = useState<Period[]>([]);
  const [students, setStudents] = useState<Student[]>([]);

  const [batchClassroomId, setBatchClassroomId] = useState("");
  const [batchPeriodId, setBatchPeriodId] = useState("");
  const [batchReport, setBatchReport] = useState<GenerateBatchResult | null>(null);
  const [batchError, setBatchError] = useState<string | null>(null);
  const [isGenerating, setIsGenerating] = useState(false);

  const [studentFilter, setStudentFilter] = useState("");
  const [bulletins, setBulletins] = useState<Bulletin[]>([]);
  const [isLoading, setIsLoading] = useState(false);
  const [listError, setListError] = useState<string | null>(null);

  useEffect(() => {
    resourceClient.get<ClassRoom[]>("/classrooms").then((response) => setClassrooms(response.data));
    resourceClient.get<Period[]>("/periods").then((response) => setPeriods(response.data));
    resourceClient
      .get<PaginatedResult<Student>>("/students", { params: { limit: 200 } })
      .then((response) => setStudents(response.data.data));
  }, []);

  async function generateBatch() {
    setBatchError(null);
    setBatchReport(null);
    setIsGenerating(true);
    try {
      const { data } = await resourceClient.post<GenerateBatchResult>("/bulletins/generate/classroom", {
        classroomId: batchClassroomId,
        periodId: batchPeriodId,
      });
      setBatchReport(data);
    } catch (error: any) {
      setBatchError(error.response?.data?.message ?? "Impossible de générer les bulletins.");
    } finally {
      setIsGenerating(false);
    }
  }

  function loadStudentBulletins(studentId: string) {
    setStudentFilter(studentId);
    if (!studentId) {
      setBulletins([]);
      return;
    }
    setIsLoading(true);
    setListError(null);
    resourceClient
      .get<Bulletin[]>(`/bulletins/student/${studentId}`)
      .then((response) => setBulletins(response.data))
      .catch((requestError: any) =>
        setListError(requestError.response?.data?.message ?? "Impossible de charger les bulletins."),
      )
      .finally(() => setIsLoading(false));
  }

  const columns: ColumnDef<Bulletin>[] = [
    {
      id: "createdAt",
      header: "Généré le",
      cell: ({ row }) => new Date(row.original.createdAt).toLocaleDateString("fr-FR"),
    },
    {
      id: "average",
      header: "Moyenne",
      cell: ({ row }) => (row.original.data?.average !== undefined ? Number(row.original.data.average).toFixed(2) : "—"),
    },
    {
      id: "status",
      header: "Statut",
      cell: ({ row }) => BULLETIN_STATUS_LABELS[row.original.status],
    },
    {
      id: "actions",
      header: "",
      cell: ({ row }) => (
        <Button
          variant="outline"
          size="sm"
          onClick={() => openPrintable(`/bulletins/${row.original.id}/pdf`)}
        >
          <Download className="mr-1 h-3.5 w-3.5" />
          PDF
        </Button>
      ),
    },
  ];

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-2xl font-semibold">Bulletins scolaires</h1>
        <p className="text-sm text-muted-foreground">Génération, visualisation et téléchargement des bulletins de notes</p>
      </div>

      <Card>
        <CardHeader>
          <CardTitle className="text-base">Générer les bulletins d'une classe</CardTitle>
          <CardDescription>Un bulletin est généré pour chaque élève inscrit dans la classe sur la période choisie.</CardDescription>
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
              value={batchPeriodId}
              onChange={(e) => setBatchPeriodId(e.target.value)}
            >
              <option value="">Choisir une période…</option>
              {periods.map((period) => (
                <option key={period.id} value={period.id}>
                  {period.type} {period.number}
                </option>
              ))}
            </select>
            <Button disabled={!batchClassroomId || !batchPeriodId || isGenerating} onClick={generateBatch}>
              {isGenerating ? "Génération…" : "Générer les bulletins"}
            </Button>
          </div>
          {batchError ? <p className="text-sm font-medium text-destructive">{batchError}</p> : null}
          {batchReport ? (
            <div className="rounded-md border border-border bg-secondary/40 p-3 text-sm">
              <p className="font-medium">
                {batchReport.success} bulletin(s) généré(s) sur {batchReport.total}
                {batchReport.errors > 0 ? ` (${batchReport.errors} erreur(s))` : ""}
              </p>
              {batchReport.results
                .filter((result) => result.status === "error")
                .map((result, index) => (
                  <p key={index} className="text-muted-foreground">
                    {result.matricule} — {result.error}
                  </p>
                ))}
            </div>
          ) : null}
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Bulletins par élève</CardTitle>
          <CardDescription>Sélectionnez un élève pour consulter ses bulletins générés et les télécharger en PDF</CardDescription>
        </CardHeader>
        <CardContent className="flex flex-col gap-4">
          <select
            className="h-9 w-full max-w-sm rounded-md border border-border bg-background px-2 text-sm"
            value={studentFilter}
            onChange={(e) => loadStudentBulletins(e.target.value)}
          >
            <option value="">Sélectionner un élève…</option>
            {students.map((student) => (
              <option key={student.id} value={student.id}>
                {student.lastName} {student.firstName} — {student.matricule}
              </option>
            ))}
          </select>

          {listError ? <p className="text-sm font-medium text-destructive">{listError}</p> : null}

          {studentFilter ? (
            <DataTable columns={columns} data={bulletins} isLoading={isLoading} emptyLabel="Aucun bulletin généré pour cet élève" />
          ) : (
            <p className="text-sm text-muted-foreground">Choisissez un élève pour afficher ses bulletins.</p>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
