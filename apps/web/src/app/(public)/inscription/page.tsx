"use client";

import { useState } from "react";
import { zodResolver } from "@hookform/resolvers/zod";
import { useForm } from "react-hook-form";
import { publicAdmissionSchema, type PublicAdmissionInput } from "@scholaris/shared";
import { apiClient } from "@/lib/api-client";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from "@/components/shared/form";

const TENANT_CODE = process.env.NEXT_PUBLIC_TENANT_CODE ?? "DEMO";

export default function PublicEnrollmentPage() {
  const [serverError, setServerError] = useState<string | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [submitted, setSubmitted] = useState(false);

  const form = useForm<PublicAdmissionInput>({
    resolver: zodResolver(publicAdmissionSchema),
    defaultValues: {
      tenantCode: TENANT_CODE,
      studentLastName: "",
      studentFirstName: "",
      studentDateOfBirth: "",
      desiredLevel: "",
      parentName: "",
      parentPhone: "",
      parentEmail: "",
      previousSchool: "",
      website: "", // honeypot : ne jamais l'afficher dans l'UI
    },
  });

  async function onSubmit(values: PublicAdmissionInput) {
    setServerError(null);
    setIsSubmitting(true);
    try {
      const { data } = await apiClient.post("/public/admissions", values);
      if (!data.success) {
        setServerError(data.error ?? "Impossible d'envoyer la pré-inscription.");
        return;
      }
      setSubmitted(true);
    } catch (error: any) {
      setServerError(
        error.response?.data?.error ?? "Impossible d'envoyer la pré-inscription. Vérifiez votre connexion et réessayez.",
      );
    } finally {
      setIsSubmitting(false);
    }
  }

  if (submitted) {
    return (
      <div className="mx-auto flex w-full max-w-lg flex-1 flex-col justify-center px-4 py-16 sm:px-8">
        <Card>
          <CardHeader>
            <CardTitle>Pré-inscription envoyée</CardTitle>
            <CardDescription>Merci ! Votre demande a bien été transmise à l'établissement.</CardDescription>
          </CardHeader>
          <CardContent>
            <p className="text-sm text-muted-foreground">
              Notre secrétariat va étudier le dossier et vous recontactera prochainement au numéro que vous avez
              indiqué pour finaliser l'inscription. Vous n'avez pas besoin de créer de compte à ce stade.
            </p>
          </CardContent>
        </Card>
      </div>
    );
  }

  return (
    <div className="mx-auto flex w-full max-w-2xl flex-1 flex-col gap-6 px-4 py-10 sm:px-8">
      <div>
        <h1 className="text-2xl font-semibold">Pré-inscription</h1>
        <p className="text-sm text-muted-foreground">
          Renseignez les informations de l'élève et d'un parent ou tuteur. Cette demande sera étudiée par
          l'établissement, qui vous recontactera pour finaliser le dossier.
        </p>
      </div>

      <Form {...form}>
        <form onSubmit={form.handleSubmit(onSubmit)} className="flex flex-col gap-6" noValidate>
          {/* Honeypot anti-bot : champ caché, jamais rempli par un humain. */}
          <div className="hidden" aria-hidden="true">
            <label htmlFor="website">Site web</label>
            <input
              id="website"
              type="text"
              tabIndex={-1}
              autoComplete="off"
              {...form.register("website")}
            />
          </div>

          <Card>
            <CardHeader>
              <CardTitle className="text-base">Élève</CardTitle>
            </CardHeader>
            <CardContent className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <FormField
                control={form.control}
                name="studentLastName"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Nom *</FormLabel>
                    <FormControl>
                      <Input {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="studentFirstName"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Prénom *</FormLabel>
                    <FormControl>
                      <Input {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="studentDateOfBirth"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Date de naissance *</FormLabel>
                    <FormControl>
                      <Input type="date" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="desiredLevel"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Niveau souhaité *</FormLabel>
                    <FormControl>
                      <Input placeholder="Ex : 6ème, CP, Terminale D…" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="previousSchool"
                render={({ field }) => (
                  <FormItem className="sm:col-span-2">
                    <FormLabel>École précédente</FormLabel>
                    <FormControl>
                      <Input {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle className="text-base">Parent / tuteur</CardTitle>
              <CardDescription>Le contact que l'établissement utilisera pour vous recontacter.</CardDescription>
            </CardHeader>
            <CardContent className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <FormField
                control={form.control}
                name="parentName"
                render={({ field }) => (
                  <FormItem className="sm:col-span-2">
                    <FormLabel>Nom complet *</FormLabel>
                    <FormControl>
                      <Input {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="parentPhone"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Téléphone *</FormLabel>
                    <FormControl>
                      <Input placeholder="+237 6…" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="parentEmail"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Email</FormLabel>
                    <FormControl>
                      <Input type="email" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
            </CardContent>
          </Card>

          {serverError ? <p className="text-sm font-medium text-destructive">{serverError}</p> : null}

          <Button type="submit" disabled={isSubmitting} size="lg">
            {isSubmitting ? "Envoi en cours…" : "Envoyer la pré-inscription"}
          </Button>
        </form>
      </Form>
    </div>
  );
}
