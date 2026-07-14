"use client";

import { useEffect, useMemo, useState } from "react";
import { useParams } from "next/navigation";
import type { PaginatedResult } from "@scholaris/shared";
import { resourceClient } from "@/lib/api-client";
import type { Student } from "@/types/students";
import { GRADE_TYPE_LABELS, gradeColorClass, type GradeType } from "@/types/grades";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from "@/components/ui/card";
import { LoadingSpinner } from "@/components/shared/loading-spinner";

interface RowState {
  studentId: string;
  value: string;
  isAbsent: boolean;
  isJustified: boolean;
}

interface AssignmentInfo {
  label: string;
  isSubject: boolean;
}

export default function GradeEntryPage() {
  const params = useParams<{ classId: string; subjectId: string; periodId: string }>();
  const { classId, subjectId, periodId } = params;

  const [assignment, setAssignment] = useState<AssignmentInfo | null>(null);
  const [students, setStudents] = useState<Student[]>([]);
  const [rows, setRows] = useState<RowState[]>([]);
  const [type, setType] = useState<GradeType>("TEST");
  const [maxValue, setMaxValue] = useState(20);
  const [weight, setWeight] = useState(1);
  const [date, setDate] = useState(() => new Date().toISOString().slice(0, 10));
  const [isLoading, setIsLoading] = useState(true);
  const [isSaving, setIsSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);

  useEffect(() => {
    setIsLoading(true);
    Promise.all([
      resourceClient.get("/subject-assignments", { params: { classroomId: classId } }),
      resourceClient.get<PaginatedResult<Student>>("/students", { params: { classroomId: classId, limit: 100 } }),
    ])
      .then(([assignmentsRes, studentsRes]) => {
        const match = (assignmentsRes.data as any[]).find(
          (a) => a.subjectId === subjectId || a.courseElementId === subjectId,
        );
        setAssignment({
          label: match?.subject?.name ?? match?.courseElement?.name ?? "Matière",
          isSubject: Boolean(match?.subjectId),
        });
        const roster = studentsRes.data.data;
        setStudents(roster);
        setRows(roster.map((s) => ({ studentId: s.id, value: "", isAbsent: false, isJustified: false })));
      })
      .finally(() => setIsLoading(false));
  }, [classId, subjectId]);

  const studentsById = useMemo(() => new Map(students.map((s) => [s.id, s])), [students]);

  function updateRow(studentId: string, patch: Partial<RowState>) {
    setRows((prev) => prev.map((r) => (r.studentId === studentId ? { ...r, ...patch } : r)));
  }

  async function onSave() {
    setError(null);
    setSuccess(null);
    setIsSaving(true);
    try {
      const entries = rows
        .filter((r) => r.isAbsent || r.value.trim() !== "")
        .map((r) => ({
          studentId: r.studentId,
          value: r.isAbsent ? undefined : Number(r.value),
          isAbsent: r.isAbsent,
          isJustified: r.isJustified,
        }));
      if (entries.length === 0) {
        setError("Saisissez au moins une note.");
        return;
      }
      await resourceClient.post("/grades/batch", {
        classroomId: classId,
        ...(assignment?.isSubject ? { subjectId } : { courseElementId: subjectId }),
        periodId,
        type,
        date,
        maxValue,
        weight,
        entries,
      });
      setSuccess(`${entries.length} note(s) enregistrée(s).`);
    } catch (submitError: any) {
      setError(submitError.response?.data?.message ?? "Impossible d'enregistrer les notes.");
    } finally {
      setIsSaving(false);
    }
  }

  if (isLoading) {
    return <LoadingSpinner label="Chargement de la grille de saisie…" />;
  }

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-2xl font-semibold">Saisie des notes — {assignment?.label}</h1>
        <p className="text-sm text-muted-foreground">
          Rouge : note &lt; 10/20 — Orange : entre 10 et 12/20 — Vert : ≥ 12/20 (équivalent /20).
        </p>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Évaluation</CardTitle>
          <CardDescription>Type, barème et pondération communs à toutes les notes saisies ci-dessous.</CardDescription>
        </CardHeader>
        <CardContent className="grid grid-cols-2 gap-3 md:grid-cols-4">
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
            Date
            <Input type="date" value={date} onChange={(e) => setDate(e.target.value)} />
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

      <div className="overflow-x-auto rounded-md border border-border">
        <table className="w-full text-sm">
          <thead className="bg-secondary/60 text-left">
            <tr>
              <th className="px-4 py-2 font-medium">Matricule</th>
              <th className="px-4 py-2 font-medium">Élève</th>
              <th className="px-4 py-2 font-medium">Note / {maxValue}</th>
              <th className="px-4 py-2 font-medium">Absent</th>
              <th className="px-4 py-2 font-medium">Justifié</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => {
              const student = studentsById.get(row.studentId);
              const numericValue = row.value.trim() === "" ? null : Number(row.value);
              return (
                <tr key={row.studentId} className="border-t border-border">
                  <td className="px-4 py-2">{student?.matricule ?? "—"}</td>
                  <td className="px-4 py-2">
                    {student ? `${student.lastName} ${student.firstName}` : row.studentId}
                  </td>
                  <td className="px-4 py-2">
                    <Input
                      type="number"
                      min={0}
                      max={maxValue}
                      step={0.25}
                      disabled={row.isAbsent}
                      value={row.value}
                      className={gradeColorClass(numericValue, maxValue)}
                      onChange={(e) => updateRow(row.studentId, { value: e.target.value })}
                    />
                  </td>
                  <td className="px-4 py-2">
                    <input
                      type="checkbox"
                      checked={row.isAbsent}
                      onChange={(e) => updateRow(row.studentId, { isAbsent: e.target.checked, value: "" })}
                    />
                  </td>
                  <td className="px-4 py-2">
                    <input
                      type="checkbox"
                      disabled={!row.isAbsent}
                      checked={row.isJustified}
                      onChange={(e) => updateRow(row.studentId, { isJustified: e.target.checked })}
                    />
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>

      {error ? <p className="text-sm font-medium text-destructive">{error}</p> : null}
      {success ? <p className="text-sm font-medium text-primary">{success}</p> : null}

      <Button className="w-fit" disabled={isSaving} onClick={onSave}>
        {isSaving ? "Enregistrement…" : "Enregistrer les notes"}
      </Button>
    </div>
  );
}
