"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { resourceClient } from "@/lib/api-client";
import type { ClassRoom } from "@/types/structure";
import type { Period } from "@/types/settings";
import type { ProgressReport } from "@/types/grades";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { LoadingSpinner } from "@/components/shared/loading-spinner";

const PERIOD_TYPE_LABELS: Record<string, string> = { SEQUENCE: "Séquence", TRIMESTER: "Trimestre", SEMESTER: "Semestre" };

export default function GradesProgressPage() {
  const [periods, setPeriods] = useState<Period[]>([]);
  const [classrooms, setClassrooms] = useState<ClassRoom[]>([]);
  const [periodId, setPeriodId] = useState("");
  const [classroomId, setClassroomId] = useState("");
  const [report, setReport] = useState<ProgressReport | null>(null);
  const [isLoading, setIsLoading] = useState(false);

  useEffect(() => {
    Promise.all([resourceClient.get<Period[]>("/periods"), resourceClient.get<ClassRoom[]>("/classrooms")]).then(
      ([periodsRes, classroomsRes]) => {
        setPeriods(periodsRes.data);
        setClassrooms(classroomsRes.data);
        if (periodsRes.data.length > 0) {
          setPeriodId(periodsRes.data[periodsRes.data.length - 1].id);
        }
      },
    );
  }, []);

  useEffect(() => {
    if (!periodId) return;
    setIsLoading(true);
    resourceClient
      .get<ProgressReport>(`/grades/progress/${periodId}`, { params: classroomId ? { classroomId } : {} })
      .then(({ data }) => setReport(data))
      .finally(() => setIsLoading(false));
  }, [periodId, classroomId]);

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-2xl font-semibold">Avancement de la saisie des notes</h1>
        <p className="text-sm text-muted-foreground">Matières saisies (toutes notes rentrées) vs non saisies, par classe.</p>
      </div>

      <div className="flex flex-wrap items-center gap-3">
        <select
          className="h-9 rounded-md border border-border bg-background px-2 text-sm"
          value={periodId}
          onChange={(e) => setPeriodId(e.target.value)}
        >
          {periods.map((period) => (
            <option key={period.id} value={period.id}>
              {PERIOD_TYPE_LABELS[period.type] ?? period.type} {period.number} ({period.gradingStatus})
            </option>
          ))}
        </select>
        <select
          className="h-9 rounded-md border border-border bg-background px-2 text-sm"
          value={classroomId}
          onChange={(e) => setClassroomId(e.target.value)}
        >
          <option value="">Toutes les classes</option>
          {classrooms.map((classroom) => (
            <option key={classroom.id} value={classroom.id}>
              {classroom.name}
            </option>
          ))}
        </select>
      </div>

      {isLoading ? (
        <LoadingSpinner label="Chargement de l'avancement…" />
      ) : !report || report.classrooms.length === 0 ? (
        <p className="text-sm text-muted-foreground">Aucune donnée pour cette période.</p>
      ) : (
        <div className="flex flex-col gap-4">
          {report.classrooms.map((classroom) => (
            <Card key={classroom.classroomId}>
              <CardHeader>
                <CardTitle>
                  {classroom.classroomName}{" "}
                  <span className="text-sm font-normal text-muted-foreground">({classroom.totalStudents} élève(s))</span>
                </CardTitle>
              </CardHeader>
              <CardContent>
                {classroom.subjects.length === 0 ? (
                  <p className="text-sm text-muted-foreground">Aucune matière assignée à cette classe.</p>
                ) : (
                  <table className="w-full text-sm">
                    <thead className="text-left text-muted-foreground">
                      <tr>
                        <th className="py-1 font-medium">Matière / EC</th>
                        <th className="py-1 font-medium">Avancement</th>
                        <th className="py-1 font-medium">Statut</th>
                        <th className="py-1 font-medium" />
                      </tr>
                    </thead>
                    <tbody>
                      {classroom.subjects.map((subject) => {
                        const targetId = subject.subjectId ?? subject.courseElementId ?? "";
                        return (
                          <tr key={targetId} className="border-t border-border">
                            <td className="py-1.5">{subject.label}</td>
                            <td className="py-1.5">
                              {subject.studentsGraded} / {subject.totalStudents}
                            </td>
                            <td className="py-1.5">
                              <span
                                className={
                                  "rounded-full px-2.5 py-0.5 text-xs font-medium " +
                                  (subject.isComplete ? "bg-primary/10 text-primary" : "bg-orange-500/10 text-orange-600")
                                }
                              >
                                {subject.isComplete ? "Complète" : "Incomplète"}
                              </span>
                            </td>
                            <td className="py-1.5 text-right">
                              <Link
                                href={`/grades/entry/${classroom.classroomId}/${targetId}/${periodId}`}
                                className="text-sm text-primary hover:underline"
                              >
                                Saisir
                              </Link>
                            </td>
                          </tr>
                        );
                      })}
                    </tbody>
                  </table>
                )}
              </CardContent>
            </Card>
          ))}
        </div>
      )}
    </div>
  );
}
