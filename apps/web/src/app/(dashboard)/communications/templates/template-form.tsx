"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { resourceClient } from "@/lib/api-client";
import {
  CHANNEL_LABELS,
  TEMPLATE_VARIABLES,
  type Channel,
  type CommunicationTemplate,
  type CommunicationTemplateInput,
} from "@/types/communication-templates";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";

interface TemplateFormProps {
  /** Template existant → mode édition (PUT) ; absent → création (POST). */
  template?: CommunicationTemplate;
}

const CHANNELS = Object.keys(CHANNEL_LABELS) as Channel[];

export function TemplateForm({ template }: TemplateFormProps) {
  const router = useRouter();
  const isEdit = Boolean(template);
  const [values, setValues] = useState<CommunicationTemplateInput>({
    code: template?.code ?? "",
    name: template?.name ?? "",
    channel: template?.channel ?? "EMAIL",
    subjectFr: template?.subjectFr ?? "",
    subjectEn: template?.subjectEn ?? "",
    bodyFr: template?.bodyFr ?? "",
    bodyEn: template?.bodyEn ?? "",
  });
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [serverError, setServerError] = useState<string | null>(null);

  function update<K extends keyof CommunicationTemplateInput>(key: K, value: CommunicationTemplateInput[K]) {
    setValues((prev) => ({ ...prev, [key]: value }));
  }

  function insertVariable(field: "bodyFr" | "bodyEn" | "subjectFr" | "subjectEn", variable: string) {
    update(field, `${values[field] ?? ""}${variable}`);
  }

  async function onSubmit(event: React.FormEvent) {
    event.preventDefault();
    setServerError(null);
    if (!values.code || !values.name || !values.bodyFr) {
      setServerError("Le code, le nom et le contenu (français) sont obligatoires.");
      return;
    }
    setIsSubmitting(true);
    try {
      if (isEdit && template) {
        await resourceClient.put(`/communication-templates/${template.id}`, values);
      } else {
        await resourceClient.post("/communication-templates", values);
      }
      router.push("/communications/templates");
      router.refresh();
    } catch (error: any) {
      setServerError(
        error.response?.data?.message ?? (isEdit ? "Impossible de modifier le template." : "Impossible de créer le template."),
      );
    } finally {
      setIsSubmitting(false);
    }
  }

  return (
    <Card className="max-w-2xl">
      <CardHeader>
        <CardTitle>{isEdit ? `Modifier ${template?.name}` : "Nouveau template"}</CardTitle>
        <CardDescription>
          Modèle réutilisable pour vos communications, avec variables dynamiques (ex: {"{nom_eleve}"}).
        </CardDescription>
      </CardHeader>
      <CardContent>
        <form onSubmit={onSubmit} className="flex flex-col gap-4" noValidate>
          <div className="grid grid-cols-2 gap-3">
            <div className="flex flex-col gap-1.5">
              <label className="text-sm font-medium" htmlFor="code">
                Code
              </label>
              <Input id="code" placeholder="CONVOCATION_PARENT" value={values.code} onChange={(e) => update("code", e.target.value)} />
            </div>
            <div className="flex flex-col gap-1.5">
              <label className="text-sm font-medium" htmlFor="name">
                Nom
              </label>
              <Input id="name" placeholder="Convocation parent" value={values.name} onChange={(e) => update("name", e.target.value)} />
            </div>
          </div>

          <div className="flex flex-col gap-1.5">
            <label className="text-sm font-medium" htmlFor="channel">
              Canal
            </label>
            <select
              id="channel"
              className="h-10 w-full rounded-md border border-border bg-background px-3 text-sm"
              value={values.channel}
              onChange={(e) => update("channel", e.target.value as Channel)}
            >
              {CHANNELS.map((channel) => (
                <option key={channel} value={channel}>
                  {CHANNEL_LABELS[channel]}
                </option>
              ))}
            </select>
          </div>

          <div className="flex flex-col gap-1.5">
            <span className="text-sm font-medium">Variables dynamiques disponibles</span>
            <div className="flex flex-wrap gap-1.5">
              {TEMPLATE_VARIABLES.map((variable) => (
                <button
                  type="button"
                  key={variable}
                  onClick={() => insertVariable("bodyFr", variable)}
                  className="rounded-md border border-border bg-secondary/40 px-2 py-1 text-xs font-mono hover:bg-secondary"
                >
                  {variable}
                </button>
              ))}
            </div>
            <p className="text-xs text-muted-foreground">Cliquez pour insérer une variable dans le contenu (français).</p>
          </div>

          {values.channel === "EMAIL" ? (
            <div className="flex flex-col gap-1.5">
              <label className="text-sm font-medium" htmlFor="subjectFr">
                Objet (français)
              </label>
              <Input
                id="subjectFr"
                placeholder="Convocation - {nom_eleve}"
                value={values.subjectFr}
                onChange={(e) => update("subjectFr", e.target.value)}
              />
            </div>
          ) : null}

          <div className="flex flex-col gap-1.5">
            <label className="text-sm font-medium" htmlFor="bodyFr">
              Contenu (français)
            </label>
            <textarea
              id="bodyFr"
              rows={5}
              className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
              placeholder="Bonjour {nom_eleve}, vous êtes convoqué(e) le {date_echeance}."
              value={values.bodyFr}
              onChange={(e) => update("bodyFr", e.target.value)}
            />
          </div>

          <div className="flex flex-col gap-1.5">
            <label className="text-sm font-medium" htmlFor="bodyEn">
              Contenu (anglais, optionnel)
            </label>
            <textarea
              id="bodyEn"
              rows={4}
              className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
              value={values.bodyEn}
              onChange={(e) => update("bodyEn", e.target.value)}
            />
          </div>

          {serverError ? <p className="text-sm font-medium text-destructive">{serverError}</p> : null}
          <Button type="submit" disabled={isSubmitting}>
            {isSubmitting ? "Enregistrement…" : isEdit ? "Enregistrer les modifications" : "Créer le template"}
          </Button>
        </form>
      </CardContent>
    </Card>
  );
}
