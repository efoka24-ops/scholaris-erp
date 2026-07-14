"use client";

import { useEffect, useState } from "react";
import { useParams } from "next/navigation";
import { resourceClient } from "@/lib/api-client";
import { gradeColorClass, type StudentGradeHistory } from "@/types/grades";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { LoadingSpinner } from "@/components/shared/loading-spinner";

export default function StudentGradeHistoryPage() {
  const params = useParams<{ studentId: string }>();
  const [history, setHistory] = useState<StudentGradeHistory | null>(null);
  const [isLoading, setIsLoading] = useState(true);

  useEffect(() => {
    setIsLoading(true);
    resourceClient
      .get<StudentGradeHistory>(`/grades/student/${params.studentId}`)
      .then(({ data }) => setHistory(data))
      .finally(() => setIsLoading(false));
  }, [params.studentId]);

  if (isLoading) {
    return <LoadingSpinner label="Chargement de l'historique…" />;
  }
  if (!history) {
    return <p className="text-sm text-muted-foreground">Élève introuvable.</p>;
  }

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-2xl font-semibold">
          {history.student.lastName} {history.student.firstName}
        </h1>
        <p className="text-sm text-muted-foreground">Matricule {history.student.matricule} — historique des notes et résultats.</p>
      </div>

      {history.annualResults.length > 0 ? (
        <Card>
          <CardHeader>
            <CardTitle>Bilans annuels</CardTitle>
          </CardHeader>
          <CardContent className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="text-left text-muted-foreground">
                <tr>
                  <th className="py-1 font-medium">Moyenne annuelle</th>
                  <th className="py-1 font-medium">Rang</th>
                  <th className="py-1 font-medium">Mention</th>
                  <th className="py-1 font-medium">Décision</th>
                  <th className="py-1 font-medium">Crédits validés</th>
                  <th className="py-1 font-medium">GPA</th>
                </tr>
              </thead>
              <tbody>
                {history.annualResults.map((result) => (
                  <tr key={result.id} className="border-t border-border">
                    <td className="py-1.5 font-medium">{Number(result.annualAverage).toFixed(2)} / 20</td>
                    <td className="py-1.5">{result.rank ?? "—"}</td>
                    <td className="py-1.5">{result.mention ?? "—"}</td>
                    <td className="py-1.5">{result.decision ?? "—"}</td>
                    <td className="py-1.5">{result.creditsValidated ?? "—"}</td>
                    <td className="py-1.5">{result.gpa !== null ? Number(result.gpa).toFixed(2) : "—"}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </CardContent>
        </Card>
      ) : null}

      <Card>
        <CardHeader>
          <CardTitle>Résultats par période</CardTitle>
        </CardHeader>
        <CardContent className="overflow-x-auto">
          {history.periodResults.length === 0 ? (
            <p className="text-sm text-muted-foreground">Aucun résultat publié pour le moment.</p>
          ) : (
            <table className="w-full text-sm">
              <thead className="text-left text-muted-foreground">
                <tr>
                  <th className="py-1 font-medium">Période</th>
                  <th className="py-1 font-medium">Moyenne générale</th>
                  <th className="py-1 font-medium">Rang</th>
                  <th className="py-1 font-medium">Mention</th>
                  <th className="py-1 font-medium">Publié</th>
                </tr>
              </thead>
              <tbody>
                {history.periodResults.map((result) => (
                  <tr key={result.id} className="border-t border-border">
                    <td className="py-1.5">
                      {result.period ? `${result.period.type} ${result.period.number}` : result.periodId}
                    </td>
                    <td className="py-1.5 font-medium">{Number(result.generalAverage).toFixed(2)} / 20</td>
                    <td className="py-1.5">
                      {result.rank ?? "—"} {result.totalStudents ? `/ ${result.totalStudents}` : ""}
                    </td>
                    <td className="py-1.5">{result.mention ?? "—"}</td>
                    <td className="py-1.5">{result.isPublished ? "Oui" : "Non"}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Détail des notes</CardTitle>
        </CardHeader>
        <CardContent className="overflow-x-auto">
          {history.grades.length === 0 ? (
            <p className="text-sm text-muted-foreground">Aucune note enregistrée.</p>
          ) : (
            <table className="w-full text-sm">
              <thead className="text-left text-muted-foreground">
                <tr>
                  <th className="py-1 font-medium">Date</th>
                  <th className="py-1 font-medium">Matière / EC</th>
                  <th className="py-1 font-medium">Type</th>
                  <th className="py-1 font-medium">Note</th>
                </tr>
              </thead>
              <tbody>
                {history.grades.map((grade) => {
                  const value = grade.value === null ? null : Number(grade.value);
                  const max = Number(grade.maxValue);
                  return (
                    <tr key={grade.id} className="border-t border-border">
                      <td className="py-1.5">{new Date(grade.date).toLocaleDateString("fr-FR")}</td>
                      <td className="py-1.5">{grade.subject?.name ?? grade.courseElement?.name ?? "—"}</td>
                      <td className="py-1.5">{grade.type}</td>
                      <td className={`py-1.5 ${gradeColorClass(value, max)}`}>
                        {grade.isAbsent ? (grade.isJustified ? "Absent (justifié)" : "Absent") : `${value} / ${max}`}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
