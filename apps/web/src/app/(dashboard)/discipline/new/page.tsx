"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import type { PaginatedResult } from "@scholaris/shared";
import { resourceClient } from "@/lib/api-client";
import type { Student } from "@/types/students";
import {
  INCIDENT_TYPES,
  INCIDENT_TYPE_LABELS,
  SEVERITY_LABELS,
  type CreateIncidentInput,
  type IncidentSeverity,
  type IncidentType,
} from "@/types/discipline";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";

export default function NewIncidentPage() {
  const router = useRouter();
  const [students, setStudents] = useState<Student[]>([]);
  const [values, setValues] = useState<CreateIncidentInput>({
    studentId: "",
    incidentDate: new Date().toISOString().slice(0, 10),
    type: INCIDENT_TYPES[0],
    severity: "LOW",
    description: "",
    location: "",
  });
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [serverError, setServerError] = useState<string | null>(null);

  useEffect(() => {
    resourceClient
      .get<PaginatedResult<Student>>("/students", { params: { limit: 200 } })
      .then((response) => setStudents(response.data.data));
  }, []);

  function update<K extends keyof CreateIncidentInput>(key: K, value: CreateIncidentInput[K]) {
    setValues((prev) => ({ ...prev, [key]: value }));
  }

  async function onSubmit(event: React.FormEvent) {
    event.preventDefault();
    setServerError(null);
    if (!values.studentId || !values.description) {
      setServerError("L'élève et la description sont obligatoires.");
      return;
    }
    setIsSubmitting(true);
    try {
      const { data } = await resourceClient.post<{ id: string }>("/discipline/incidents", values);
      router.push(`/discipline/${data.id}`);
      router.refresh();
    } catch (error: any) {
      setServerError(error.response?.data?.message ?? "Impossible d'enregistrer l'incident.");
    } finally {
      setIsSubmitting(false);
    }
  }

  return (
    <Card className="max-w-xl">
      <CardHeader>
        <CardTitle>Signaler un incident</CardTitle>
        <CardDescription>Enregistrement d'un incident disciplinaire concernant un élève.</CardDescription>
      </CardHeader>
      <CardContent>
        <form onSubmit={onSubmit} className="flex flex-col gap-4" noValidate>
          <div className="flex flex-col gap-1.5">
            <label className="text-sm font-medium" htmlFor="studentId">
              Élève
            </label>
            <select
              id="studentId"
              className="h-10 w-full rounded-md border border-border bg-background px-3 text-sm"
              value={values.studentId}
              onChange={(e) => update("studentId", e.target.value)}
            >
              <option value="">Sélectionner un élève…</option>
              {students.map((student) => (
                <option key={student.id} value={student.id}>
                  {student.lastName} {student.firstName} — {student.matricule}
                </option>
              ))}
            </select>
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div className="flex flex-col gap-1.5">
              <label className="text-sm font-medium" htmlFor="incidentDate">
                Date de l'incident
              </label>
              <Input
                id="incidentDate"
                type="date"
                value={values.incidentDate}
                onChange={(e) => update("incidentDate", e.target.value)}
              />
            </div>
            <div className="flex flex-col gap-1.5">
              <label className="text-sm font-medium" htmlFor="location">
                Lieu (optionnel)
              </label>
              <Input id="location" placeholder="Cour, salle 12…" value={values.location} onChange={(e) => update("location", e.target.value)} />
            </div>
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div className="flex flex-col gap-1.5">
              <label className="text-sm font-medium" htmlFor="type">
                Type d'incident
              </label>
              <select
                id="type"
                className="h-10 w-full rounded-md border border-border bg-background px-3 text-sm"
                value={values.type}
                onChange={(e) => update("type", e.target.value as IncidentType)}
              >
                {INCIDENT_TYPES.map((type) => (
                  <option key={type} value={type}>
                    {INCIDENT_TYPE_LABELS[type]}
                  </option>
                ))}
              </select>
            </div>
            <div className="flex flex-col gap-1.5">
              <label className="text-sm font-medium" htmlFor="severity">
                Gravité
              </label>
              <select
                id="severity"
                className="h-10 w-full rounded-md border border-border bg-background px-3 text-sm"
                value={values.severity}
                onChange={(e) => update("severity", e.target.value as IncidentSeverity)}
              >
                {(Object.keys(SEVERITY_LABELS) as IncidentSeverity[]).map((severity) => (
                  <option key={severity} value={severity}>
                    {SEVERITY_LABELS[severity]}
                  </option>
                ))}
              </select>
            </div>
          </div>

          <div className="flex flex-col gap-1.5">
            <label className="text-sm font-medium" htmlFor="description">
              Description
            </label>
            <textarea
              id="description"
              rows={4}
              className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
              value={values.description}
              onChange={(e) => update("description", e.target.value)}
            />
          </div>

          {serverError ? <p className="text-sm font-medium text-destructive">{serverError}</p> : null}
          <Button type="submit" disabled={isSubmitting}>
            {isSubmitting ? "Enregistrement…" : "Enregistrer l'incident"}
          </Button>
        </form>
      </CardContent>
    </Card>
  );
}
