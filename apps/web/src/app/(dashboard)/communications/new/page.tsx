"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { createCommunicationTemplateSchema, type CreateCommunicationTemplateInput } from "@scholaris/shared";
import { apiClient } from "@/lib/api-client";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from "@/components/shared/form";

const CHANNEL_OPTIONS: Array<{ value: CreateCommunicationTemplateInput["channel"]; label: string }> = [
  { value: "EMAIL", label: "Email" },
  { value: "SMS", label: "SMS" },
  { value: "WHATSAPP", label: "WhatsApp" },
  { value: "PUSH", label: "Push" },
  { value: "INTERNAL", label: "Interne" },
];

export default function NewCommunicationTemplatePage() {
  const router = useRouter();
  const [serverError, setServerError] = useState<string | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);

  const form = useForm<CreateCommunicationTemplateInput>({
    resolver: zodResolver(createCommunicationTemplateSchema),
    defaultValues: { code: "", name: "", channel: "EMAIL", subjectFr: "", subjectEn: "", bodyFr: "", bodyEn: "" },
  });

  const onSubmit = async (values: CreateCommunicationTemplateInput) => {
    setServerError(null);
    setIsSubmitting(true);
    try {
      await apiClient.post("/communication-templates", values);
      router.push("/communications");
    } catch (error: any) {
      setServerError(error.response?.data?.error ?? "Erreur lors de la création du modèle");
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-2xl font-semibold">Nouveau modèle de communication</h1>
        <p className="text-sm text-muted-foreground">
          Variables disponibles : {"{nom_eleve}"}, {"{classe}"}, {"{montant_du}"}, {"{date_echeance}"}, {"{moyenne}"}, {"{rang}"}
        </p>
      </div>

      <Card className="max-w-2xl">
        <CardHeader>
          <CardTitle>Informations du modèle</CardTitle>
        </CardHeader>
        <CardContent>
          <Form {...form}>
            <form onSubmit={form.handleSubmit(onSubmit)} className="flex flex-col gap-4">
              <div className="grid gap-4 sm:grid-cols-2">
                <FormField
                  control={form.control}
                  name="code"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>Code</FormLabel>
                      <FormControl>
                        <Input placeholder="CONVOCATION" {...field} />
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
                        <Input placeholder="Convocation à un entretien" {...field} />
                      </FormControl>
                      <FormMessage />
                    </FormItem>
                  )}
                />
              </div>

              <FormField
                control={form.control}
                name="channel"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Canal</FormLabel>
                    <FormControl>
                      <select
                        className="flex h-10 w-full rounded-md border border-border bg-background px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                        {...field}
                      >
                        {CHANNEL_OPTIONS.map((option) => (
                          <option key={option.value} value={option.value}>
                            {option.label}
                          </option>
                        ))}
                      </select>
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />

              <div className="grid gap-4 sm:grid-cols-2">
                <FormField
                  control={form.control}
                  name="subjectFr"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>Sujet (FR)</FormLabel>
                      <FormControl>
                        <Input placeholder="Convocation — {nom_eleve}" {...field} />
                      </FormControl>
                      <FormMessage />
                    </FormItem>
                  )}
                />
                <FormField
                  control={form.control}
                  name="subjectEn"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>Sujet (EN)</FormLabel>
                      <FormControl>
                        <Input placeholder="Summons — {nom_eleve}" {...field} />
                      </FormControl>
                      <FormMessage />
                    </FormItem>
                  )}
                />
              </div>

              <FormField
                control={form.control}
                name="bodyFr"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Corps (FR)</FormLabel>
                    <FormControl>
                      <textarea
                        className="flex min-h-24 w-full rounded-md border border-border bg-background px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                        placeholder="Bonjour {nom_eleve}, vous êtes convoqué(e) le {date_echeance}."
                        {...field}
                      />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />

              <FormField
                control={form.control}
                name="bodyEn"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Corps (EN)</FormLabel>
                    <FormControl>
                      <textarea
                        className="flex min-h-24 w-full rounded-md border border-border bg-background px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                        placeholder="Hello {nom_eleve}, you are summoned on {date_echeance}."
                        {...field}
                      />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />

              {serverError ? <p className="text-sm font-medium text-destructive">{serverError}</p> : null}

              <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" onClick={() => router.push("/communications")}>
                  Annuler
                </Button>
                <Button type="submit" disabled={isSubmitting}>
                  {isSubmitting ? "Création…" : "Créer le modèle"}
                </Button>
              </div>
            </form>
          </Form>
        </CardContent>
      </Card>
    </div>
  );
}
