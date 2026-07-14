"use client";

import { useEffect, useState } from "react";
import { zodResolver } from "@hookform/resolvers/zod";
import { useFieldArray, useForm } from "react-hook-form";
import { Plus, Trash2 } from "lucide-react";
import {
  calculationEngineSchema,
  DEFAULT_CALCULATION_ENGINE_CONFIG,
  EVALUATION_TYPES,
  ROUNDING_RULES,
  ABSENCE_RULES,
  type CalculationEngineConfig,
} from "@scholaris/shared";
import { resourceClient } from "@/lib/api-client";
import { useAuth } from "@/hooks/use-auth";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from "@/components/shared/form";
import { LoadingSpinner } from "@/components/shared/loading-spinner";
import type { CurrentUser } from "@/types/auth";

const EVALUATION_TYPE_LABELS: Record<(typeof EVALUATION_TYPES)[number], string> = {
  SEQUENTIAL: "Séquentiel (séquences → trimestres)",
  TRIMESTER: "Trimestriel (moyennes directes)",
  SEMESTER: "Semestriel",
  LMD: "LMD (UE/EC, crédits, GPA)",
};

const ROUNDING_RULE_LABELS: Record<(typeof ROUNDING_RULES)[number], string> = {
  NONE: "Aucun arrondi",
  HUNDREDTH: "Au centième",
  TENTH: "Au dixième",
  HALF_POINT: "Au demi-point",
  INTEGER: "À l'entier",
};

const ABSENCE_RULE_LABELS: Record<(typeof ABSENCE_RULES)[number], string> = {
  ZERO: "Note de 0 pour une absence non justifiée",
  NEUTRALIZED: "Épreuve neutralisée (non comptée dans la moyenne)",
  POSTPONED: "Report à une épreuve de rattrapage",
};

function parseWeightsInput(value: string): number[] {
  return value
    .split(",")
    .map((part) => part.trim())
    .filter(Boolean)
    .map((part) => Number(part))
    .filter((n) => !Number.isNaN(n));
}

export default function CalculationEnginePage() {
  const { user } = useAuth() as { user: CurrentUser | null };
  const [isLoading, setIsLoading] = useState(true);
  const [serverError, setServerError] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);

  const form = useForm<CalculationEngineConfig>({
    resolver: zodResolver(calculationEngineSchema),
    defaultValues: DEFAULT_CALCULATION_ENGINE_CONFIG,
  });

  const mentionFields = useFieldArray({ control: form.control, name: "mentionThresholds" });
  const gpaFields = useFieldArray({ control: form.control, name: "gpaScale" });

  const evaluationType = form.watch("evaluationType");

  useEffect(() => {
    if (!user?.tenantId) return;
    setIsLoading(true);
    resourceClient
      .get(`/tenants/${user.tenantId}/config`)
      .then(({ data }) => {
        const config = data?.data ?? data;
        if (config && Object.keys(config).length > 0) {
          form.reset(config);
        }
      })
      .finally(() => setIsLoading(false));
    // form volontairement omis des dépendances : reset() ne doit être appelé qu'au chargement initial.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [user?.tenantId]);

  async function onSubmit(values: CalculationEngineConfig) {
    if (!user?.tenantId) return;
    setServerError(null);
    setSuccessMessage(null);
    try {
      await resourceClient.put(`/tenants/${user.tenantId}/config`, values);
      setSuccessMessage("Configuration du moteur de calcul enregistrée.");
    } catch (error: any) {
      setServerError(error.response?.data?.message ?? "Configuration invalide — vérifiez les valeurs saisies.");
    }
  }

  if (isLoading) {
    return <LoadingSpinner label="Chargement de la configuration…" />;
  }

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-2xl font-semibold">Moteur de calcul</h1>
        <p className="text-sm text-muted-foreground">
          Type d'évaluation, pondérations, seuils de mentions, arrondi, règle d'absence et paramètres LMD.
        </p>
      </div>

      <Form {...form}>
        <form onSubmit={form.handleSubmit(onSubmit)} className="flex flex-col gap-6" noValidate>
          <Card>
            <CardHeader>
              <CardTitle>Type d'évaluation</CardTitle>
              <CardDescription>Détermine la structure de calcul de la moyenne (séquences, trimestres, LMD…).</CardDescription>
            </CardHeader>
            <CardContent className="grid grid-cols-1 gap-4 md:grid-cols-2">
              <FormField
                control={form.control}
                name="evaluationType"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Type</FormLabel>
                    <FormControl>
                      <select className="h-10 w-full rounded-md border border-border bg-background px-3 text-sm" {...field}>
                        {EVALUATION_TYPES.map((type) => (
                          <option key={type} value={type}>
                            {EVALUATION_TYPE_LABELS[type]}
                          </option>
                        ))}
                      </select>
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="roundingRule"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Règle d'arrondi</FormLabel>
                    <FormControl>
                      <select className="h-10 w-full rounded-md border border-border bg-background px-3 text-sm" {...field}>
                        {ROUNDING_RULES.map((rule) => (
                          <option key={rule} value={rule}>
                            {ROUNDING_RULE_LABELS[rule]}
                          </option>
                        ))}
                      </select>
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="absenceRule"
                render={({ field }) => (
                  <FormItem className="md:col-span-2">
                    <FormLabel>Règle d'absence</FormLabel>
                    <FormControl>
                      <select className="h-10 w-full rounded-md border border-border bg-background px-3 text-sm" {...field}>
                        {ABSENCE_RULES.map((rule) => (
                          <option key={rule} value={rule}>
                            {ABSENCE_RULE_LABELS[rule]}
                          </option>
                        ))}
                      </select>
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              {evaluationType === "LMD" ? (
                <FormField
                  control={form.control}
                  name="lmdCompensation"
                  render={({ field }) => (
                    <FormItem className="flex-row items-center gap-2 md:col-span-2">
                      <FormControl>
                        <input
                          type="checkbox"
                          className="h-4 w-4"
                          checked={field.value ?? false}
                          onChange={(e) => field.onChange(e.target.checked)}
                        />
                      </FormControl>
                      <FormLabel className="!mt-0">Autoriser la compensation entre UE/EC</FormLabel>
                    </FormItem>
                  )}
                />
              ) : null}
            </CardContent>
          </Card>

          {evaluationType === "SEQUENTIAL" || evaluationType === "TRIMESTER" ? (
            <Card>
              <CardHeader>
                <CardTitle>Pondérations</CardTitle>
                <CardDescription>Listes de nombres séparés par des virgules (ex: 1,1 pour deux séquences de poids égal).</CardDescription>
              </CardHeader>
              <CardContent className="grid grid-cols-1 gap-4 md:grid-cols-2">
                {evaluationType === "SEQUENTIAL" ? (
                  <FormField
                    control={form.control}
                    name="sequenceWeights"
                    render={({ field }) => (
                      <FormItem>
                        <FormLabel>Pondérations des séquences</FormLabel>
                        <FormControl>
                          <Input
                            defaultValue={(field.value ?? []).join(",")}
                            onBlur={(e) => field.onChange(parseWeightsInput(e.target.value))}
                          />
                        </FormControl>
                        <FormMessage />
                      </FormItem>
                    )}
                  />
                ) : null}
                <FormField
                  control={form.control}
                  name="trimesterWeights"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>Pondérations des trimestres</FormLabel>
                      <FormControl>
                        <Input
                          defaultValue={(field.value ?? []).join(",")}
                          onBlur={(e) => field.onChange(parseWeightsInput(e.target.value))}
                        />
                      </FormControl>
                      <FormMessage />
                    </FormItem>
                  )}
                />
              </CardContent>
            </Card>
          ) : null}

          <Card>
            <CardHeader className="flex-row items-center justify-between space-y-0">
              <div>
                <CardTitle>Seuils de mentions</CardTitle>
                <CardDescription>Moyenne minimale (sur 20) requise pour chaque mention.</CardDescription>
              </div>
              <Button
                type="button"
                size="sm"
                variant="outline"
                onClick={() => mentionFields.append({ code: "", label: "", minAverage: 10 })}
              >
                <Plus className="mr-1 h-3.5 w-3.5" />
                Ajouter
              </Button>
            </CardHeader>
            <CardContent className="flex flex-col gap-3">
              {mentionFields.fields.map((item, index) => (
                <div key={item.id} className="grid grid-cols-1 gap-3 md:grid-cols-[1fr_2fr_1fr_auto]">
                  <FormField
                    control={form.control}
                    name={`mentionThresholds.${index}.code`}
                    render={({ field }) => (
                      <FormItem>
                        <FormControl>
                          <Input placeholder="Code (ex: TRES_BIEN)" {...field} />
                        </FormControl>
                        <FormMessage />
                      </FormItem>
                    )}
                  />
                  <FormField
                    control={form.control}
                    name={`mentionThresholds.${index}.label`}
                    render={({ field }) => (
                      <FormItem>
                        <FormControl>
                          <Input placeholder="Libellé (ex: Très Bien)" {...field} />
                        </FormControl>
                        <FormMessage />
                      </FormItem>
                    )}
                  />
                  <FormField
                    control={form.control}
                    name={`mentionThresholds.${index}.minAverage`}
                    render={({ field }) => (
                      <FormItem>
                        <FormControl>
                          <Input type="number" step="0.1" min={0} max={20} {...field} />
                        </FormControl>
                        <FormMessage />
                      </FormItem>
                    )}
                  />
                  <Button type="button" variant="ghost" size="sm" onClick={() => mentionFields.remove(index)}>
                    <Trash2 className="h-4 w-4" />
                  </Button>
                </div>
              ))}
              {mentionFields.fields.length === 0 ? (
                <p className="text-sm text-muted-foreground">Aucune mention configurée.</p>
              ) : null}
            </CardContent>
          </Card>

          {evaluationType === "LMD" ? (
            <Card>
              <CardHeader className="flex-row items-center justify-between space-y-0">
                <div>
                  <CardTitle>Échelle GPA</CardTitle>
                  <CardDescription>Conversion note (sur 100) → lettre → points GPA (sur 4).</CardDescription>
                </div>
                <Button
                  type="button"
                  size="sm"
                  variant="outline"
                  onClick={() => gpaFields.append({ grade: "", minScore: 0, points: 0 })}
                >
                  <Plus className="mr-1 h-3.5 w-3.5" />
                  Ajouter
                </Button>
              </CardHeader>
              <CardContent className="flex flex-col gap-3">
                {gpaFields.fields.map((item, index) => (
                  <div key={item.id} className="grid grid-cols-1 gap-3 md:grid-cols-[1fr_1fr_1fr_auto]">
                    <FormField
                      control={form.control}
                      name={`gpaScale.${index}.grade`}
                      render={({ field }) => (
                        <FormItem>
                          <FormControl>
                            <Input placeholder="Lettre (ex: A)" {...field} />
                          </FormControl>
                          <FormMessage />
                        </FormItem>
                      )}
                    />
                    <FormField
                      control={form.control}
                      name={`gpaScale.${index}.minScore`}
                      render={({ field }) => (
                        <FormItem>
                          <FormControl>
                            <Input type="number" min={0} max={100} {...field} />
                          </FormControl>
                          <FormMessage />
                        </FormItem>
                      )}
                    />
                    <FormField
                      control={form.control}
                      name={`gpaScale.${index}.points`}
                      render={({ field }) => (
                        <FormItem>
                          <FormControl>
                            <Input type="number" step="0.1" min={0} max={4} {...field} />
                          </FormControl>
                          <FormMessage />
                        </FormItem>
                      )}
                    />
                    <Button type="button" variant="ghost" size="sm" onClick={() => gpaFields.remove(index)}>
                      <Trash2 className="h-4 w-4" />
                    </Button>
                  </div>
                ))}
                {gpaFields.fields.length === 0 ? (
                  <p className="text-sm text-muted-foreground">Aucune entrée d'échelle GPA.</p>
                ) : null}
              </CardContent>
            </Card>
          ) : null}

          {serverError ? <p className="text-sm font-medium text-destructive">{serverError}</p> : null}
          {successMessage ? <p className="text-sm font-medium text-primary">{successMessage}</p> : null}

          <Button type="submit" disabled={form.formState.isSubmitting} className="w-fit">
            {form.formState.isSubmitting ? "Enregistrement…" : "Enregistrer la configuration"}
          </Button>
        </form>
      </Form>
    </div>
  );
}
