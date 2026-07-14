"use client";

import { useCallback, useEffect, useState } from "react";
import type { ColumnDef } from "@tanstack/react-table";
import { Plus } from "lucide-react";
import type { PaginationMeta } from "@scholaris/shared";
import { resourceClient } from "@/lib/api-client";
import type { ClassRoom } from "@/types/structure";
import type {
  AcademicYearOption,
  CourseElement,
  Subject,
  SubjectAssignment,
  TeacherOption,
} from "@/types/subjects";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { DataTable } from "@/components/shared/data-table";

type AssignmentKind = "subject" | "courseElement";

export default function AssignmentsPage() {
  const [assignments, setAssignments] = useState<SubjectAssignment[]>([]);
  const [classrooms, setClassrooms] = useState<ClassRoom[]>([]);
  const [subjects, setSubjects] = useState<Subject[]>([]);
  const [courseElements, setCourseElements] = useState<CourseElement[]>([]);
  const [teachers, setTeachers] = useState<TeacherOption[]>([]);
  const [academicYears, setAcademicYears] = useState<AcademicYearOption[]>([]);
  const [isLoading, setIsLoading] = useState(true);

  const [classroomFilter, setClassroomFilter] = useState("");
  const [showForm, setShowForm] = useState(false);
  const [kind, setKind] = useState<AssignmentKind>("subject");
  const [subjectId, setSubjectId] = useState("");
  const [courseElementId, setCourseElementId] = useState("");
  const [teacherId, setTeacherId] = useState("");
  const [classroomId, setClassroomId] = useState("");
  const [academicYearId, setAcademicYearId] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);

  const loadAssignments = useCallback(() => {
    setIsLoading(true);
    resourceClient
      .get<SubjectAssignment[]>("/subject-assignments", {
        params: classroomFilter ? { classroomId: classroomFilter } : {},
      })
      .then((response) => setAssignments(response.data))
      .catch((requestError: any) =>
        setError(requestError.response?.data?.message ?? "Impossible de charger les assignations."),
      )
      .finally(() => setIsLoading(false));
  }, [classroomFilter]);

  useEffect(() => {
    loadAssignments();
  }, [loadAssignments]);

  useEffect(() => {
    resourceClient.get<ClassRoom[]>("/classrooms").then((response) => setClassrooms(response.data));
    resourceClient
      .get<{ data: Subject[]; meta: PaginationMeta }>("/subjects", { params: { limit: 100 } })
      .then((response) => setSubjects(response.data.data));
    resourceClient.get<CourseElement[]>("/course-elements").then((response) => setCourseElements(response.data));
    resourceClient.get<TeacherOption[]>("/subject-assignments/teachers").then((response) => setTeachers(response.data));
    resourceClient
      .get<AcademicYearOption[]>("/subject-assignments/academic-years")
      .then((response) => setAcademicYears(response.data));
  }, []);

  async function handleCreate() {
    setError(null);
    setIsSubmitting(true);
    try {
      await resourceClient.post("/subject-assignments", {
        ...(kind === "subject" ? { subjectId } : { courseElementId }),
        teacherId,
        classroomId,
        academicYearId,
      });
      setSubjectId("");
      setCourseElementId("");
      setTeacherId("");
      setShowForm(false);
      loadAssignments();
    } catch (requestError: any) {
      setError(requestError.response?.data?.message ?? "Impossible de créer l'assignation.");
    } finally {
      setIsSubmitting(false);
    }
  }

  const canSubmit =
    Boolean(teacherId) &&
    Boolean(classroomId) &&
    Boolean(academicYearId) &&
    (kind === "subject" ? Boolean(subjectId) : Boolean(courseElementId));

  const columns: ColumnDef<SubjectAssignment>[] = [
    {
      id: "discipline",
      header: "Matière / EC",
      cell: ({ row }) => {
        const target = row.original.subject ?? row.original.courseElement;
        return target ? `${target.code} — ${target.name}` : "—";
      },
    },
    {
      id: "type",
      header: "Type",
      cell: ({ row }) => (row.original.subjectId ? "Matière" : "EC"),
    },
    {
      id: "teacher",
      header: "Enseignant",
      cell: ({ row }) => `${row.original.teacher.firstName} ${row.original.teacher.lastName}`,
    },
    {
      id: "classroom",
      header: "Classe",
      cell: ({ row }) => row.original.classroom.name,
    },
    {
      id: "year",
      header: "Année académique",
      cell: ({ row }) => row.original.academicYear.label,
    },
  ];

  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">Assignations enseignants</h1>
          <p className="text-sm text-muted-foreground">
            Qui enseigne quelle matière (ou quel EC) dans quelle classe, pour quelle année académique
          </p>
        </div>
        <Button size="sm" onClick={() => setShowForm((value) => !value)}>
          <Plus className="mr-2 h-4 w-4" />
          Nouvelle assignation
        </Button>
      </div>

      {error ? <p className="text-sm font-medium text-destructive">{error}</p> : null}

      {showForm ? (
        <Card>
          <CardHeader>
            <CardTitle>Nouvelle assignation</CardTitle>
            <CardDescription>
              Une même matière ne peut être assignée qu'une fois par classe et par année académique.
            </CardDescription>
          </CardHeader>
          <CardContent className="flex flex-col gap-3">
            <div className="flex gap-4 text-sm">
              <label className="flex items-center gap-2">
                <input type="radio" checked={kind === "subject"} onChange={() => setKind("subject")} />
                Matière (secondaire)
              </label>
              <label className="flex items-center gap-2">
                <input type="radio" checked={kind === "courseElement"} onChange={() => setKind("courseElement")} />
                EC (supérieur LMD)
              </label>
            </div>

            <div className="grid grid-cols-2 gap-3">
              {kind === "subject" ? (
                <select
                  className="h-10 rounded-md border border-border bg-background px-3 text-sm"
                  value={subjectId}
                  onChange={(event) => setSubjectId(event.target.value)}
                >
                  <option value="">Matière…</option>
                  {subjects.map((subject) => (
                    <option key={subject.id} value={subject.id}>
                      {subject.code} — {subject.name}
                    </option>
                  ))}
                </select>
              ) : (
                <select
                  className="h-10 rounded-md border border-border bg-background px-3 text-sm"
                  value={courseElementId}
                  onChange={(event) => setCourseElementId(event.target.value)}
                >
                  <option value="">Élément constitutif…</option>
                  {courseElements.map((element) => (
                    <option key={element.id} value={element.id}>
                      {element.code} — {element.name}
                    </option>
                  ))}
                </select>
              )}

              <select
                className="h-10 rounded-md border border-border bg-background px-3 text-sm"
                value={classroomId}
                onChange={(event) => setClassroomId(event.target.value)}
              >
                <option value="">Classe…</option>
                {classrooms.map((classroom) => (
                  <option key={classroom.id} value={classroom.id}>
                    {classroom.name}
                  </option>
                ))}
              </select>

              <select
                className="h-10 rounded-md border border-border bg-background px-3 text-sm"
                value={teacherId}
                onChange={(event) => setTeacherId(event.target.value)}
              >
                <option value="">Enseignant…</option>
                {teachers.map((teacher) => (
                  <option key={teacher.id} value={teacher.id}>
                    {teacher.lastName} {teacher.firstName} ({teacher.email})
                  </option>
                ))}
              </select>

              <select
                className="h-10 rounded-md border border-border bg-background px-3 text-sm"
                value={academicYearId}
                onChange={(event) => setAcademicYearId(event.target.value)}
              >
                <option value="">Année académique…</option>
                {academicYears.map((year) => (
                  <option key={year.id} value={year.id}>
                    {year.label}
                  </option>
                ))}
              </select>
            </div>

            <Button className="self-start" disabled={isSubmitting || !canSubmit} onClick={handleCreate}>
              {isSubmitting ? "Assignation…" : "Assigner"}
            </Button>
          </CardContent>
        </Card>
      ) : null}

      <div className="flex items-center gap-2">
        <label htmlFor="classroom-filter" className="text-sm text-muted-foreground">
          Filtrer par classe
        </label>
        <select
          id="classroom-filter"
          className="h-9 rounded-md border border-border bg-background px-2 text-sm"
          value={classroomFilter}
          onChange={(event) => setClassroomFilter(event.target.value)}
        >
          <option value="">Toutes les classes</option>
          {classrooms.map((classroom) => (
            <option key={classroom.id} value={classroom.id}>
              {classroom.name}
            </option>
          ))}
        </select>
      </div>

      <DataTable
        columns={columns}
        data={assignments}
        isLoading={isLoading}
        emptyLabel="Aucune assignation pour ce filtre"
      />
    </div>
  );
}
