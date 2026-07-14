"use client";

import { useEffect, useState } from "react";
import { cn } from "@scholaris/ui";
import { resourceClient } from "@/lib/api-client";
import type { AcademicYear } from "@/types/students";
import { formatAmount, type FinanceDashboard, type RecoveryBucket } from "@/types/finance";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { LoadingSpinner } from "@/components/shared/loading-spinner";

function StatTile({ label, value }: { label: string; value: string }) {
  return (
    <Card>
      <CardContent className="flex flex-col gap-1 p-4">
        <span className="text-xs uppercase text-muted-foreground">{label}</span>
        <span className="text-2xl font-semibold tabular-nums">{value}</span>
      </CardContent>
    </Card>
  );
}

/** Vert ≥ 80 %, ambre 50-79 %, rouge < 50 % — jamais la couleur seule (taux affiché en clair). */
function recoveryColor(rate: number): string {
  if (rate >= 80) return "bg-emerald-500";
  if (rate >= 50) return "bg-amber-500";
  return "bg-red-500";
}

function RecoveryTable({ title, buckets }: { title: string; buckets: RecoveryBucket[] }) {
  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">{title}</CardTitle>
        <CardDescription>Taux de recouvrement (encaissé / facturé)</CardDescription>
      </CardHeader>
      <CardContent>
        {buckets.length === 0 ? (
          <p className="text-sm text-muted-foreground">Aucune donnée.</p>
        ) : (
          <div className="flex flex-col gap-3">
            {buckets.map((bucket) => (
              <div key={bucket.id} className="flex flex-col gap-1">
                <div className="flex items-center justify-between text-sm">
                  <span className="font-medium">{bucket.name}</span>
                  <span className="tabular-nums text-muted-foreground">
                    {formatAmount(bucket.collected)} / {formatAmount(bucket.invoiced)} — {bucket.recoveryRate}%
                  </span>
                </div>
                <div className="h-2 w-full overflow-hidden rounded-full bg-secondary">
                  <div
                    className={cn("h-full rounded-full", recoveryColor(bucket.recoveryRate))}
                    style={{ width: `${Math.min(100, bucket.recoveryRate)}%` }}
                  />
                </div>
              </div>
            ))}
          </div>
        )}
      </CardContent>
    </Card>
  );
}

export default function FinanceDashboardPage() {
  const [dashboard, setDashboard] = useState<FinanceDashboard | null>(null);
  const [years, setYears] = useState<AcademicYear[]>([]);
  const [academicYearId, setAcademicYearId] = useState("");
  const [isLoading, setIsLoading] = useState(true);

  useEffect(() => {
    resourceClient.get<AcademicYear[]>("/academic-years").then((response) => setYears(response.data));
  }, []);

  useEffect(() => {
    setIsLoading(true);
    resourceClient
      .get<FinanceDashboard>("/finance/dashboard", { params: academicYearId ? { academicYearId } : {} })
      .then((response) => setDashboard(response.data))
      .finally(() => setIsLoading(false));
  }, [academicYearId]);

  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">Tableau de bord financier</h1>
          <p className="text-sm text-muted-foreground">Recouvrement des frais de scolarité</p>
        </div>
        <select
          className="h-9 rounded-md border border-border bg-background px-2 text-sm"
          value={academicYearId}
          onChange={(e) => setAcademicYearId(e.target.value)}
        >
          <option value="">Toutes les années</option>
          {years.map((year) => (
            <option key={year.id} value={year.id}>
              {year.label}
            </option>
          ))}
        </select>
      </div>

      {isLoading || !dashboard ? (
        <LoadingSpinner label="Chargement des indicateurs…" />
      ) : (
        <>
          <div className="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-6">
            <StatTile label="Total facturé" value={formatAmount(dashboard.totals.totalInvoiced)} />
            <StatTile label="Total encaissé" value={formatAmount(dashboard.totals.totalCollected)} />
            <StatTile label="Impayés" value={formatAmount(dashboard.totals.totalOutstanding)} />
            <StatTile label="Taux de recouvrement" value={`${dashboard.totals.recoveryRate}%`} />
            <StatTile label="Factures" value={String(dashboard.totals.invoiceCount)} />
            <StatTile label="En retard" value={String(dashboard.totals.overdueCount)} />
          </div>

          <div className="grid gap-4 lg:grid-cols-2">
            <RecoveryTable title="Par niveau" buckets={dashboard.byLevel} />
            <RecoveryTable title="Par classe" buckets={dashboard.byClassroom} />
          </div>
        </>
      )}
    </div>
  );
}
