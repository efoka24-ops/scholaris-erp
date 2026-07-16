"use client";

import { useEffect, useState } from "react";
import { useParams } from "next/navigation";
import { resourceClient } from "@/lib/api-client";
import {
  INCIDENT_TYPE_LABELS,
  SANCTION_TYPES,
  SANCTION_TYPE_LABELS,
  SEVERITY_LABELS,
  type CreateSanctionInput,
  type Incident,
  type IncidentType,
  type SanctionType,
} from "@/types/discipline";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { LoadingSpinner } from "@/components/shared/loading-spinner";

export default function IncidentDetailPage() {
  const params = useParams<{ id: string }>();
  const [incident, setIncident] = useState<Incident | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [sanctionValues, setSanctionValues] = useState<Omit<CreateSanctionInput, "incidentId">>({
    type: SANCTION_TYPES[0],
    description: "",
    startDate: "",
    endDate: "",
  });
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [sanctionError, setSanctionError] = useState<string | null>(null);

  function load() {
    resourceClient
      .get<Incident>(`/discipline/incidents/${params.id}`)
      .then((response) => setIncident(response.data))
      .catch((requestError: any) =>
        setError(requestError.response?.data?.message ?? "Impossible de charger l'incident."),
      );
  }

  useEffect(() => {
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [params.id]);

  async function onSubmitSanction(event: React.FormEvent) {
    event.preventDefault();
    setSanctionError(null);
    if (!incident || !sanctionValues.description) {
      setSanctionError("La description de la sanction est obligatoire.");
      return;
    }
    setIsSubmitting(true);
    try {
      await resourceClient.post("/discipline/sanctions", { ...sanctionValues, incidentId: incident.id });
      setSanctionValues({ type: SANCTION_TYPES[0], description: "", startDate: "", endDate: "" });
      load();
    } catch (requestError: any) {
      setSanctionError(requestError.response?.data?.message ?? "Impossible d'enregistrer la sanction.");
    } finally {
      setIsSubmitting(false);
    }
  }

  if (error) return <p className="text-sm font-medium text-destructive">{error}</p>;
  if (!incident) return <LoadingSpinner label="Chargement…" />;

  return (
    <div className="flex flex-col gap-6">
      <Card>
        <CardHeader>
          <CardTitle>
            Incident du {new Date(incident.incidentDate).toLocaleDateString("fr-FR")}
          </CardTitle>
          <CardDescription>
            {incident.student ? `${incident.student.lastName} ${incident.student.firstName} (${incident.student.matricule})` : incident.studentId}
          </CardDescription>
        </CardHeader>
        <CardContent className="flex flex-col gap-2 text-sm">
          <p>
            <span className="font-medium">Type : </span>
            {INCIDENT_TYPE_LABELS[incident.type as IncidentType] ?? incident.type}
          </p>
          <p>
            <span className="font-medium">Gravité : </span>
            {SEVERITY_LABELS[incident.severity]}
          </p>
          {incident.location ? (
            <p>
              <span className="font-medium">Lieu : </span>
              {incident.location}
            </p>
          ) : null}
          <p>
            <span className="font-medium">Description : </span>
            {incident.description}
          </p>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Sanctions</CardTitle>
          <CardDescription>Sanctions liées à cet incident</CardDescription>
        </CardHeader>
        <CardContent className="flex flex-col gap-4">
          {incident.sanctions && incident.sanctions.length > 0 ? (
            <ul className="flex flex-col gap-2">
              {incident.sanctions.map((sanction) => (
                <li key={sanction.id} className="rounded-md border border-border p-3 text-sm">
                  <span className="font-medium">
                    {SANCTION_TYPE_LABELS[sanction.type as SanctionType] ?? sanction.type}
                  </span>{" "}
                  — {sanction.description}
                  {sanction.startDate ? (
                    <span className="text-muted-foreground">
                      {" "}
                      ({new Date(sanction.startDate).toLocaleDateString("fr-FR")}
                      {sanction.endDate ? ` – ${new Date(sanction.endDate).toLocaleDateString("fr-FR")}` : ""})
                    </span>
                  ) : null}
                </li>
              ))}
            </ul>
          ) : (
            <p className="text-sm text-muted-foreground">Aucune sanction enregistrée pour cet incident.</p>
          )}

          <form onSubmit={onSubmitSanction} className="flex flex-col gap-3 border-t border-border pt-4" noValidate>
            <span className="text-sm font-medium">Ajouter une sanction</span>
            <div className="grid grid-cols-2 gap-3">
              <select
                className="h-10 rounded-md border border-border bg-background px-3 text-sm"
                value={sanctionValues.type}
                onChange={(e) => setSanctionValues((prev) => ({ ...prev, type: e.target.value as SanctionType }))}
              >
                {SANCTION_TYPES.map((type) => (
                  <option key={type} value={type}>
                    {SANCTION_TYPE_LABELS[type]}
                  </option>
                ))}
              </select>
              <div />
              <Input
                type="date"
                placeholder="Date de début"
                value={sanctionValues.startDate}
                onChange={(e) => setSanctionValues((prev) => ({ ...prev, startDate: e.target.value }))}
              />
              <Input
                type="date"
                placeholder="Date de fin"
                value={sanctionValues.endDate}
                onChange={(e) => setSanctionValues((prev) => ({ ...prev, endDate: e.target.value }))}
              />
            </div>
            <textarea
              rows={3}
              className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
              placeholder="Description de la sanction"
              value={sanctionValues.description}
              onChange={(e) => setSanctionValues((prev) => ({ ...prev, description: e.target.value }))}
            />
            {sanctionError ? <p className="text-sm font-medium text-destructive">{sanctionError}</p> : null}
            <Button type="submit" disabled={isSubmitting} className="self-start">
              {isSubmitting ? "Enregistrement…" : "Ajouter la sanction"}
            </Button>
          </form>
        </CardContent>
      </Card>
    </div>
  );
}
