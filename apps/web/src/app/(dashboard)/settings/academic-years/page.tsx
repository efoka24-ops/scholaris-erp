"use client";

import { useEffect, useState } from "react";
import { zodResolver } from "@hookform/resolvers/zod";
import { useForm } from "react-hook-form";
import { Plus } from "lucide-react";
import {
  academicYearSchema,
  periodSchema,
  PERIOD_TYPES,
  GRADING_STATUSES,
  type AcademicYearInput,
  type PeriodInput,
} from "@scholaris/shared";
import { resourceClient } from "@/lib/api-client";
import { useAuth } from "@/hooks/use-auth";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from "@/components/shared/form";
import { LoadingSpinner } from "@/components/shared/loading-spinner";
import type { AcademicYear, Period } from "@/types/settings";

const STATUS_LABELS: Record<string, string> = {
  ACTIVE: "Active",
  CLOSED: "Clôturée",
  ARCHIVED: "Archivée",
};

const PERIOD_TYPE_LABELS: Record<(typeof PERIOD_TYPES)[number], string> = {
  SEQUENCE: "Séquence",
  TRIMESTER: "Trimestre",
  SEMESTER: "Semestre",
};

const GRADING_STATUS_LABELS: Record<(typeof GRADING_STATUSES)[number], string> = {
  CLOSED: "Fermée",
  OPEN: "Ouverte",
  LOCKED: "Verrouillée",
};

export default function AcademicYearsPage() {
  const { hasPermission } = useAuth();
  const [years, setYears] = useState<AcademicYear[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [showYearForm, setShowYearForm] = useState(false);
  const [periodFormForYear, setPeriodFormForYear] = useState<string | null>(null);
  const [serverError, setServerError] = useState<string | null>(null);

  const canCreateYear = hasPermission("academic-years:create");
  const canCreatePeriod = hasPermission("periods:create");
  const canUpdatePeriod = hasPermission("periods:update");
  const canUnlockPeriod = hasPermission("periods:unlock");

  const loadYears = async () => {
    setIsLoading(true);
    try {
      const { data } = await resourceClient.get<AcademicYear[]>("/academic-years");
      setYears(data);
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => {
    loadYears();
  }, []);

  const yearForm = useForm<AcademicYearInput>({
    resolver: zodResolver(academicYearSchema),
    defaultValues: { label: "", startDate: "", endDate: "" },
  });

  async function onCreateYear(values: AcademicYearInput) {
    setServerError(null);
    try {
      await resourceClient.post("/academic-years", values);
      yearForm.reset({ label: "", startDate: "", endDate: "" });
      setShowYearForm(false);
      await loadYears();
    } catch (error: any) {
      setServerError(error.response?.data?.message ?? "Impossible de créer l'année académique.");
    }
  }

  const periodForm = useForm<PeriodInput>({
    resolver: zodResolver(periodSchema),
    defaultValues: { academicYearId: "", type: "SEQUENCE", number: 1, startDate: "", endDate: "" },
  });

  async function onCreatePeriod(values: PeriodInput) {
    setServerError(null);
    try {
      await resourceClient.post("/periods", values);
      periodForm.reset({ academicYearId: values.academicYearId, type: "SEQUENCE", number: 1, startDate: "", endDate: "" });
      setPeriodFormForYear(null);
      await loadYears();
    } catch (error: any) {
      setServerError(error.response?.data?.message ?? "Impossible de créer la période.");
    }
  }

  async function onActivateYear(id: string) {
    setServerError(null);
    try {
      await resourceClient.patch(`/academic-years/${id}/activate`);
      await loadYears();
    } catch (error: any) {
      setServerError(error.response?.data?.message ?? "Impossible d'activer cette année.");
    }
  }

  async function onCloseYear(id: string) {
    setServerError(null);
    try {
      await resourceClient.patch(`/academic-years/${id}/close`);
      await loadYears();
    } catch (error: any) {
      setServerError(error.response?.data?.message ?? "Impossible de clôturer cette année.");
    }
  }

  async function onUpdatePeriodStatus(periodId: string, gradingStatus: string) {
    setServerError(null);
    try {
      await resourceClient.put(`/periods/${periodId}/status`, { gradingStatus });
      await loadYears();
    } catch (error: any) {
      setServerError(error.response?.data?.message ?? "Impossible de modifier le statut de cette période.");
    }
  }

  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">Années académiques</h1>
          <p className="text-sm text-muted-foreground">
            Une seule année peut être active à la fois : en activer une nouvelle archive automatiquement l'ancienne.
          </p>
        </div>
        {canCreateYear ? (
          <Button size="sm" onClick={() => setShowYearForm((value) => !value)}>
            <Plus className="mr-2 h-4 w-4" />
            Nouvelle année
          </Button>
        ) : null}
      </div>

      {serverError ? <p className="text-sm font-medium text-destructive">{serverError}</p> : null}

      {showYearForm ? (
        <Card>
          <CardHeader>
            <CardTitle>Créer une année académique</CardTitle>
          </CardHeader>
          <CardContent>
            <Form {...yearForm}>
              <form onSubmit={yearForm.handleSubmit(onCreateYear)} className="flex flex-col gap-4" noValidate>
                <div className="grid grid-cols-3 gap-3">
                  <FormField
                    control={yearForm.control}
                    name="label"
                    render={({ field }) => (
                      <FormItem>
                        <FormLabel>Libellé</FormLabel>
                        <FormControl>
                          <Input placeholder="2026-2027" {...field} />
                        </FormControl>
                        <FormMessage />
                      </FormItem>
                    )}
                  />
                  <FormField
                    control={yearForm.control}
                    name="startDate"
                    render={({ field }) => (
                      <FormItem>
                        <FormLabel>Date de début</FormLabel>
                        <FormControl>
                          <Input type="date" {...field} />
                        </FormControl>
                        <FormMessage />
                      </FormItem>
                    )}
                  />
                  <FormField
                    control={yearForm.control}
                    name="endDate"
                    render={({ field }) => (
                      <FormItem>
                        <FormLabel>Date de fin</FormLabel>
                        <FormControl>
                          <Input type="date" {...field} />
                        </FormControl>
                        <FormMessage />
                      </FormItem>
                    )}
                  />
                </div>
                <Button type="submit" disabled={yearForm.formState.isSubmitting} className="w-fit">
                  {yearForm.formState.isSubmitting ? "Création…" : "Créer (et archiver l'année active)"}
                </Button>
              </form>
            </Form>
          </CardContent>
        </Card>
      ) : null}

      {isLoading ? (
        <LoadingSpinner label="Chargement des années académiques…" />
      ) : years.length === 0 ? (
        <p className="text-sm text-muted-foreground">Aucune année académique enregistrée.</p>
      ) : (
        <div className="flex flex-col gap-4">
          {years.map((year) => (
            <Card key={year.id}>
              <CardHeader className="flex-row items-center justify-between space-y-0">
                <div>
                  <CardTitle>{year.label}</CardTitle>
                  <p className="mt-1 text-sm text-muted-foreground">
                    {new Date(year.startDate).toLocaleDateString("fr-FR")} —{" "}
                    {new Date(year.endDate).toLocaleDateString("fr-FR")}
                  </p>
                </div>
                <div className="flex items-center gap-2">
                  <span
                    className={
                      "rounded-full px-2.5 py-0.5 text-xs font-medium " +
                      (year.status === "ACTIVE"
                        ? "bg-primary/10 text-primary"
                        : "bg-secondary text-secondary-foreground")
                    }
                  >
                    {STATUS_LABELS[year.status] ?? year.status}
                  </span>
                  {canCreateYear && year.status !== "ACTIVE" ? (
                    <Button size="sm" variant="outline" onClick={() => onActivateYear(year.id)}>
                      Activer
                    </Button>
                  ) : null}
                  {canCreateYear && year.status === "ACTIVE" ? (
                    <Button size="sm" variant="outline" onClick={() => onCloseYear(year.id)}>
                      Clôturer
                    </Button>
                  ) : null}
                </div>
              </CardHeader>
              <CardContent className="flex flex-col gap-4">
                <div className="flex items-center justify-between">
                  <h3 className="text-sm font-medium">Périodes</h3>
                  {canCreatePeriod ? (
                    <Button
                      size="sm"
                      variant="ghost"
                      onClick={() => {
                        periodForm.reset({
                          academicYearId: year.id,
                          type: "SEQUENCE",
                          number: (year.periods?.length ?? 0) + 1,
                          startDate: "",
                          endDate: "",
                        });
                        setPeriodFormForYear(periodFormForYear === year.id ? null : year.id);
                      }}
                    >
                      <Plus className="mr-1 h-3.5 w-3.5" />
                      Ajouter une période
                    </Button>
                  ) : null}
                </div>

                {periodFormForYear === year.id ? (
                  <Form {...periodForm}>
                    <form
                      onSubmit={periodForm.handleSubmit(onCreatePeriod)}
                      className="grid grid-cols-2 gap-3 rounded-md border border-border p-3 md:grid-cols-4"
                      noValidate
                    >
                      <FormField
                        control={periodForm.control}
                        name="type"
                        render={({ field }) => (
                          <FormItem>
                            <FormLabel>Type</FormLabel>
                            <FormControl>
                              <select
                                className="h-10 w-full rounded-md border border-border bg-background px-3 text-sm"
                                {...field}
                              >
                                {PERIOD_TYPES.map((type) => (
                                  <option key={type} value={type}>
                                    {PERIOD_TYPE_LABELS[type]}
                                  </option>
                                ))}
                              </select>
                            </FormControl>
                            <FormMessage />
                          </FormItem>
                        )}
                      />
                      <FormField
                        control={periodForm.control}
                        name="number"
                        render={({ field }) => (
                          <FormItem>
                            <FormLabel>Numéro</FormLabel>
                            <FormControl>
                              <Input type="number" min={1} {...field} />
                            </FormControl>
                            <FormMessage />
                          </FormItem>
                        )}
                      />
                      <FormField
                        control={periodForm.control}
                        name="startDate"
                        render={({ field }) => (
                          <FormItem>
                            <FormLabel>Début</FormLabel>
                            <FormControl>
                              <Input type="date" {...field} />
                            </FormControl>
                            <FormMessage />
                          </FormItem>
                        )}
                      />
                      <FormField
                        control={periodForm.control}
                        name="endDate"
                        render={({ field }) => (
                          <FormItem>
                            <FormLabel>Fin</FormLabel>
                            <FormControl>
                              <Input type="date" {...field} />
                            </FormControl>
                            <FormMessage />
                          </FormItem>
                        )}
                      />
                      <Button type="submit" size="sm" disabled={periodForm.formState.isSubmitting} className="w-fit">
                        {periodForm.formState.isSubmitting ? "Création…" : "Créer la période"}
                      </Button>
                    </form>
                  </Form>
                ) : null}

                {!year.periods || year.periods.length === 0 ? (
                  <p className="text-sm text-muted-foreground">Aucune période définie.</p>
                ) : (
                  <table className="w-full text-sm">
                    <thead className="text-left text-muted-foreground">
                      <tr>
                        <th className="py-1 font-medium">Type</th>
                        <th className="py-1 font-medium">N°</th>
                        <th className="py-1 font-medium">Dates</th>
                        <th className="py-1 font-medium">Statut</th>
                        {canUpdatePeriod ? <th className="py-1 font-medium">Actions</th> : null}
                      </tr>
                    </thead>
                    <tbody>
                      {year.periods.map((period: Period) => {
                        const isLocked = period.gradingStatus === "LOCKED";
                        const canAct = canUpdatePeriod && (!isLocked || canUnlockPeriod);
                        return (
                          <tr key={period.id} className="border-t border-border">
                            <td className="py-1.5">{PERIOD_TYPE_LABELS[period.type]}</td>
                            <td className="py-1.5">{period.number}</td>
                            <td className="py-1.5">
                              {new Date(period.startDate).toLocaleDateString("fr-FR")} —{" "}
                              {new Date(period.endDate).toLocaleDateString("fr-FR")}
                            </td>
                            <td className="py-1.5">{GRADING_STATUS_LABELS[period.gradingStatus]}</td>
                            {canUpdatePeriod ? (
                              <td className="flex gap-1 py-1.5">
                                {period.gradingStatus !== "OPEN" ? (
                                  <Button
                                    size="sm"
                                    variant="outline"
                                    disabled={!canAct}
                                    title={isLocked && !canUnlockPeriod ? "Réservé à l'administrateur" : undefined}
                                    onClick={() => onUpdatePeriodStatus(period.id, "OPEN")}
                                  >
                                    Ouvrir
                                  </Button>
                                ) : null}
                                {period.gradingStatus !== "CLOSED" ? (
                                  <Button
                                    size="sm"
                                    variant="outline"
                                    disabled={!canAct}
                                    onClick={() => onUpdatePeriodStatus(period.id, "CLOSED")}
                                  >
                                    Fermer
                                  </Button>
                                ) : null}
                                {period.gradingStatus !== "LOCKED" ? (
                                  <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => onUpdatePeriodStatus(period.id, "LOCKED")}
                                  >
                                    Verrouiller
                                  </Button>
                                ) : null}
                              </td>
                            ) : null}
                          </tr>
                        );
                      })}
                    </tbody>
                  </table>
                )}
              </CardContent>
            </Card>
          ))}
        </div>
      )}
    </div>
  );
}
