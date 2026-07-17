"use client";

import { useCallback, useEffect, useState } from "react";
import type { ColumnDef } from "@tanstack/react-table";
import { Plus } from "lucide-react";
import { resourceClient } from "@/lib/api-client";
import type { ClassRoom, Room } from "@/types/structure";
import type { AcademicYearOption, Subject, TeacherOption } from "@/types/subjects";
import type { PaginationMeta } from "@scholaris/shared";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { DataTable } from "@/components/shared/data-table";

interface TimetableSlot {
  id: string;
  dayOfWeek: string;
  startTime: string;
  endTime: string;
  classroom?: { name: string };
  subject?: { name: string };
  teacher?: { firstName: string; lastName: string };
  room?: { name: string };
}

const DAY_LABELS: Record<string, string> = {
  MONDAY: "Lundi",
  TUESDAY: "Mardi",
  WEDNESDAY: "Mercredi",
  THURSDAY: "Jeudi",
  FRIDAY: "Vendredi",
  SATURDAY: "Samedi",
  SUNDAY: "Dimanche",
};

const DAY_OPTIONS = [
  { value: 1, label: "Lundi" },
  { value: 2, label: "Mardi" },
  { value: 3, label: "Mercredi" },
  { value: 4, label: "Jeudi" },
  { value: 5, label: "Vendredi" },
  { value: 6, label: "Samedi" },
  { value: 7, label: "Dimanche" },
];

export default function TimetablesPage() {
  const [slots, setSlots] = useState<TimetableSlot[]>([]);
  const [meta, setMeta] = useState<PaginationMeta | undefined>();
  const [page, setPage] = useState(1);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const [classrooms, setClassrooms] = useState<ClassRoom[]>([]);
  const [subjects, setSubjects] = useState<Subject[]>([]);
  const [rooms, setRooms] = useState<Room[]>([]);
  const [teachers, setTeachers] = useState<TeacherOption[]>([]);
  const [academicYears, setAcademicYears] = useState<AcademicYearOption[]>([]);
  const [classroomFilter, setClassroomFilter] = useState("");

  const [showForm, setShowForm] = useState(false);
  const [classroomId, setClassroomId] = useState("");
  const [subjectId, setSubjectId] = useState("");
  const [teacherId, setTeacherId] = useState("");
  const [roomId, setRoomId] = useState("");
  const [academicYearId, setAcademicYearId] = useState("");
  const [dayOfWeek, setDayOfWeek] = useState(1);
  const [startTime, setStartTime] = useState("");
  const [endTime, setEndTime] = useState("");
  const [isSubmitting, setIsSubmitting] = useState(false);

  const load = useCallback(() => {
    setIsLoading(true);
    resourceClient
      .get<{ items: TimetableSlot[]; total: number; page: number; limit: number; totalPages: number }>(
        "/timetables",
        { params: { page, limit: 20, ...(classroomFilter ? { classroomId: classroomFilter } : {}) } },
      )
      .then((response) => {
        setSlots(response.data.items);
        setMeta({
          page: response.data.page,
          total: response.data.total,
          limit: response.data.limit,
          totalPages: response.data.totalPages,
        });
      })
      .catch((requestError: any) =>
        setError(requestError.response?.data?.message ?? "Impossible de charger les emplois du temps."),
      )
      .finally(() => setIsLoading(false));
  }, [page, classroomFilter]);

  useEffect(() => {
    load();
  }, [load]);

  useEffect(() => {
    resourceClient.get<ClassRoom[]>("/classrooms").then((response) => setClassrooms(response.data));
    resourceClient
      .get<{ data: Subject[]; meta: PaginationMeta }>("/subjects", { params: { limit: 100 } })
      .then((response) => setSubjects(response.data.data));
    resourceClient.get<Room[]>("/rooms").then((response) => setRooms(response.data));
    resourceClient.get<TeacherOption[]>("/subject-assignments/teachers").then((response) => setTeachers(response.data));
    resourceClient
      .get<AcademicYearOption[]>("/subject-assignments/academic-years")
      .then((response) => setAcademicYears(response.data));
  }, []);

  async function handleCreate() {
    setError(null);
    setIsSubmitting(true);
    try {
      await resourceClient.post("/timetables", {
        classroomId,
        subjectId,
        ...(teacherId ? { teacherId } : {}),
        roomId,
        academicYearId,
        dayOfWeek,
        startTime,
        endTime,
      });
      setSubjectId("");
      setTeacherId("");
      setRoomId("");
      setStartTime("");
      setEndTime("");
      setShowForm(false);
      load();
    } catch (requestError: any) {
      setError(requestError.response?.data?.message ?? "Impossible de créer le créneau.");
    } finally {
      setIsSubmitting(false);
    }
  }

  const canSubmit = Boolean(classroomId && subjectId && roomId && academicYearId && startTime && endTime);

  const columns: ColumnDef<TimetableSlot>[] = [
    { id: "day", header: "Jour", cell: ({ row }) => DAY_LABELS[row.original.dayOfWeek] ?? row.original.dayOfWeek },
    { id: "time", header: "Horaire", cell: ({ row }) => `${row.original.startTime} – ${row.original.endTime}` },
    { id: "classroom", header: "Classe", cell: ({ row }) => row.original.classroom?.name ?? "—" },
    { id: "subject", header: "Matière", cell: ({ row }) => row.original.subject?.name ?? "—" },
    {
      id: "teacher",
      header: "Enseignant",
      cell: ({ row }) =>
        row.original.teacher ? `${row.original.teacher.firstName} ${row.original.teacher.lastName}` : "—",
    },
    { id: "room", header: "Salle", cell: ({ row }) => row.original.room?.name ?? "—" },
  ];

  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">Emplois du temps</h1>
          <p className="text-sm text-muted-foreground">
            Gestion et consultation des emplois du temps des classes et enseignants
          </p>
        </div>
        <Button onClick={() => setShowForm((value) => !value)}>
          <Plus className="mr-2 h-4 w-4" />
          Nouveau créneau
        </Button>
      </div>

      {showForm ? (
        <Card>
          <CardHeader>
            <CardTitle className="text-base">Créer un créneau</CardTitle>
            <CardDescription>La détection des conflits de salle et de professeur est appliquée côté serveur.</CardDescription>
          </CardHeader>
          <CardContent className="flex flex-col gap-3">
            <div className="flex flex-wrap items-end gap-3">
              <select className="h-10 rounded-md border border-border bg-background px-3 text-sm" value={classroomId} onChange={(e) => setClassroomId(e.target.value)}>
                <option value="">Classe…</option>
                {classrooms.map((c) => (
                  <option key={c.id} value={c.id}>{c.name}</option>
                ))}
              </select>
              <select className="h-10 rounded-md border border-border bg-background px-3 text-sm" value={subjectId} onChange={(e) => setSubjectId(e.target.value)}>
                <option value="">Matière…</option>
                {subjects.map((s) => (
                  <option key={s.id} value={s.id}>{s.name}</option>
                ))}
              </select>
              <select className="h-10 rounded-md border border-border bg-background px-3 text-sm" value={teacherId} onChange={(e) => setTeacherId(e.target.value)}>
                <option value="">Enseignant (optionnel)…</option>
                {teachers.map((t) => (
                  <option key={t.id} value={t.id}>{t.firstName} {t.lastName}</option>
                ))}
              </select>
              <select className="h-10 rounded-md border border-border bg-background px-3 text-sm" value={roomId} onChange={(e) => setRoomId(e.target.value)}>
                <option value="">Salle…</option>
                {rooms.map((r) => (
                  <option key={r.id} value={r.id}>{r.name}</option>
                ))}
              </select>
              <select className="h-10 rounded-md border border-border bg-background px-3 text-sm" value={academicYearId} onChange={(e) => setAcademicYearId(e.target.value)}>
                <option value="">Année académique…</option>
                {academicYears.map((y) => (
                  <option key={y.id} value={y.id}>{y.label}</option>
                ))}
              </select>
              <select className="h-10 rounded-md border border-border bg-background px-3 text-sm" value={dayOfWeek} onChange={(e) => setDayOfWeek(Number(e.target.value))}>
                {DAY_OPTIONS.map((d) => (
                  <option key={d.value} value={d.value}>{d.label}</option>
                ))}
              </select>
              <input type="time" className="h-10 rounded-md border border-border bg-background px-3 text-sm" value={startTime} onChange={(e) => setStartTime(e.target.value)} />
              <input type="time" className="h-10 rounded-md border border-border bg-background px-3 text-sm" value={endTime} onChange={(e) => setEndTime(e.target.value)} />
              <Button disabled={!canSubmit || isSubmitting} onClick={handleCreate}>
                {isSubmitting ? "Création…" : "Créer le créneau"}
              </Button>
            </div>
          </CardContent>
        </Card>
      ) : null}

      <div className="flex items-center gap-2">
        <label htmlFor="classroom-filter" className="text-sm text-muted-foreground">Filtrer par classe</label>
        <select
          id="classroom-filter"
          className="h-9 rounded-md border border-border bg-background px-2 text-sm"
          value={classroomFilter}
          onChange={(e) => {
            setPage(1);
            setClassroomFilter(e.target.value);
          }}
        >
          <option value="">Toutes les classes</option>
          {classrooms.map((c) => (
            <option key={c.id} value={c.id}>{c.name}</option>
          ))}
        </select>
      </div>

      {error ? <p className="text-sm font-medium text-destructive">{error}</p> : null}

      <DataTable
        columns={columns}
        data={slots}
        meta={meta}
        isLoading={isLoading}
        onPageChange={setPage}
        emptyLabel="Aucun créneau pour ce filtre"
      />
    </div>
  );
}
