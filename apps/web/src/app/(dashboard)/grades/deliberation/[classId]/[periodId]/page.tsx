"use client";

import { useEffect, useState } from "react";
import { useParams } from "next/navigation";
import { resourceClient } from "@/lib/api-client";
import { DELIBERATION_DECISION_LABELS, DELIBERATION_DECISIONS, type PeriodResult } from "@/types/grades";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from "@/components/ui/card";
import { LoadingSpinner } from "@/components/shared/loading-spinner";

interface RowState {
  studentId: string;
  decision: string;
  observations: string;
  teacherComment: string;
}

export default function DeliberationPage() {
  const params = useParams<{ classId: string; periodId: string }>();
  const { classId, periodId } = params;

  const [periodResults, setPeriodResults] = useState<PeriodResult[]>([]);
  const [rows, setRows] = useState<Record<string, RowState>>({});
  const [isLoading, setIsLoading] = useState(true);
  const [isSaving, setIsSaving] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    setIsLoading(true);
    resourceClient
      .get(`/grades/results/${classId}/${periodId}`)
      .then(({ data }) => {
        const results: PeriodResult[] = data.periodResults ?? [];
        setPeriodResults(results);
        setRows(
          Object.fromEntries(
            results.map((r) => [
              r.studentId,
              { studentId: r.studentId, decision: r.decision ?? "", observations: r.observations ?? "", teacherComment: r.teacherComment ?? "" },
            ]),
          ),
        );
      })
      .finally(() => setIsLoading(false));
  }, [classId, periodId]);

  function updateRow(studentId: string, patch: Partial<RowState>) {
    setRows((prev) => ({ ...prev, [studentId]: { ...prev[studentId], ...patch } }));
  }

  async function onSave() {
    setIsSaving(true);
    setMessage(null);
    setError(null);
    try {
      const entries = Object.values(rows)
        .filter((r) => r.decision || r.observations || r.teacherComment)
        .map((r) => ({
          studentId: r.studentId,
          decision: r.decision || undefined,
          observations: r.observations || undefined,
          teacherComment: r.teacherComment || undefined,
        }));
      if (entries.length === 0) {
        setError("Renseignez au moins une décision ou observation.");
        return;
      }
      await resourceClient.post(`/grades/deliberation/${classId}/${periodId}`, { entries });
      setMessage("Délibération enregistrée.");
    } catch (submitError: any) {
      setError(submitError.response?.data?.message ?? "Impossible d'enregistrer la délibération.");
    } finally {
      setIsSaving(false);
    }
  }

  if (isLoading) {
    return <LoadingSpinner label="Chargement des résultats…" />;
  }

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-2xl font-semibold">Délibération</h1>
        <p className="text-sm text-muted-foreground">Décision, observations et appréciation par élève pour cette période.</p>
      </div>

      {periodResults.length === 0 ? (
        <p className="text-sm text-muted-foreground">Aucun résultat calculé — lancez d'abord le calcul depuis la page Résultats.</p>
      ) : (
        <Card>
          <CardHeader>
            <CardTitle>Conseil de classe</CardTitle>
            <CardDescription>Triés par rang. Les champs laissés vides ne modifient pas la valeur existante.</CardDescription>
          </CardHeader>
          <CardContent className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-secondary/60 text-left">
                <tr>
                  <th className="px-4 py-2 font-medium">Rang</th>
                  <th className="px-4 py-2 font-medium">Élève</th>
                  <th className="px-4 py-2 font-medium">Moyenne</th>
                  <th className="px-4 py-2 font-medium">Décision</th>
                  <th className="px-4 py-2 font-medium">Observations</th>
                  <th className="px-4 py-2 font-medium">Appréciation</th>
                </tr>
              </thead>
              <tbody>
                {periodResults.map((result) => {
                  const row = rows[result.studentId];
                  return (
                    <tr key={result.id} className="border-t border-border">
                      <td className="px-4 py-2">{result.rank ?? "—"}</td>
                      <td className="px-4 py-2">
                        {result.student ? `${result.student.lastName} ${result.student.firstName}` : result.studentId}
                      </td>
                      <td className="px-4 py-2 font-medium">{Number(result.generalAverage).toFixed(2)} / 20</td>
                      <td className="px-4 py-2">
                        <select
                          className="h-9 rounded-md border border-border bg-background px-2 text-sm"
                          value={row?.decision ?? ""}
                          onChange={(e) => updateRow(result.studentId, { decision: e.target.value })}
                        >
                          <option value="">—</option>
                          {DELIBERATION_DECISIONS.map((decision) => (
                            <option key={decision} value={decision}>
                              {DELIBERATION_DECISION_LABELS[decision]}
                            </option>
                          ))}
                        </select>
                      </td>
                      <td className="px-4 py-2">
                        <input
                          className="h-9 w-40 rounded-md border border-border bg-background px-2 text-sm"
                          value={row?.observations ?? ""}
                          onChange={(e) => updateRow(result.studentId, { observations: e.target.value })}
                        />
                      </td>
                      <td className="px-4 py-2">
                        <input
                          className="h-9 w-40 rounded-md border border-border bg-background px-2 text-sm"
                          value={row?.teacherComment ?? ""}
                          onChange={(e) => updateRow(result.studentId, { teacherComment: e.target.value })}
                        />
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </CardContent>
        </Card>
      )}

      {message ? <p className="text-sm font-medium text-primary">{message}</p> : null}
      {error ? <p className="text-sm font-medium text-destructive">{error}</p> : null}

      {periodResults.length > 0 ? (
        <Button className="w-fit" disabled={isSaving} onClick={onSave}>
          {isSaving ? "Enregistrement…" : "Enregistrer la délibération"}
        </Button>
      ) : null}
    </div>
  );
}
