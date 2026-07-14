"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { zodResolver } from "@hookform/resolvers/zod";
import { useForm } from "react-hook-form";
import { subjectSchema, SUBJECT_CATEGORIES, SUBJECT_CATEGORY_LABELS, type SubjectInput } from "@scholaris/shared";
import { resourceClient } from "@/lib/api-client";
import type { Level } from "@/types/structure";
import type { Subject } from "@/types/subjects";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from "@/components/shared/form";

interface SubjectFormProps {
  /** Matière existante → mode édition (PUT) ; absente → création (POST). */
  subject?: Subject;
}

export function SubjectForm({ subject }: SubjectFormProps) {
  const router = useRouter();
  const [levels, setLevels] = useState<Level[]>([]);
  const [serverError, setServerError] = useState<string | null>(null);
  const isEdit = Boolean(subject);

  useEffect(() => {
    resourceClient.get<Level[]>("/levels").then((response) => setLevels(response.data));
  }, []);

  const form = useForm<SubjectInput>({
    resolver: zodResolver(subjectSchema),
    defaultValues: subject
      ? {
          code: subject.code,
          name: subject.name,
          coefficient: Number(subject.coefficient),
          weeklyHours: subject.weeklyHours,
          category: subject.category,
          isEliminatory: subject.isEliminatory,
          eliminatoryThreshold: Number(subject.eliminatoryThreshold),
          levelIds: subject.levelIds ?? [],
        }
      : {
          code: "",
          name: "",
          coefficient: 1,
          weeklyHours: 1,
          category: "SCIENTIFIC",
          isEliminatory: false,
          eliminatoryThreshold: 0,
          levelIds: [],
        },
  });

  const isEliminatory = form.watch("isEliminatory");
  const selectedLevelIds = form.watch("levelIds") ?? [];

  function toggleLevel(levelId: string) {
    const next = selectedLevelIds.includes(levelId)
      ? selectedLevelIds.filter((id) => id !== levelId)
      : [...selectedLevelIds, levelId];
    form.setValue("levelIds", next, { shouldDirty: true });
  }

  async function onSubmit(values: SubjectInput) {
    setServerError(null);
    try {
      if (isEdit && subject) {
        await resourceClient.put(`/subjects/${subject.id}`, values);
      } else {
        await resourceClient.post("/subjects", values);
      }
      router.push("/academics/subjects");
      router.refresh();
    } catch (error: any) {
      setServerError(
        error.response?.data?.message ?? (isEdit ? "Impossible de modifier la matière." : "Impossible de créer la matière."),
      );
    }
  }

  return (
    <Card className="max-w-xl">
      <CardHeader>
        <CardTitle>{isEdit ? `Modifier ${subject?.name}` : "Nouvelle matière"}</CardTitle>
        <CardDescription>
          Référentiel des disciplines : code unique, coefficient, volume horaire hebdomadaire et catégorie.
        </CardDescription>
      </CardHeader>
      <CardContent>
        <Form {...form}>
          <form onSubmit={form.handleSubmit(onSubmit)} className="flex flex-col gap-4" noValidate>
            <div className="grid grid-cols-2 gap-3">
              <FormField
                control={form.control}
                name="code"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Code</FormLabel>
                    <FormControl>
                      <Input placeholder="MATH" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="name"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Nom</FormLabel>
                    <FormControl>
                      <Input placeholder="Mathématiques" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
            </div>

            <div className="grid grid-cols-3 gap-3">
              <FormField
                control={form.control}
                name="coefficient"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Coefficient</FormLabel>
                    <FormControl>
                      <Input type="number" step="0.5" min={0.5} {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="weeklyHours"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Heures / semaine</FormLabel>
                    <FormControl>
                      <Input type="number" min={1} {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="category"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Catégorie</FormLabel>
                    <FormControl>
                      <select
                        className="h-10 w-full rounded-md border border-border bg-background px-3 text-sm"
                        {...field}
                      >
                        {SUBJECT_CATEGORIES.map((category) => (
                          <option key={category} value={category}>
                            {SUBJECT_CATEGORY_LABELS[category]}
                          </option>
                        ))}
                      </select>
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
            </div>

            <div className="flex items-end gap-4">
              <FormField
                control={form.control}
                name="isEliminatory"
                render={({ field }) => (
                  <FormItem>
                    <label className="flex items-center gap-2 text-sm font-medium">
                      <input
                        type="checkbox"
                        checked={Boolean(field.value)}
                        onChange={(event) => field.onChange(event.target.checked)}
                        className="h-4 w-4 rounded border-border"
                      />
                      Matière éliminatoire
                    </label>
                  </FormItem>
                )}
              />
              {isEliminatory ? (
                <FormField
                  control={form.control}
                  name="eliminatoryThreshold"
                  render={({ field }) => (
                    <FormItem className="flex-1">
                      <FormLabel>Seuil éliminatoire (note)</FormLabel>
                      <FormControl>
                        <Input type="number" step="0.5" min={0} {...field} />
                      </FormControl>
                      <FormMessage />
                    </FormItem>
                  )}
                />
              ) : null}
            </div>

            <div className="flex flex-col gap-1.5">
              <span className="text-sm font-medium">Niveaux concernés</span>
              {levels.length === 0 ? (
                <p className="text-xs text-muted-foreground">Aucun niveau défini (voir Structure pédagogique).</p>
              ) : (
                <div className="grid grid-cols-2 gap-1 rounded-md border border-border p-3 md:grid-cols-3">
                  {levels.map((level) => (
                    <label key={level.id} className="flex items-center gap-2 text-sm">
                      <input
                        type="checkbox"
                        checked={selectedLevelIds.includes(level.id)}
                        onChange={() => toggleLevel(level.id)}
                        className="h-4 w-4 rounded border-border"
                      />
                      {level.name}
                    </label>
                  ))}
                </div>
              )}
            </div>

            {serverError ? <p className="text-sm font-medium text-destructive">{serverError}</p> : null}
            <Button type="submit" disabled={form.formState.isSubmitting}>
              {form.formState.isSubmitting
                ? "Enregistrement…"
                : isEdit
                  ? "Enregistrer les modifications"
                  : "Créer la matière"}
            </Button>
          </form>
        </Form>
      </CardContent>
    </Card>
  );
}
