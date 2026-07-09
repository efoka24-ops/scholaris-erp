"use client";

import { useEffect, useMemo, useState } from "react";
import { useRouter } from "next/navigation";
import { zodResolver } from "@hookform/resolvers/zod";
import { useForm } from "react-hook-form";
import { classroomSchema, SECTIONS, type ClassroomInput } from "@scholaris/shared";
import { resourceClient } from "@/lib/api-client";
import type { Cycle, Level, Program, Room } from "@/types/structure";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from "@/components/shared/form";

export default function NewClassroomPage() {
  const router = useRouter();
  const [cycles, setCycles] = useState<Cycle[]>([]);
  const [programs, setPrograms] = useState<Program[]>([]);
  const [levels, setLevels] = useState<Level[]>([]);
  const [rooms, setRooms] = useState<Room[]>([]);
  const [selectedCycleId, setSelectedCycleId] = useState("");
  const [selectedProgramId, setSelectedProgramId] = useState("");
  const [serverError, setServerError] = useState<string | null>(null);

  useEffect(() => {
    resourceClient.get<Cycle[]>("/cycles").then((response) => setCycles(response.data));
    resourceClient.get<Program[]>("/programs").then((response) => setPrograms(response.data));
    resourceClient.get<Level[]>("/levels").then((response) => setLevels(response.data));
    resourceClient.get<Room[]>("/rooms").then((response) => setRooms(response.data));
  }, []);

  const programsForCycle = useMemo(
    () => programs.filter((program) => program.cycleId === selectedCycleId),
    [programs, selectedCycleId],
  );
  const levelsForSelection = useMemo(
    () =>
      levels.filter((level) => {
        if (!selectedCycleId) return true;
        if (selectedProgramId) return level.programId === selectedProgramId;
        return level.cycleId === selectedCycleId && !level.programId;
      }),
    [levels, selectedCycleId, selectedProgramId],
  );

  const form = useForm<ClassroomInput>({
    resolver: zodResolver(classroomSchema),
    defaultValues: { code: "", name: "", capacity: 60, levelId: "", section: "FRANCOPHONE" },
  });

  async function onSubmit(values: ClassroomInput) {
    setServerError(null);
    try {
      await resourceClient.post("/classrooms", values);
      router.push("/academics/classrooms");
    } catch (error: any) {
      setServerError(error.response?.data?.message ?? "Impossible de créer la classe.");
    }
  }

  return (
    <Card className="max-w-xl">
      <CardHeader>
        <CardTitle>Nouvelle classe</CardTitle>
        <CardDescription>Sélecteurs en cascade : cycle → filière (si applicable) → niveau</CardDescription>
      </CardHeader>
      <CardContent>
        <div className="mb-4 grid grid-cols-2 gap-3">
          <div className="flex flex-col gap-1.5">
            <label className="text-sm font-medium">Cycle</label>
            <select
              className="h-10 rounded-md border border-border bg-background px-3 text-sm"
              value={selectedCycleId}
              onChange={(event) => {
                setSelectedCycleId(event.target.value);
                setSelectedProgramId("");
                form.setValue("levelId", "");
              }}
            >
              <option value="">Sélectionner…</option>
              {cycles.map((cycle) => (
                <option key={cycle.id} value={cycle.id}>
                  {cycle.name}
                </option>
              ))}
            </select>
          </div>
          <div className="flex flex-col gap-1.5">
            <label className="text-sm font-medium">Filière / Programme (optionnel)</label>
            <select
              className="h-10 rounded-md border border-border bg-background px-3 text-sm"
              value={selectedProgramId}
              disabled={!selectedCycleId}
              onChange={(event) => {
                setSelectedProgramId(event.target.value);
                form.setValue("levelId", "");
              }}
            >
              <option value="">Aucune (rattaché directement au cycle)</option>
              {programsForCycle.map((program) => (
                <option key={program.id} value={program.id}>
                  {program.name}
                </option>
              ))}
            </select>
          </div>
        </div>

        <Form {...form}>
          <form onSubmit={form.handleSubmit(onSubmit)} className="flex flex-col gap-4" noValidate>
            <FormField
              control={form.control}
              name="levelId"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Niveau</FormLabel>
                  <FormControl>
                    <select
                      className="h-10 w-full rounded-md border border-border bg-background px-3 text-sm"
                      {...field}
                    >
                      <option value="">Sélectionner…</option>
                      {levelsForSelection.map((level) => (
                        <option key={level.id} value={level.id}>
                          {level.name}
                        </option>
                      ))}
                    </select>
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />

            <div className="grid grid-cols-2 gap-3">
              <FormField
                control={form.control}
                name="code"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Code</FormLabel>
                    <FormControl>
                      <Input placeholder="6EME-A" {...field} />
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
                      <Input placeholder="6ème A" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
            </div>

            <div className="grid grid-cols-2 gap-3">
              <FormField
                control={form.control}
                name="capacity"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Capacité</FormLabel>
                    <FormControl>
                      <Input type="number" min={1} {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="section"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Section</FormLabel>
                    <FormControl>
                      <select className="h-10 w-full rounded-md border border-border bg-background px-3 text-sm" {...field}>
                        {SECTIONS.map((section) => (
                          <option key={section} value={section}>
                            {section === "FRANCOPHONE" ? "Francophone" : "Anglophone"}
                          </option>
                        ))}
                      </select>
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
            </div>

            <FormField
              control={form.control}
              name="roomId"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Salle (optionnel)</FormLabel>
                  <FormControl>
                    <select
                      className="h-10 w-full rounded-md border border-border bg-background px-3 text-sm"
                      value={field.value ?? ""}
                      onChange={field.onChange}
                    >
                      <option value="">Aucune</option>
                      {rooms.map((room) => (
                        <option key={room.id} value={room.id}>
                          {room.name}
                        </option>
                      ))}
                    </select>
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />

            <p className="text-xs text-muted-foreground">
              L'affectation d'un enseignant principal se fera depuis la liste des enseignants, disponible avec le
              module Gestion des utilisateurs.
            </p>

            {serverError ? <p className="text-sm font-medium text-destructive">{serverError}</p> : null}
            <Button type="submit" disabled={form.formState.isSubmitting}>
              {form.formState.isSubmitting ? "Création…" : "Créer la classe"}
            </Button>
          </form>
        </Form>
      </CardContent>
    </Card>
  );
}
