"use client";

import { useEffect, useState } from "react";
import { resourceClient } from "@/lib/api-client";
import type { ClassRoom } from "@/types/structure";
import type { Period } from "@/types/settings";
import { GRADE_TYPE_LABELS, type GradeType, type ImportGradesReport } from "@/types/grades";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";

interface AssignmentOption {
  subjectId: string | null;
  courseElementId: string | null;
  label: string;
}

function fileToBase64(file: File): Promise<string> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => {
      const result = reader.result as string;
      resolve(result.slice(result.indexOf(",") + 1));
    };
    reader.onerror = () => reject(reader.error);
    reader.readAsDataURL(file);
  });
}

export default function ImportGradesPage() {
  const [classrooms, setClassrooms] = useState<ClassRoom[]>([]);
  const [periods, setPeriods] = useState<Period[]>([]);
  const [assignments, setAssignments] = useState<AssignmentOption[]>([]);

  const [classroomId, setClassroomId] = useState("");
  const [periodId, setPeriodId] = useState("");
  const [target, setTarget] = useState("");
  const [type, setType] = useState<GradeType>("TEST");
  const [maxValue, setMaxValue] = useState(20);
  const [weight, setWeight] = useState(1);

  const [file, setFile] = useState<File | null>(null);
  const [report, setReport] = useState<ImportGradesReport | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [isUploading, setIsUploading] = useState(false);

  useEffect(() => {
    Promise.all([resourceClient.get<ClassRoom[]>("/classrooms"), resourceClient.get<Period[]>("/periods")]).then(
      ([classroomsRes, periodsRes]) => {
        setClassrooms(classroomsRes.data);
        setPeriods(periodsRes.data);
      },
    );
  }, []);

  useEffect(() => {
    if (!classroomId) {
      setAssignments([]);
      return;
    }
    resourceClient.get("/subject-assignments", { params: { classroomId } }).then(({ data }) => {
      setAssignments(
        (data as any[]).map((a) => ({
          subjectId: a.subjectId,
          courseElementId: a.courseElementId,
          label: a.subject?.name ?? a.courseElement?.name ?? "—",
        })),
      );
    });
  }, [classroomId]);

  async function upload() {
    if (!file || !classroomId || !periodId || !target) return;
    setIsUploading(true);
    setError(null);
    setReport(null);
    try {
      const contentBase64 = await fileToBase64(file);
      const selected = assignments.find((a) => (a.subjectId ?? a.courseElementId) === target);
      const { data } = await resourceClient.post<ImportGradesReport>(
        "/grades/import",
        {
          filename: file.name,
          contentBase64,
          classroomId,
          periodId,
          type,
          maxValue,
          weight,
          ...(selected?.subjectId ? { subjectId: selected.subjectId } : { courseElementId: selected?.courseElementId }),
        },
        { timeout: 120_000 },
      );
      setReport(data);
    } catch (uploadError: any) {
      setError(uploadError.response?.data?.message ?? "Import impossible.");
    } finally {
      setIsUploading(false);
    }
  }

  return (
    <div className="flex max-w-2xl flex-col gap-6">
      <div>
        <h1 className="text-2xl font-semibold">Importer des notes</h1>
        <p className="text-sm text-muted-foreground">Une feuille Excel = une évaluation (matière/EC, période, type, barème communs).</p>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Évaluation</CardTitle>
          <CardDescription>
            Colonnes attendues : « Matricule », « Note », « Absent » (oui/non), « Justifié » (oui/non).
          </CardDescription>
        </CardHeader>
        <CardContent className="grid grid-cols-2 gap-3">
          <label className="flex flex-col gap-1 text-sm">
            Classe
            <select
              className="h-9 rounded-md border border-border bg-background px-2 text-sm"
              value={classroomId}
              onChange={(e) => {
                setClassroomId(e.target.value);
                setTarget("");
              }}
            >
              <option value="">Sélectionner…</option>
              {classrooms.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                </option>
              ))}
            </select>
          </label>
          <label className="flex flex-col gap-1 text-sm">
            Matière / EC
            <select
              className="h-9 rounded-md border border-border bg-background px-2 text-sm"
              value={target}
              onChange={(e) => setTarget(e.target.value)}
            >
              <option value="">Sélectionner…</option>
              {assignments.map((a) => (
                <option key={a.subjectId ?? a.courseElementId ?? ""} value={a.subjectId ?? a.courseElementId ?? ""}>
                  {a.label}
                </option>
              ))}
            </select>
          </label>
          <label className="flex flex-col gap-1 text-sm">
            Période
            <select
              className="h-9 rounded-md border border-border bg-background px-2 text-sm"
              value={periodId}
              onChange={(e) => setPeriodId(e.target.value)}
            >
              <option value="">Sélectionner…</option>
              {periods.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.type} {p.number}
                </option>
              ))}
            </select>
          </label>
          <label className="flex flex-col gap-1 text-sm">
            Type
            <select
              className="h-9 rounded-md border border-border bg-background px-2 text-sm"
              value={type}
              onChange={(e) => setType(e.target.value as GradeType)}
            >
              {Object.entries(GRADE_TYPE_LABELS).map(([value, label]) => (
                <option key={value} value={value}>
                  {label}
                </option>
              ))}
            </select>
          </label>
          <label className="flex flex-col gap-1 text-sm">
            Barème
            <Input type="number" min={1} value={maxValue} onChange={(e) => setMaxValue(Number(e.target.value))} />
          </label>
          <label className="flex flex-col gap-1 text-sm">
            Pondération
            <Input type="number" min={0.1} step={0.1} value={weight} onChange={(e) => setWeight(Number(e.target.value))} />
          </label>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Fichier Excel (.xlsx)</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-col gap-4">
          <input
            type="file"
            accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
            className="text-sm"
            onChange={(event) => {
              setFile(event.target.files?.[0] ?? null);
              setReport(null);
              setError(null);
            }}
          />
          <Button
            className="w-fit"
            disabled={!file || !classroomId || !periodId || !target || isUploading}
            onClick={upload}
          >
            {isUploading ? "Import en cours…" : "Lancer l'import"}
          </Button>
          {error ? <p className="text-sm font-medium text-destructive">{error}</p> : null}
        </CardContent>
      </Card>

      {report ? (
        <Card>
          <CardHeader>
            <CardTitle>Rapport d'import</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-col gap-4">
            <div className="grid grid-cols-3 gap-3 text-center">
              <div className="rounded-md border border-border p-3">
                <p className="text-2xl font-semibold text-primary">{report.created}</p>
                <p className="text-sm text-muted-foreground">note(s) créée(s)</p>
              </div>
              <div className="rounded-md border border-border p-3">
                <p className="text-2xl font-semibold">{report.skipped}</p>
                <p className="text-sm text-muted-foreground">ignorée(s)</p>
              </div>
              <div className="rounded-md border border-border p-3">
                <p className="text-2xl font-semibold text-destructive">{report.errors.length}</p>
                <p className="text-sm text-muted-foreground">erreur(s)</p>
              </div>
            </div>
            {report.errors.length > 0 ? (
              <div className="overflow-x-auto rounded-md border border-border">
                <table className="w-full text-sm">
                  <thead className="bg-secondary/60 text-left">
                    <tr>
                      <th className="px-4 py-2 font-medium">Ligne</th>
                      <th className="px-4 py-2 font-medium">Erreur</th>
                    </tr>
                  </thead>
                  <tbody>
                    {report.errors.map((rowError, index) => (
                      <tr key={`${rowError.row}-${index}`} className="border-t border-border">
                        <td className="px-4 py-2">{rowError.row || "—"}</td>
                        <td className="px-4 py-2">{rowError.message}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            ) : null}
          </CardContent>
        </Card>
      ) : null}
    </div>
  );
}
