"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import Link from "next/link";
import { Calculator } from "lucide-react";
import { resourceClient } from "@/lib/api-client";
import type { ClassRoom } from "@/types/structure";
import type { Period } from "@/types/settings";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";

export default function GradesCalculationsPage() {
  const router = useRouter();

  const [classrooms, setClassrooms] = useState<ClassRoom[]>([]);
  const [periods, setPeriods] = useState<Period[]>([]);
  const [classroomId, setClassroomId] = useState("");
  const [periodId, setPeriodId] = useState("");
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

  function handleView() {
    if (!classroomId || !periodId) return;
    router.push(`/grades/results/${classroomId}/${periodId}`);
  }

  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">Calculs et moyennes</h1>
          <p className="text-sm text-muted-foreground">Calculer les moyennes, classements et résultats par période</p>
        </div>
      </div>

      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Calculator className="h-5 w-5" />
            Moteur de calcul
          </CardTitle>
          <CardDescription>
            Sélectionnez une classe et une période pour accéder aux résultats (calcul, délibération, publication)
          </CardDescription>
        </CardHeader>
        <CardContent className="flex flex-col gap-4">
          <div className="grid gap-3 md:grid-cols-2">
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="calc-classroom">Classe</Label>
              <select
                id="calc-classroom"
                className="h-10 rounded-md border border-border bg-background px-3 text-sm"
                value={classroomId}
                onChange={(event) => setClassroomId(event.target.value)}
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
              <Label htmlFor="calc-period">Période</Label>
              <select
                id="calc-period"
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
            <Button disabled={!classroomId || !periodId} onClick={handleView}>
              Voir les résultats de la classe
            </Button>
            <Button asChild variant="outline">
              <Link href="/settings/calculation-engine">Voir le moteur de calcul</Link>
            </Button>
          </div>
        </CardContent>
      </Card>
    </div>
  );
}
