"use client";

import { useCallback, useEffect, useState } from "react";
import { resourceClient } from "@/lib/api-client";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { LoadingSpinner } from "@/components/shared/loading-spinner";

interface Level {
  id: string;
  name: string;
}
interface Period {
  id: string;
  type: string;
  number: number;
  academicYearId: string;
}
interface AcademicYear {
  id: string;
  label: string;
  status: string;
}
interface LevelReport {
  level: { id: string; name: string };
  effectifs: { total: number; boys: number; girls: number; classes: number };
  results: {
    levelAverage: number | null;
    successRate: number | null;
    gradedStudents: number;
    bestClass: { name: string; average: number } | null;
    weakestClass: { name: string; average: number } | null;
  };
  top10: Array<{ rank: number; name: string; classroom: string; average: number }>;
  distribution: Array<{ label: string; count: number }>;
  bySubject: Array<{ subject: string; coefficient: number; average: number; successRate: number }>;
  classComparison: Array<{ name: string; studentCount: number; average: number; successRate: number }>;
}

const fieldClass = "h-10 rounded-md border border-border bg-background px-3 text-sm";

export default function LevelReportPage() {
  const [levels, setLevels] = useState<Level[]>([]);
  const [years, setYears] = useState<AcademicYear[]>([]);
  const [periods, setPeriods] = useState<Period[]>([]);
  const [levelId, setLevelId] = useState("");
  const [periodId, setPeriodId] = useState("");
  const [report, setReport] = useState<LevelReport | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [isRunning, setIsRunning] = useState(false);
  const [err, setErr] = useState<string | null>(null);

  useEffect(() => {
    Promise.all([
      resourceClient.get<Level[]>("/levels").then((r) => r.data).catch(() => []),
      resourceClient.get<AcademicYear[]>("/academic-years").then((r) => r.data).catch(() => []),
    ]).then(([lvls, ys]) => {
      setLevels(lvls);
      setYears(ys);
      setLevelId((p) => p || lvls[0]?.id || "");
      setIsLoading(false);
    });
  }, []);

  // Charge les périodes de l'année active.
  useEffect(() => {
    const active = years.find((y) => y.status === "ACTIVE") ?? years[0];
    if (!active) return;
    resourceClient
      .get<Period[]>(`/periods?academicYearId=${active.id}`)
      .then((r) => {
        setPeriods(r.data);
        const trimesters = r.data.filter((p) => p.type === "TRIMESTER");
        setPeriodId((p) => p || trimesters[0]?.id || r.data[0]?.id || "");
      })
      .catch(() => setPeriods([]));
  }, [years]);

  const run = useCallback(() => {
    if (!levelId || !periodId) return;
    setErr(null);
    setIsRunning(true);
    resourceClient
      .get<LevelReport>(`/reports/level?levelId=${levelId}&periodId=${periodId}`)
      .then((r) => setReport(r.data))
      .catch((e) => {
        setReport(null);
        setErr(e.response?.data?.message ?? "Impossible de générer le rapport.");
      })
      .finally(() => setIsRunning(false));
  }, [levelId, periodId]);

  if (isLoading) return <LoadingSpinner label="Chargement…" />;

  const periodLabel = (p: Period) =>
    `${p.type === "TRIMESTER" ? "Trimestre" : p.type === "SEQUENCE" ? "Séquence" : "Semestre"} ${p.number}`;
  const maxBucket = report ? Math.max(1, ...report.distribution.map((d) => d.count)) : 1;
  const maxClass = report ? Math.max(1, ...report.classComparison.map((c) => c.average)) : 1;

  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-start justify-between">
        <div>
          <h1 className="text-2xl font-semibold">Rapport par niveau</h1>
          <p className="text-sm text-muted-foreground">
            Moyennes, taux de réussite, distribution, comparaison des classes et résultats par matière
          </p>
        </div>
        {report ? (
          <Button variant="outline" onClick={() => window.print()}>Imprimer</Button>
        ) : null}
      </div>

      <Card>
        <CardContent className="flex flex-wrap items-end gap-4 pt-6">
          <div className="flex flex-col gap-1.5">
            <Label>Niveau</Label>
            <select className={fieldClass} value={levelId} onChange={(e) => setLevelId(e.target.value)}>
              {levels.map((l) => (
                <option key={l.id} value={l.id}>{l.name}</option>
              ))}
            </select>
          </div>
          <div className="flex flex-col gap-1.5">
            <Label>Période</Label>
            <select className={fieldClass} value={periodId} onChange={(e) => setPeriodId(e.target.value)}>
              {periods.map((p) => (
                <option key={p.id} value={p.id}>{periodLabel(p)}</option>
              ))}
            </select>
          </div>
          <Button disabled={!levelId || !periodId || isRunning} onClick={run}>
            {isRunning ? "Génération…" : "Générer le rapport"}
          </Button>
        </CardContent>
      </Card>

      {err ? <p className="text-sm font-medium text-destructive">{err}</p> : null}

      {report ? (
        <div className="flex flex-col gap-6">
          {/* KPIs */}
          <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <Kpi label="Effectif" value={`${report.effectifs.total}`} sub={`${report.effectifs.boys} G · ${report.effectifs.girls} F · ${report.effectifs.classes} classes`} />
            <Kpi label="Moyenne du niveau" value={report.results.levelAverage != null ? `${report.results.levelAverage}/20` : "—"} sub={`${report.results.gradedStudents} élèves notés`} />
            <Kpi label="Taux de réussite" value={report.results.successRate != null ? `${report.results.successRate}%` : "—"} sub="Élèves ≥ 10/20" />
            <Kpi
              label="Meilleure / plus faible classe"
              value={report.results.bestClass ? report.results.bestClass.name : "—"}
              sub={
                report.results.bestClass && report.results.weakestClass
                  ? `${report.results.bestClass.average} ↑ · ${report.results.weakestClass.name} ${report.results.weakestClass.average} ↓`
                  : ""
              }
            />
          </div>

          {/* Distribution */}
          <Card>
            <CardHeader><CardTitle>Distribution des moyennes</CardTitle></CardHeader>
            <CardContent className="flex flex-col gap-2">
              {report.distribution.map((d) => (
                <div key={d.label} className="flex items-center gap-3">
                  <span className="w-16 shrink-0 text-xs text-muted-foreground">{d.label}</span>
                  <div className="h-4 flex-1 rounded bg-secondary">
                    <div className="h-4 rounded bg-primary" style={{ width: `${(d.count / maxBucket) * 100}%` }} />
                  </div>
                  <span className="w-8 shrink-0 text-right text-sm">{d.count}</span>
                </div>
              ))}
            </CardContent>
          </Card>

          <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
            {/* Comparaison classes */}
            <Card>
              <CardHeader><CardTitle>Comparaison des classes</CardTitle></CardHeader>
              <CardContent className="flex flex-col gap-2">
                {report.classComparison.map((c) => (
                  <div key={c.name} className="flex items-center gap-3">
                    <span className="w-24 shrink-0 truncate text-xs">{c.name}</span>
                    <div className="h-4 flex-1 rounded bg-secondary">
                      <div className="h-4 rounded bg-primary" style={{ width: `${(c.average / maxClass) * 100}%` }} />
                    </div>
                    <span className="w-20 shrink-0 text-right text-xs">{c.average} · {c.successRate}%</span>
                  </div>
                ))}
              </CardContent>
            </Card>

            {/* Top 10 */}
            <Card>
              <CardHeader><CardTitle>Top 10 du niveau</CardTitle></CardHeader>
              <CardContent>
                <table className="w-full text-sm">
                  <tbody>
                    {report.top10.map((s) => (
                      <tr key={s.rank} className="border-b border-border/50">
                        <td className="py-1.5 pr-2 font-semibold">{s.rank}</td>
                        <td className="py-1.5 pr-2">{s.name}</td>
                        <td className="py-1.5 pr-2 text-muted-foreground">{s.classroom}</td>
                        <td className="py-1.5 text-right font-medium">{s.average}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </CardContent>
            </Card>
          </div>

          {/* Par matière */}
          <Card>
            <CardHeader><CardTitle>Résultats par matière</CardTitle></CardHeader>
            <CardContent className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-border text-left text-muted-foreground">
                    <th className="py-2 pr-4 font-medium">Matière</th>
                    <th className="py-2 pr-4 font-medium">Coef.</th>
                    <th className="py-2 pr-4 font-medium">Moyenne niveau</th>
                    <th className="py-2 pr-4 font-medium">Taux de réussite</th>
                  </tr>
                </thead>
                <tbody>
                  {report.bySubject.map((s) => (
                    <tr key={s.subject} className="border-b border-border/50">
                      <td className="py-2 pr-4 font-medium">{s.subject}</td>
                      <td className="py-2 pr-4">{s.coefficient}</td>
                      <td className="py-2 pr-4">{s.average}/20</td>
                      <td className="py-2 pr-4">{s.successRate}%</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </CardContent>
          </Card>
        </div>
      ) : null}
    </div>
  );
}

function Kpi({ label, value, sub }: { label: string; value: string; sub?: string }) {
  return (
    <Card>
      <CardContent className="pt-6">
        <p className="text-xs text-muted-foreground">{label}</p>
        <p className="text-xl font-bold">{value}</p>
        {sub ? <p className="mt-1 text-xs text-muted-foreground">{sub}</p> : null}
      </CardContent>
    </Card>
  );
}
