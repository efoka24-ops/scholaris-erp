"use client";

import { useCallback, useEffect, useState } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { resourceClient } from "@/lib/api-client";
import { useAuth } from "@/hooks/use-auth";
import type { GradeCalculation, PeriodResult } from "@/types/grades";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { LoadingSpinner } from "@/components/shared/loading-spinner";

export default function GradeResultsPage() {
  const params = useParams<{ classId: string; periodId: string }>();
  const { classId, periodId } = params;
  const { hasPermission } = useAuth();

  const [periodResults, setPeriodResults] = useState<PeriodResult[]>([]);
  const [gradeCalculations, setGradeCalculations] = useState<GradeCalculation[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [isActing, setIsActing] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(() => {
    setIsLoading(true);
    return resourceClient
      .get(`/grades/results/${classId}/${periodId}`)
      .then(({ data }) => {
        setPeriodResults(data.periodResults ?? []);
        setGradeCalculations(data.gradeCalculations ?? []);
      })
      .finally(() => setIsLoading(false));
  }, [classId, periodId]);

  useEffect(() => {
    load();
  }, [load]);

  async function runAction(action: () => Promise<unknown>, successMessage: string) {
    setIsActing(true);
    setMessage(null);
    setError(null);
    try {
      await action();
      setMessage(successMessage);
      await load();
    } catch (actionError: any) {
      setError(actionError.response?.data?.message ?? "Action impossible.");
    } finally {
      setIsActing(false);
    }
  }

  const subjectsByStudent = new Map<string, GradeCalculation[]>();
  for (const calc of gradeCalculations) {
    const list = subjectsByStudent.get(calc.studentId) ?? [];
    list.push(calc);
    subjectsByStudent.set(calc.studentId, list);
  }

  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">Résultats de la classe</h1>
          <p className="text-sm text-muted-foreground">Moyennes, classement et détail par matière pour cette période.</p>
        </div>
        <div className="flex gap-2">
          {hasPermission("grades:calculate") ? (
            <Button
              size="sm"
              variant="outline"
              disabled={isActing}
              onClick={() => runAction(() => resourceClient.post(`/grades/calculate/${classId}/${periodId}`), "Calcul effectué.")}
            >
              Calculer
            </Button>
          ) : null}
          {hasPermission("grades:deliberation") ? (
            <Button asChild size="sm" variant="outline">
              <Link href={`/grades/deliberation/${classId}/${periodId}`}>Délibérer</Link>
            </Button>
          ) : null}
          {hasPermission("grades:publish") ? (
            <Button
              size="sm"
              disabled={isActing}
              onClick={() => runAction(() => resourceClient.post(`/grades/publish/${classId}/${periodId}`), "Résultats publiés.")}
            >
              Publier
            </Button>
          ) : null}
        </div>
      </div>

      {message ? <p className="text-sm font-medium text-primary">{message}</p> : null}
      {error ? <p className="text-sm font-medium text-destructive">{error}</p> : null}

      {isLoading ? (
        <LoadingSpinner label="Chargement des résultats…" />
      ) : periodResults.length === 0 ? (
        <p className="text-sm text-muted-foreground">
          Aucun résultat calculé pour cette classe et cette période. Cliquez sur « Calculer » pour lancer le moteur de calcul.
        </p>
      ) : (
        <Card>
          <CardHeader>
            <CardTitle>Tableau récapitulatif</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="overflow-x-auto rounded-md border border-border">
              <table className="w-full text-sm">
                <thead className="bg-secondary/60 text-left">
                  <tr>
                    <th className="px-4 py-2 font-medium">Rang</th>
                    <th className="px-4 py-2 font-medium">Élève</th>
                    <th className="px-4 py-2 font-medium">Moyenne générale</th>
                    <th className="px-4 py-2 font-medium">Mention</th>
                    <th className="px-4 py-2 font-medium">Décision</th>
                    <th className="px-4 py-2 font-medium">Publié</th>
                    <th className="px-4 py-2 font-medium">Détail par matière</th>
                  </tr>
                </thead>
                <tbody>
                  {periodResults.map((result) => (
                    <tr key={result.id} className="border-t border-border">
                      <td className="px-4 py-2">{result.rank ?? "—"}</td>
                      <td className="px-4 py-2">
                        <Link href={`/grades/student/${result.studentId}`} className="text-primary hover:underline">
                          {result.student ? `${result.student.lastName} ${result.student.firstName}` : result.studentId}
                        </Link>
                      </td>
                      <td className="px-4 py-2 font-medium">{Number(result.generalAverage).toFixed(2)} / 20</td>
                      <td className="px-4 py-2">{result.mention ?? "—"}</td>
                      <td className="px-4 py-2">{result.decision ?? "—"}</td>
                      <td className="px-4 py-2">{result.isPublished ? "Oui" : "Non"}</td>
                      <td className="px-4 py-2 text-xs text-muted-foreground">
                        {(subjectsByStudent.get(result.studentId) ?? [])
                          .map((calc) => `${calc.subject?.name ?? calc.courseElement?.name ?? "—"}: ${Number(calc.calculatedAverage).toFixed(1)}`)
                          .join(" · ") || "—"}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </CardContent>
        </Card>
      )}
    </div>
  );
}
