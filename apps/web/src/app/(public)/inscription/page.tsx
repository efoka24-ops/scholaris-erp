"use client";

import { useEffect, useState } from "react";
import { zodResolver } from "@hookform/resolvers/zod";
import { useForm } from "react-hook-form";
import { publicAdmissionSchema, type PublicAdmissionInput } from "@scholaris/shared";
import { apiClient } from "@/lib/api-client";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from "@/components/shared/form";

interface PublicTenant {
  id: string;
  code: string;
  name: string;
  type: string;
  logoUrl: string | null;
}

const MAX_DOCUMENTS = 5;
const MAX_DOCUMENT_SIZE = 5 * 1024 * 1024;
const ALLOWED_DOCUMENT_TYPES = ["application/pdf", "image/jpeg", "image/png"];

export default function PublicEnrollmentPage() {
  const [tenants, setTenants] = useState<PublicTenant[]>([]);
  const [tenantsError, setTenantsError] = useState<string | null>(null);
  const [serverError, setServerError] = useState<string | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [applicationId, setApplicationId] = useState<string | null>(null);
  const [submitted, setSubmitted] = useState(false);

  // Étape 2 : dépôt des bulletins de l'ancien établissement (facultatif).
  const [documents, setDocuments] = useState<File[]>([]);
  const [documentsError, setDocumentsError] = useState<string | null>(null);
  const [isUploading, setIsUploading] = useState(false);

  useEffect(() => {
    apiClient
      .get("/public/tenants")
      .then(({ data }) => {
        if (data.success) {
          setTenants(data.data);
        } else {
          setTenantsError("Impossible de charger la liste des établissements.");
        }
      })
      .catch(() => setTenantsError("Impossible de charger la liste des établissements."));
  }, []);

  const form = useForm<PublicAdmissionInput>({
    resolver: zodResolver(publicAdmissionSchema),
    defaultValues: {
      tenantCode: "",
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
      // Honeypot rempli : le backend répond {accepted:true} sans id, sans rien créer.
      if (data.data?.id) {
        setApplicationId(data.data.id);
      } else {
        setSubmitted(true);
      }
    } catch (error: any) {
      setServerError(
        error.response?.data?.error ?? "Impossible d'envoyer la pré-inscription. Vérifiez votre connexion et réessayez.",
      );
    } finally {
      setIsSubmitting(false);
    }
  }

  function handleFileChange(event: React.ChangeEvent<HTMLInputElement>) {
    setDocumentsError(null);
    const selected = Array.from(event.target.files ?? []);
    if (documents.length + selected.length > MAX_DOCUMENTS) {
      setDocumentsError(`Maximum ${MAX_DOCUMENTS} fichiers.`);
      return;
    }
    for (const file of selected) {
      if (!ALLOWED_DOCUMENT_TYPES.includes(file.type)) {
        setDocumentsError("Formats acceptés : PDF, JPEG, PNG uniquement.");
        return;
      }
      if (file.size > MAX_DOCUMENT_SIZE) {
        setDocumentsError(`« ${file.name} » dépasse 5 Mo.`);
        return;
      }
    }
    setDocuments((prev) => [...prev, ...selected]);
    event.target.value = "";
  }

  function removeDocument(index: number) {
    setDocuments((prev) => prev.filter((_, i) => i !== index));
  }

  async function submitDocuments() {
    if (!applicationId) return;
    setDocumentsError(null);
    setIsUploading(true);
    try {
      const formData = new FormData();
      documents.forEach((file) => formData.append("files", file));
      const { data } = await apiClient.post(`/public/admissions/${applicationId}/documents`, formData);
      if (!data.success) {
        setDocumentsError(data.error ?? "Impossible d'envoyer les documents.");
        return;
      }
      setSubmitted(true);
    } catch (error: any) {
      setDocumentsError(error.response?.data?.error ?? "Impossible d'envoyer les documents.");
    } finally {
      setIsUploading(false);
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

  // Étape 2 (post-création de la candidature) : dépôt facultatif des bulletins.
  if (applicationId) {
    return (
      <div className="mx-auto flex w-full max-w-lg flex-1 flex-col justify-center gap-6 px-4 py-16 sm:px-8">
        <Card>
          <CardHeader>
            <CardTitle>Bulletins de l'ancien établissement</CardTitle>
            <CardDescription>
              Facultatif, mais recommandé : joignez les derniers bulletins pour accélérer l'étude du dossier
              (PDF, JPEG ou PNG, 5 Mo max par fichier, {MAX_DOCUMENTS} fichiers max).
            </CardDescription>
          </CardHeader>
          <CardContent className="flex flex-col gap-4">
            <Input type="file" accept=".pdf,.jpg,.jpeg,.png" multiple onChange={handleFileChange} />
            {documents.length > 0 ? (
              <ul className="flex flex-col gap-1 text-sm">
                {documents.map((file, index) => (
                  <li key={`${file.name}-${index}`} className="flex items-center justify-between rounded-md border border-border px-3 py-1.5">
                    <span className="truncate">{file.name}</span>
                    <button
                      type="button"
                      onClick={() => removeDocument(index)}
                      className="text-xs text-muted-foreground hover:text-destructive"
                    >
                      Retirer
                    </button>
                  </li>
                ))}
              </ul>
            ) : null}
            {documentsError ? <p className="text-sm font-medium text-destructive">{documentsError}</p> : null}
            <div className="flex gap-2">
              <Button onClick={submitDocuments} disabled={isUploading || documents.length === 0}>
                {isUploading ? "Envoi en cours…" : "Envoyer les documents"}
              </Button>
              <Button variant="outline" onClick={() => setSubmitted(true)} disabled={isUploading}>
                Passer cette étape
              </Button>
            </div>
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
              <CardTitle className="text-base">Établissement</CardTitle>
              <CardDescription>Choisissez l'établissement auprès duquel vous déposez cette pré-inscription.</CardDescription>
            </CardHeader>
            <CardContent>
              <FormField
                control={form.control}
                name="tenantCode"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Établissement *</FormLabel>
                    <FormControl>
                      <select
                        {...field}
                        className="h-10 w-full rounded-md border border-border bg-background px-3 text-sm"
                      >
                        <option value="" disabled>
                          {tenantsError ? "Liste indisponible" : "Sélectionnez un établissement…"}
                        </option>
                        {tenants.map((tenant) => (
                          <option key={tenant.id} value={tenant.code}>
                            {tenant.name}
                          </option>
                        ))}
                      </select>
                    </FormControl>
                    {tenantsError ? <p className="text-sm text-destructive">{tenantsError}</p> : null}
                    <FormMessage />
                  </FormItem>
                )}
              />
            </CardContent>
          </Card>

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
