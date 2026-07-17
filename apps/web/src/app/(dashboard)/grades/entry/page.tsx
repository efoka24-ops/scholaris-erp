"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import Link from "next/link";
import { NotebookPen, School } from "lucide-react";
import { resourceClient } from "@/lib/api-client";
import type { ClassRoom } from "@/types/structure";
import type { Period } from "@/types/settings";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";

interface SubjectAssignment {
  id: string;
  subjectId: string | null;
  courseElementId: string | null;
  subject?: { id: string; code: string; name: string } | null;
  courseElement?: { id: string; code: string; name: string } | null;
}

export default function GradesEntryPage() {
  const router = useRouter();

  const [classrooms, setClassrooms] = useState<ClassRoom[]>([]);
  const [periods, setPeriods] = useState<Period[]>([]);
  const [assignments, setAssignments] = useState<SubjectAssignment[]>([]);

  const [classroomId, setClassroomId] = useState("");
  const [subjectId, setSubjectId] = useState("");
  const [periodId, setPeriodId] = useState("");
  const [isLoadingAssignments, setIsLoadingAssignments] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    resourceClient
      .get<ClassRoom[]>("/classrooms")
      .then((response) => setClassrooms(response.data))
      .catch(() => setError("Impossible de charger les classes."));
    resourceClient
      .get<Period[]>("/periods")
      .then((response) => setPeriods(response.data))
      .catch(() => setError("Impossible de charger les périodes."));
  }, []);

  useEffect(() => {
    if (!classroomId) {
      setAssignments([]);
      setSubjectId("");
      return;
    }
    setIsLoadingAssignments(true);
    resourceClient
      .get<SubjectAssignment[]>("/subject-assignments", { params: { classroomId } })
      .then((response) => setAssignments(response.data))
      .catch(() => setError("Impossible de charger les matières assignées à cette classe."))
      .finally(() => setIsLoadingAssignments(false));
  }, [classroomId]);

  function handleStart() {
    if (!classroomId || !subjectId || !periodId) return;
    router.push(`/grades/entry/${classroomId}/${subjectId}/${periodId}`);
  }

  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">Saisie des notes</h1>
          <p className="text-sm text-muted-foreground">Saisir les notes par classe, matière et période</p>
        </div>
      </div>

      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <NotebookPen className="h-5 w-5" />
            Sélection classe / matière / période
          </CardTitle>
          <CardDescription>Choisissez la classe, la matière et la période pour commencer la saisie</CardDescription>
        </CardHeader>
        <CardContent className="flex flex-col gap-4">
          <div className="grid gap-3 md:grid-cols-3">
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="entry-classroom">Classe</Label>
              <select
                id="entry-classroom"
                className="h-10 rounded-md border border-border bg-background px-3 text-sm"
                value={classroomId}
                onChange={(event) => {
                  setClassroomId(event.target.value);
                  setSubjectId("");
                }}
              >
                <option value="">Choisir une classe…</option>
                {classrooms.map((classroom) => (
                  <option key={classroom.id} value={classroom.id}>
                    {classroom.name}
                  </option>
                ))}
              </select>
            </div>

            <div className="flex flex-col gap-1.5">
              <Label htmlFor="entry-subject">Matière</Label>
              <select
                id="entry-subject"
                className="h-10 rounded-md border border-border bg-background px-3 text-sm"
                value={subjectId}
                disabled={!classroomId || isLoadingAssignments}
                onChange={(event) => setSubjectId(event.target.value)}
              >
                <option value="">
                  {!classroomId ? "Choisir une classe d'abord…" : isLoadingAssignments ? "Chargement…" : "Choisir une matière…"}
                </option>
                {assignments.map((assignment) => {
                  const id = assignment.subjectId ?? assignment.courseElementId ?? assignment.id;
                  const label = assignment.subject?.name ?? assignment.courseElement?.name ?? "—";
                  return (
                    <option key={assignment.id} value={id ?? ""}>
                      {label}
                    </option>
                  );
                })}
              </select>
              {classroomId && !isLoadingAssignments && assignments.length === 0 ? (
                <p className="text-xs text-muted-foreground">Aucune matière assignée à cette classe.</p>
              ) : null}
            </div>

            <div className="flex flex-col gap-1.5">
              <Label htmlFor="entry-period">Période</Label>
              <select
                id="entry-period"
                className="h-10 rounded-md border border-border bg-background px-3 text-sm"
                value={periodId}
                onChange={(event) => setPeriodId(event.target.value)}
              >
                <option value="">Choisir une période…</option>
                {periods.map((period) => (
                  <option key={period.id} value={period.id}>
                    {period.type} {period.number}
                  </option>
                ))}
              </select>
            </div>
          </div>

          {error ? <p className="text-sm font-medium text-destructive">{error}</p> : null}

          <div className="flex gap-2">
            <Button disabled={!classroomId || !subjectId || !periodId} onClick={handleStart}>
              Commencer la saisie
            </Button>
            <Button asChild variant="outline">
              <Link href="/academics/classrooms">
                <School className="mr-2 h-4 w-4" />
                Voir les classes
              </Link>
            </Button>
            <Button asChild variant="outline">
              <Link href="/grades/progress">Avancement saisie</Link>
            </Button>
          </div>
        </CardContent>
      </Card>
    </div>
  );
}
