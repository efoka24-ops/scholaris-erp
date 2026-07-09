"use client";

import { useRouter } from "next/navigation";
import { zodResolver } from "@hookform/resolvers/zod";
import { useForm } from "react-hook-form";
import { roomSchema, ROOM_TYPES, type RoomInput } from "@scholaris/shared";
import { resourceClient } from "@/lib/api-client";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from "@/components/shared/form";
import { useState } from "react";

const ROOM_TYPE_LABELS: Record<(typeof ROOM_TYPES)[number], string> = {
  SALLE_CLASSE: "Salle de classe",
  LABORATOIRE: "Laboratoire",
  SALLE_INFO: "Salle informatique",
  AMPHITHEATRE: "Amphithéâtre",
  TERRAIN_SPORT: "Terrain de sport",
};

export default function NewRoomPage() {
  const router = useRouter();
  const [serverError, setServerError] = useState<string | null>(null);

  const form = useForm<RoomInput>({
    resolver: zodResolver(roomSchema),
    defaultValues: { code: "", name: "", type: "SALLE_CLASSE", equipment: [] },
  });

  async function onSubmit(values: RoomInput) {
    setServerError(null);
    try {
      await resourceClient.post("/rooms", values);
      router.push("/academics/rooms");
    } catch (error: any) {
      setServerError(error.response?.data?.message ?? "Impossible de créer la salle.");
    }
  }

  return (
    <Card className="max-w-lg">
      <CardHeader>
        <CardTitle>Nouvelle salle</CardTitle>
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
                      <Input placeholder="LAB-INFO-1" {...field} />
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
                      <Input placeholder="Laboratoire Informatique 1" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
            </div>

            <FormField
              control={form.control}
              name="type"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Type</FormLabel>
                  <FormControl>
                    <select className="h-10 w-full rounded-md border border-border bg-background px-3 text-sm" {...field}>
                      {ROOM_TYPES.map((type) => (
                        <option key={type} value={type}>
                          {ROOM_TYPE_LABELS[type]}
                        </option>
                      ))}
                    </select>
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />

            <div className="grid grid-cols-3 gap-3">
              <FormField
                control={form.control}
                name="capacity"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Capacité</FormLabel>
                    <FormControl>
                      <Input type="number" min={1} {...field} value={field.value ?? ""} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="building"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Bâtiment</FormLabel>
                    <FormControl>
                      <Input {...field} value={field.value ?? ""} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="floor"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Étage</FormLabel>
                    <FormControl>
                      <Input {...field} value={field.value ?? ""} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
            </div>

            {serverError ? <p className="text-sm font-medium text-destructive">{serverError}</p> : null}
            <Button type="submit" disabled={form.formState.isSubmitting}>
              {form.formState.isSubmitting ? "Création…" : "Créer la salle"}
            </Button>
          </form>
        </Form>
      </CardContent>
    </Card>
  );
}
