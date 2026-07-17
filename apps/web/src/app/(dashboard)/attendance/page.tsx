"use client";

import { useCallback, useEffect, useState } from "react";
import { UserCheck } from "lucide-react";
import { resourceClient } from "@/lib/api-client";
import type { ClassRoom } from "@/types/structure";
import type { Student } from "@/types/students";
import type { PaginatedResult } from "@scholaris/shared";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { LoadingSpinner } from "@/components/shared/loading-spinner";

type AttendanceStatus = "PRESENT" | "ABSENT" | "LATE" | "EXCUSED";

const STATUS_LABELS: Record<AttendanceStatus, string> = {
  PRESENT: "Présent",
  ABSENT: "Absent",
  LATE: "Retard",
  EXCUSED: "Excusé",
};

interface AttendanceRecord {
  id: string;
  date: string;
  status: AttendanceStatus;
  reason: string | null;
  student?: { firstName: string; lastName: string };
}

function todayIso() {
  return new Date().toISOString().slice(0, 10);
}

export default function AttendancePage() {
  const [classrooms, setClassrooms] = useState<ClassRoom[]>([]);
  const [classroomId, setClassroomId] = useState("");
  const [date, setDate] = useState(todayIso());

  const [students, setStudents] = useState<Student[]>([]);
  const [statuses, setStatuses] = useState<Record<string, AttendanceStatus>>({});
  const [isLoadingStudents, setIsLoadingStudents] = useState(false);

  const [history, setHistory] = useState<AttendanceRecord[]>([]);
  const [isLoadingHistory, setIsLoadingHistory] = useState(false);

  const [error, setError] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);

  useEffect(() => {
    resourceClient.get<ClassRoom[]>("/classrooms").then((response) => setClassrooms(response.data));
  }, []);

  const loadRoster = useCallback(() => {
    if (!classroomId) {
      setStudents([]);
      return;
    }
    setIsLoadingStudents(true);
    resourceClient
      .get<PaginatedResult<Student>>("/students", { params: { classroomId, limit: 100 } })
      .then((response) => {
        setStudents(response.data.data);
        setStatuses((prev) => {
          const next: Record<string, AttendanceStatus> = {};
          response.data.data.forEach((student) => {
            next[student.id] = prev[student.id] ?? "PRESENT";
          });
          return next;
        });
      })
      .catch((requestError: any) =>
        setError(requestError.response?.data?.message ?? "Impossible de charger les élèves de la classe."),
      )
      .finally(() => setIsLoadingStudents(false));
  }, [classroomId]);

  const loadHistory = useCallback(() => {
    if (!classroomId) {
      setHistory([]);
      return;
    }
    setIsLoadingHistory(true);
    resourceClient
      .get<AttendanceRecord[]>(`/attendance/classroom/${classroomId}`)
      .then((response) => setHistory(response.data))
      .catch(() => undefined)
      .finally(() => setIsLoadingHistory(false));
  }, [classroomId]);

  useEffect(() => {
    loadRoster();
    loadHistory();
  }, [loadRoster, loadHistory]);

  async function handleSubmit() {
    if (!classroomId || students.length === 0) return;
    setError(null);
    setSuccessMessage(null);
    setIsSubmitting(true);
    try {
      const records = students.map((student) => ({
        studentId: student.id,
        status: statuses[student.id] ?? "PRESENT",
      }));
      await resourceClient.post("/attendance/record", { date, classroomId, records });
      setSuccessMessage("Appel enregistré.");
      loadHistory();
    } catch (requestError: any) {
      setError(requestError.response?.data?.message ?? "Impossible d'enregistrer l'appel.");
    } finally {
      setIsSubmitting(false);
    }
  }

  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">Présences et absences</h1>
          <p className="text-sm text-muted-foreground">Suivi quotidien des présences, absences et retards</p>
        </div>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Faire l'appel</CardTitle>
          <CardDescription>Sélectionnez une classe et une date, puis marquez le statut de chaque élève.</CardDescription>
        </CardHeader>
        <CardContent className="flex flex-col gap-4">
          <div className="flex flex-wrap items-end gap-3">
            <select
              className="h-10 rounded-md border border-border bg-background px-3 text-sm"
              value={classroomId}
              onChange={(e) => setClassroomId(e.target.value)}
            >
              <option value="">Choisir une classe…</option>
              {classrooms.map((c) => (
                <option key={c.id} value={c.id}>{c.name}</option>
              ))}
            </select>
            <input
              type="date"
              className="h-10 rounded-md border border-border bg-background px-3 text-sm"
              value={date}
              onChange={(e) => setDate(e.target.value)}
            />
            <Button disabled={!classroomId || students.length === 0 || isSubmitting} onClick={handleSubmit}>
              {isSubmitting ? "Enregistrement…" : "Enregistrer l'appel"}
            </Button>
          </div>

          {error ? <p className="text-sm font-medium text-destructive">{error}</p> : null}
          {successMessage ? <p className="text-sm font-medium text-emerald-600">{successMessage}</p> : null}

          {!classroomId ? (
            <p className="text-sm text-muted-foreground">Choisissez une classe pour afficher la liste des élèves.</p>
          ) : isLoadingStudents ? (
            <LoadingSpinner label="Chargement des élèves…" />
          ) : students.length === 0 ? (
            <p className="text-sm text-muted-foreground">Aucun élève inscrit dans cette classe.</p>
          ) : (
            <div className="overflow-x-auto rounded-md border border-border">
              <table className="w-full text-sm">
                <thead className="bg-secondary/60 text-left">
                  <tr>
                    <th className="px-4 py-2 font-medium text-secondary-foreground">Élève</th>
                    <th className="px-4 py-2 font-medium text-secondary-foreground">Statut</th>
                  </tr>
                </thead>
                <tbody>
                  {students.map((student) => (
                    <tr key={student.id} className="border-t border-border">
                      <td className="px-4 py-2">{student.lastName} {student.firstName}</td>
                      <td className="px-4 py-2">
                        <select
                          className="h-9 rounded-md border border-border bg-background px-2 text-sm"
                          value={statuses[student.id] ?? "PRESENT"}
                          onChange={(e) =>
                            setStatuses((prev) => ({ ...prev, [student.id]: e.target.value as AttendanceStatus }))
                          }
                        >
                          {(Object.keys(STATUS_LABELS) as AttendanceStatus[]).map((status) => (
                            <option key={status} value={status}>{STATUS_LABELS[status]}</option>
                          ))}
                        </select>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Registre des présences</CardTitle>
          <CardDescription>Historique des appels enregistrés pour la classe sélectionnée</CardDescription>
        </CardHeader>
        <CardContent>
          {!classroomId ? (
            <div className="flex flex-col items-center justify-center py-12 text-center">
              <UserCheck className="h-12 w-12 text-muted-foreground mb-4" />
              <p className="text-sm text-muted-foreground max-w-md">
                Sélectionnez une classe ci-dessus pour consulter son registre de présences.
              </p>
            </div>
          ) : isLoadingHistory ? (
            <LoadingSpinner label="Chargement…" />
          ) : history.length === 0 ? (
            <p className="py-6 text-center text-sm text-muted-foreground">Aucun enregistrement de présence pour le moment</p>
          ) : (
            <div className="overflow-x-auto rounded-md border border-border">
              <table className="w-full text-sm">
                <thead className="bg-secondary/60 text-left">
                  <tr>
                    <th className="px-4 py-2 font-medium text-secondary-foreground">Date</th>
                    <th className="px-4 py-2 font-medium text-secondary-foreground">Élève</th>
                    <th className="px-4 py-2 font-medium text-secondary-foreground">Statut</th>
                    <th className="px-4 py-2 font-medium text-secondary-foreground">Motif</th>
                  </tr>
                </thead>
                <tbody>
                  {history.map((record) => (
                    <tr key={record.id} className="border-t border-border">
                      <td className="px-4 py-2">{new Date(record.date).toLocaleDateString("fr-FR")}</td>
                      <td className="px-4 py-2">
                        {record.student ? `${record.student.lastName} ${record.student.firstName}` : "—"}
                      </td>
                      <td className="px-4 py-2">{STATUS_LABELS[record.status]}</td>
                      <td className="px-4 py-2">{record.reason ?? "—"}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
