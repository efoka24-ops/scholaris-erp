"use client";

import { useState } from "react";
import { apiClient } from "@/lib/api-client";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";

// 6 catégories (matrice « Fonctionnalités par type »). Chacune est envoyée au
// backend via le type Prisma correspondant (`type`) ; le moteur de calcul est
// alors pré-configuré automatiquement (séquentiel, semestriel ou LMD).
const CATEGORIES = [
  { value: "PRIMAIRE", label: "Primaire (SIL → CM2)", type: "PRIMAIRE", hint: "Séquentiel, notes /10-/20, APC, examen CEP" },
  { value: "COLLEGE", label: "Collège (6ème → 3ème)", type: "SECONDAIRE", hint: "Séquentiel, coefficients MINESEC, examen BEPC" },
  { value: "LYCEE_GENERAL", label: "Lycée général (2nde → Tle)", type: "SECONDAIRE", hint: "Séquentiel, séries, Probatoire + Baccalauréat" },
  { value: "LYCEE_TECHNIQUE", label: "Lycée technique", type: "TECHNIQUE", hint: "Séquentiel/semestriel, CAP/BEP, Bac technique" },
  { value: "CENTRE_FORMATION", label: "Centre de formation", type: "FORMATION_PRO", hint: "Semestriel, modules, BTS/DUT (configurable)" },
  { value: "SUPERIEUR", label: "Institut supérieur / Université", type: "SUPERIEUR", hint: "LMD : UE/EC, crédits ECTS, GPA, semestres" },
];
const STATUSES = [
  { value: "PUBLIC", label: "Public" },
  { value: "PRIVE", label: "Privé" },
];
const fieldClass = "h-10 w-full rounded-md border border-border bg-background px-3 text-sm";

export default function CreateEstablishmentRequestPage() {
  const [form, setForm] = useState({
    name: "",
    code: "",
    category: "COLLEGE",
    status: "PUBLIC",
    address: "",
    phone: "",
    email: "",
    directorFirstName: "",
    directorLastName: "",
    directorEmail: "",
    directorPhone: "",
    website: "", // honeypot
  });
  const [error, setError] = useState<string | null>(null);
  const [submitted, setSubmitted] = useState(false);
  const [isSubmitting, setIsSubmitting] = useState(false);

  function set<K extends keyof typeof form>(key: K, value: string) {
    setForm((f) => ({ ...f, [key]: value }));
  }

  async function submit() {
    setError(null);
    setIsSubmitting(true);
    try {
      const mappedType = CATEGORIES.find((c) => c.value === form.category)?.type ?? "SECONDAIRE";
      const { data } = await apiClient.post("/public/establishment-requests", {
        name: form.name,
        code: form.code,
        type: mappedType,
        status: form.status,
        address: form.address || undefined,
        phone: form.phone || undefined,
        email: form.email || undefined,
        directorFirstName: form.directorFirstName,
        directorLastName: form.directorLastName,
        directorEmail: form.directorEmail,
        directorPhone: form.directorPhone || undefined,
        website: form.website,
      });
      if (!data.success) {
        setError(data.error ?? "Impossible d'envoyer la demande.");
        return;
      }
      setSubmitted(true);
    } catch (e: any) {
      setError(e.response?.data?.error ?? "Impossible d'envoyer la demande. Réessayez plus tard.");
    } finally {
      setIsSubmitting(false);
    }
  }

  if (submitted) {
    return (
      <div className="mx-auto flex w-full max-w-lg flex-1 flex-col justify-center px-4 py-16 sm:px-8">
        <Card>
          <CardHeader>
            <CardTitle>Demande envoyée</CardTitle>
            <CardDescription>Merci ! Votre demande de création d&apos;établissement a bien été transmise.</CardDescription>
          </CardHeader>
          <CardContent>
            <p className="text-sm text-muted-foreground">
              Notre équipe va étudier votre demande. Après validation, vous recevrez par email vos identifiants
              d&apos;administrateur d&apos;établissement pour vous connecter et configurer votre établissement.
            </p>
          </CardContent>
        </Card>
      </div>
    );
  }

  const canSubmit = form.name && form.code && form.directorFirstName && form.directorLastName && form.directorEmail;

  return (
    <div className="mx-auto flex w-full max-w-2xl flex-1 flex-col gap-6 px-4 py-10 sm:px-8">
      <div>
        <h1 className="text-2xl font-semibold">Créer votre établissement</h1>
        <p className="text-sm text-muted-foreground">
          Directeur d&apos;école ? Déposez votre demande. Après validation par notre équipe, vous recevrez vos
          identifiants pour administrer votre établissement.
        </p>
      </div>

      {/* Honeypot anti-bot : caché, ne jamais remplir */}
      <div className="hidden" aria-hidden="true">
        <label htmlFor="website">Site web</label>
        <input id="website" type="text" tabIndex={-1} autoComplete="off" value={form.website} onChange={(e) => set("website", e.target.value)} />
      </div>

      <Card>
        <CardHeader>
          <CardTitle className="text-base">Établissement</CardTitle>
        </CardHeader>
        <CardContent className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div className="flex flex-col gap-1.5">
            <Label>Nom *</Label>
            <Input value={form.name} onChange={(e) => set("name", e.target.value)} placeholder="Lycée Bilingue de Maroua" />
          </div>
          <div className="flex flex-col gap-1.5">
            <Label>Code *</Label>
            <Input value={form.code} onChange={(e) => set("code", e.target.value)} placeholder="EN/EXN/LBM" />
          </div>
          <div className="flex flex-col gap-1.5">
            <Label>Type d&apos;établissement *</Label>
            <select className={fieldClass} value={form.category} onChange={(e) => set("category", e.target.value)}>
              {CATEGORIES.map((c) => (
                <option key={c.value} value={c.value}>{c.label}</option>
              ))}
            </select>
            <p className="text-xs text-muted-foreground">
              {CATEGORIES.find((c) => c.value === form.category)?.hint}
            </p>
            <p className="text-xs text-muted-foreground">
              Le moteur de calcul et les menus seront pré-configurés automatiquement selon ce choix.
            </p>
          </div>
          <div className="flex flex-col gap-1.5">
            <Label>Statut *</Label>
            <select className={fieldClass} value={form.status} onChange={(e) => set("status", e.target.value)}>
              {STATUSES.map((s) => (
                <option key={s.value} value={s.value}>{s.label}</option>
              ))}
            </select>
          </div>
          <div className="flex flex-col gap-1.5 sm:col-span-2">
            <Label>Adresse</Label>
            <Input value={form.address} onChange={(e) => set("address", e.target.value)} />
          </div>
          <div className="flex flex-col gap-1.5">
            <Label>Téléphone établissement</Label>
            <Input value={form.phone} onChange={(e) => set("phone", e.target.value)} placeholder="+237…" />
          </div>
          <div className="flex flex-col gap-1.5">
            <Label>Email établissement</Label>
            <Input type="email" value={form.email} onChange={(e) => set("email", e.target.value)} />
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle className="text-base">Directeur (futur administrateur)</CardTitle>
          <CardDescription>Vous recevrez vos identifiants à cette adresse après validation.</CardDescription>
        </CardHeader>
        <CardContent className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div className="flex flex-col gap-1.5">
            <Label>Prénom *</Label>
            <Input value={form.directorFirstName} onChange={(e) => set("directorFirstName", e.target.value)} />
          </div>
          <div className="flex flex-col gap-1.5">
            <Label>Nom *</Label>
            <Input value={form.directorLastName} onChange={(e) => set("directorLastName", e.target.value)} />
          </div>
          <div className="flex flex-col gap-1.5">
            <Label>Email *</Label>
            <Input type="email" value={form.directorEmail} onChange={(e) => set("directorEmail", e.target.value)} />
          </div>
          <div className="flex flex-col gap-1.5">
            <Label>Téléphone</Label>
            <Input value={form.directorPhone} onChange={(e) => set("directorPhone", e.target.value)} placeholder="+237…" />
          </div>
        </CardContent>
      </Card>

      {error ? <p className="text-sm font-medium text-destructive">{error}</p> : null}

      <Button size="lg" disabled={!canSubmit || isSubmitting} onClick={submit}>
        {isSubmitting ? "Envoi en cours…" : "Envoyer la demande"}
      </Button>
    </div>
  );
}
