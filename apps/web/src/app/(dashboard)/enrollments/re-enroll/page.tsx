"use client";

import { useEffect, useState } from "react";
import type { PaginatedResult } from "@scholaris/shared";
import { resourceClient } from "@/lib/api-client";
import type { ClassRoom } from "@/types/structure";
import type { AcademicYear, ReEnrollReport, Student } from "@/types/students";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { LoadingSpinner } from "@/components/shared/loading-spinner";

export default function ReEnrollPage() {
  const [classrooms, setClassrooms] = useState<ClassRoom[]>([]);
  const [years, setYears] = useState<AcademicYear[]>([]);
  const [sourceClassroomId, setSourceClassroomId] = useState("");
  const [targetClassroomId, setTargetClassroomId] = useState("");
  const [targetAcademicYearId, setTargetAcademicYearId] = useState("");
  const [preview, setPreview] = useState<Student[] | null>(null);
  const [isLoadingPreview, setIsLoadingPreview] = useState(false);
  const [report, setReport] = useState<ReEnrollReport | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);

  useEffect(() => {
    resourceClient.get<ClassRoom[]>("/classrooms").then((response) => setClassrooms(response.data));
    resourceClient.get<AcademicYear[]>("/academic-years").then((response) => setYears(response.data));
  }, []);

  useEffect(() => {
    setPreview(null);
    setReport(null);
    if (!sourceClassroomId) return;
    setIsLoadingPreview(true);
    resourceClient
      .get<PaginatedResult<Student>>("/students", {
        params: { classroomId: sourceClassroomId, limit: 100 },
      })
      .then((response) => setPreview(response.data.data))
      .finally(() => setIsLoadingPreview(false));
  }, [sourceClassroomId]);

  const targetClassroom = classrooms.find((classroom) => classroom.id === targetClassroomId);
  const canSubmit = sourceClassroomId && targetClassroomId && targetAcademicYearId && (preview?.length ?? 0) > 0;

  async function confirm() {
    setIsSubmitting(true);
    setError(null);
    setReport(null);
    try {
      const { data } = await resourceClient.post<ReEnrollReport>("/enrollments/re-enroll", {
        sourceClassroomId,
        targetClassroomId,
        targetAcademicYearId,
      });
      setReport(data);
    } catch (submitError: any) {
      setError(submitError.response?.data?.message ?? "Réinscription impossible.");
    } finally {
      setIsSubmitting(false);
    }
  }

  const fieldClass = "h-10 w-full rounded-md border border-border bg-background px-3 text-sm";

  return (
    <div className="flex max-w-3xl flex-col gap-6">
      <div>
        <h1 className="text-2xl font-semibold">Réinscription en lot</h1>
        <p className="text-sm text-muted-foreground">
          Réinscrit tous les élèves actifs d'une classe source vers une classe de destination pour la nouvelle année.
        </p>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Paramètres</CardTitle>
        </CardHeader>
        <CardContent className="grid grid-cols-3 gap-4">
          <div className="flex flex-col gap-1.5">
            <Label>Classe source</Label>
            <select className={fieldClass} value={sourceClassroomId} onChange={(e) => setSourceClassroomId(e.target.value)}>
              <option value="">Sélectionner…</option>
              {classrooms.map((classroom) => (
                <option key={classroom.id} value={classroom.id}>
                  {classroom.name}
                </option>
              ))}
            </select>
          </div>
          <div className="flex flex-col gap-1.5">
            <Label>Classe de destination</Label>
            <select className={fieldClass} value={targetClassroomId} onChange={(e) => setTargetClassroomId(e.target.value)}>
              <option value="">Sélectionner…</option>
              {classrooms
                .filter((classroom) => classroom.id !== sourceClassroomId)
                .map((classroom) => (
                  <option key={classroom.id} value={classroom.id}>
                    {classroom.name} (cap. {classroom.capacity})
                  </option>
                ))}
            </select>
          </div>
          <div className="flex flex-col gap-1.5">
            <Label>Année académique cible</Label>
            <select
              className={fieldClass}
              value={targetAcademicYearId}
              onChange={(e) => setTargetAcademicYearId(e.target.value)}
            >
              <option value="">Sélectionner…</option>
              {years.map((year) => (
                <option key={year.id} value={year.id}>
                  {year.label}
                </option>
              ))}
            </select>
          </div>
        </CardContent>
      </Card>

      {sourceClassroomId ? (
        <Card>
          <CardHeader>
            <CardTitle>Prévisualisation</CardTitle>
            <CardDescription>
              {preview ? `${preview.length} élève(s) à réinscrire` : "Chargement…"}
              {targetClassroom ? ` — capacité destination : ${targetClassroom.capacity}` : ""}
            </CardDescription>
          </CardHeader>
          <CardContent>
            {isLoadingPreview ? (
              <LoadingSpinner label="Chargement des élèves…" />
            ) : (preview?.length ?? 0) === 0 ? (
              <p className="text-sm text-muted-foreground">Aucun élève à inscription active dans cette classe.</p>
            ) : (
              <ul className="grid grid-cols-2 gap-1 text-sm md:grid-cols-3">
                {preview?.map((student) => (
                  <li key={student.id}>
                    {student.lastName} {student.firstName}
                  </li>
                ))}
              </ul>
            )}
          </CardContent>
        </Card>
      ) : null}

      {error ? <p className="text-sm font-medium text-destructive">{error}</p> : null}

      <Button className="w-fit" disabled={!canSubmit || isSubmitting} onClick={confirm}>
        {isSubmitting ? "Réinscription…" : "Confirmer la réinscription"}
      </Button>

      {report ? (
        <Card>
          <CardHeader>
            <CardTitle>Rapport</CardTitle>
            <CardDescription>
              {report.reEnrolled} réinscrit(s), {report.failed.length} échec(s)
            </CardDescription>
          </CardHeader>
          {report.failed.length > 0 ? (
            <CardContent>
              <div className="overflow-x-auto rounded-md border border-border">
                <table className="w-full text-sm">
                  <thead className="bg-secondary/60 text-left">
                    <tr>
                      <th className="px-4 py-2 font-medium">Élève</th>
                      <th className="px-4 py-2 font-medium">Motif</th>
                    </tr>
                  </thead>
                  <tbody>
                    {report.failed.map((failure) => (
                      <tr key={failure.studentId} className="border-t border-border">
                        <td className="px-4 py-2">{failure.studentName}</td>
                        <td className="px-4 py-2">{failure.reason}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </CardContent>
          ) : null}
        </Card>
      ) : null}
    </div>
  );
}
