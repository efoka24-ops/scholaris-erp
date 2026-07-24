"use client";

import { useCallback, useEffect, useState } from "react";
import { useParams } from "next/navigation";
import { resourceClient } from "@/lib/api-client";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { LoadingSpinner } from "@/components/shared/loading-spinner";

interface Exam {
  id: string;
  name: string;
  code: string;
  levelId: string | null;
  academicYearId: string;
  feeAmount: string;
  passMark: string;
}
interface Candidate {
  id: string;
  registrationNumber: string;
  series: string | null;
  status: string;
  average: string | null;
  mention: string | null;
  rank: number | null;
  student: { firstName: string; lastName: string; matricule: string; gender: string } | null;
}
interface Level {
  id: string;
  name: string;
}
interface StudentRow {
  id: string;
  firstName: string;
  lastName: string;
}
interface ResultsData {
  stats: {
    totalGraded: number;
    passedCount: number;
    successRate: number;
    best: { name: string; average: number } | null;
    mentionDistribution: Record<string, number>;
  };
  candidates: Array<{
    registrationNumber: string;
    student: { firstName: string; lastName: string } | null;
    series: string | null;
    average: number | null;
    mention: string | null;
    rank: number | null;
    status: string;
  }>;
}

const fieldClass = "h-10 w-full rounded-md border border-border bg-background px-3 text-sm";

const STATUS_LABELS: Record<string, string> = {
  PENDING: "En attente",
  VALIDATED: "Validé",
  REJECTED: "Rejeté",
  ABSENT: "Absent",
  PASSED: "Admis",
  FAILED: "Échoué",
};

export default function ExamDetailPage() {
  const params = useParams();
  const examId = params.id as string;

  const [exam, setExam] = useState<Exam | null>(null);
  const [candidates, setCandidates] = useState<Candidate[]>([]);
  const [levels, setLevels] = useState<Level[]>([]);
  const [results, setResults] = useState<ResultsData | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [msg, setMsg] = useState<string | null>(null);
  const [err, setErr] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const [batchLevelId, setBatchLevelId] = useState("");
  const [batchSeries, setBatchSeries] = useState("");
  const [resultsText, setResultsText] = useState("");

  const load = useCallback(() => {
    setIsLoading(true);
    Promise.all([
      resourceClient.get<Exam>(`/exams/${examId}`).then((r) => r.data),
      resourceClient.get<Candidate[]>(`/exams/${examId}/candidates`).then((r) => r.data).catch(() => []),
      resourceClient.get<Level[]>("/levels").then((r) => r.data).catch(() => []),
      resourceClient.get<ResultsData>(`/exams/${examId}/results`).then((r) => r.data).catch(() => null),
    ]).then(([e, c, l, res]) => {
      setExam(e);
      setCandidates(c);
      setLevels(l);
      setBatchLevelId((prev) => prev || e.levelId || l[0]?.id || "");
      setResults(res && res.stats.totalGraded > 0 ? res : null);
      setIsLoading(false);
    });
  }, [examId]);

  useEffect(() => {
    load();
  }, [load]);

  async function registerBatch() {
    setErr(null);
    setMsg(null);
    setBusy(true);
    try {
      // Récupère les élèves du niveau sélectionné (inscrits, année de l'examen).
      const studentsRes = await resourceClient.get<{ data: StudentRow[] }>(
        `/students?levelId=${batchLevelId}&academicYearId=${exam?.academicYearId ?? ""}&limit=1000`,
      );
      const studentIds = studentsRes.data.data.map((s) => s.id);
      if (studentIds.length === 0) {
        setErr("Aucun élève inscrit dans ce niveau pour l'année de l'examen.");
        setBusy(false);
        return;
      }
      const { data } = await resourceClient.post<{
        total: number;
        registeredCount: number;
        rejectedCount: number;
        rejected: Array<{ studentId: string; reason: string }>;
      }>(`/exams/${examId}/register-batch`, {
        studentIds,
        series: batchSeries || undefined,
      });
      setMsg(
        `Inscription batch : ${data.registeredCount} inscrit(s), ${data.rejectedCount} rejeté(s) sur ${data.total}.`,
      );
      load();
    } catch (e: any) {
      setErr(e.response?.data?.message ?? "Échec de l'inscription batch.");
    } finally {
      setBusy(false);
    }
  }

  async function submitResults() {
    setErr(null);
    setMsg(null);
    setBusy(true);
    try {
      // Format attendu par ligne : N°inscription ; Matière ; Coefficient ; Note (vide = absent)
      const lines = resultsText
        .split("\n")
        .map((l) => l.trim())
        .filter(Boolean)
        .map((l) => {
          const [registrationNumber, subject, coefficient, mark] = l.split(/[;,\t]/).map((x) => x?.trim());
          const hasMark = mark !== undefined && mark !== "";
          return {
            registrationNumber,
            subject,
            coefficient: Number(coefficient),
            mark: hasMark ? Number(mark) : undefined,
            isAbsent: !hasMark,
          };
        });
      const { data } = await resourceClient.post<ResultsData>(`/exams/${examId}/results`, { results: lines });
      setResults(data);
      setMsg(`Résultats calculés : ${data.stats.passedCount}/${data.stats.totalGraded} admis (${data.stats.successRate}%).`);
      load();
    } catch (e: any) {
      setErr(e.response?.data?.message ?? "Échec de l'import des résultats.");
    } finally {
      setBusy(false);
    }
  }

  if (isLoading) return <LoadingSpinner label="Chargement de l'examen…" />;
  if (!exam) return <p className="text-sm text-destructive">Examen introuvable.</p>;

  return (
    <div className="flex flex-col gap-6">
      <div>
        <p className="text-xs text-muted-foreground">
          <a href="/exams" className="hover:underline">Examens</a> / {exam.code}
        </p>
        <h1 className="text-2xl font-semibold">{exam.name}</h1>
        <p className="text-sm text-muted-foreground">
          Frais : {Number(exam.feeAmount).toLocaleString("fr-FR")} FCFA · Seuil d&apos;admission : {Number(exam.passMark)}/20
        </p>
      </div>

      {err ? <p className="text-sm font-medium text-destructive">{err}</p> : null}
      {msg ? <p className="text-sm font-medium text-primary">{msg}</p> : null}

      <Card>
        <CardHeader>
          <CardTitle>Inscription batch</CardTitle>
          <CardDescription>Inscrit automatiquement tous les élèves éligibles d&apos;un niveau</CardDescription>
        </CardHeader>
        <CardContent>
          <div className="flex flex-wrap items-end gap-4">
            <div className="flex flex-col gap-1.5">
              <Label>Niveau</Label>
              <select className={fieldClass} value={batchLevelId} onChange={(e) => setBatchLevelId(e.target.value)}>
                {levels.map((l) => (
                  <option key={l.id} value={l.id}>{l.name}</option>
                ))}
              </select>
            </div>
            <div className="flex flex-col gap-1.5">
              <Label>Série (BAC/Probatoire)</Label>
              <input className={fieldClass} value={batchSeries} onChange={(e) => setBatchSeries(e.target.value)} placeholder="C, D, A4…" />
            </div>
            <Button disabled={!batchLevelId || busy} onClick={registerBatch}>
              {busy ? "Inscription…" : "Inscrire le niveau"}
            </Button>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Candidats inscrits ({candidates.length})</CardTitle>
        </CardHeader>
        <CardContent>
          {candidates.length === 0 ? (
            <p className="py-6 text-center text-sm text-muted-foreground">Aucun candidat inscrit.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-border text-left text-muted-foreground">
                    <th className="py-2 pr-4 font-medium">N° inscription</th>
                    <th className="py-2 pr-4 font-medium">Candidat</th>
                    <th className="py-2 pr-4 font-medium">Série</th>
                    <th className="py-2 pr-4 font-medium">Moyenne</th>
                    <th className="py-2 pr-4 font-medium">Mention</th>
                    <th className="py-2 pr-4 font-medium">Rang</th>
                    <th className="py-2 pr-4 font-medium">Statut</th>
                  </tr>
                </thead>
                <tbody>
                  {candidates.map((c) => (
                    <tr key={c.id} className="border-b border-border/50">
                      <td className="py-2 pr-4 font-mono text-xs">{c.registrationNumber}</td>
                      <td className="py-2 pr-4">
                        {c.student ? `${c.student.lastName} ${c.student.firstName}` : "—"}
                      </td>
                      <td className="py-2 pr-4">{c.series ?? "—"}</td>
                      <td className="py-2 pr-4">{c.average != null ? Number(c.average).toFixed(2) : "—"}</td>
                      <td className="py-2 pr-4">{c.mention ?? "—"}</td>
                      <td className="py-2 pr-4">{c.rank ?? "—"}</td>
                      <td className="py-2 pr-4">{STATUS_LABELS[c.status] ?? c.status}</td>
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
          <CardTitle>Saisie / import des résultats</CardTitle>
          <CardDescription>
            Une ligne par note : <code>N°inscription ; Matière ; Coefficient ; Note</code> (note vide = absent)
          </CardDescription>
        </CardHeader>
        <CardContent className="flex flex-col gap-3">
          <textarea
            className="min-h-[140px] w-full rounded-md border border-border bg-background p-3 font-mono text-xs"
            value={resultsText}
            onChange={(e) => setResultsText(e.target.value)}
            placeholder={"BEPC-2027-00001 ; Mathématiques ; 4 ; 14\nBEPC-2027-00001 ; Français ; 4 ; 12\nBEPC-2027-00002 ; Mathématiques ; 4 ;"}
          />
          <Button disabled={!resultsText.trim() || busy} onClick={submitResults} className="w-fit">
            {busy ? "Calcul…" : "Importer et calculer"}
          </Button>
        </CardContent>
      </Card>

      {results ? (
        <Card>
          <CardHeader>
            <CardTitle>Résultats</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-col gap-4">
            <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
              <Stat label="Notés" value={String(results.stats.totalGraded)} />
              <Stat label="Admis" value={String(results.stats.passedCount)} />
              <Stat label="Taux de réussite" value={`${results.stats.successRate}%`} />
              <Stat
                label="Major"
                value={results.stats.best ? `${results.stats.best.average.toFixed(2)}` : "—"}
                sub={results.stats.best?.name}
              />
            </div>
            {Object.keys(results.stats.mentionDistribution).length > 0 ? (
              <div className="flex flex-wrap gap-2">
                {Object.entries(results.stats.mentionDistribution).map(([mention, count]) => (
                  <span key={mention} className="rounded-full bg-secondary px-3 py-1 text-xs">
                    {mention} : {count}
                  </span>
                ))}
              </div>
            ) : null}
          </CardContent>
        </Card>
      ) : null}
    </div>
  );
}

function Stat({ label, value, sub }: { label: string; value: string; sub?: string }) {
  return (
    <div className="rounded-md border border-border p-3">
      <p className="text-xs text-muted-foreground">{label}</p>
      <p className="text-xl font-bold">{value}</p>
      {sub ? <p className="text-xs text-muted-foreground">{sub}</p> : null}
    </div>
  );
}
