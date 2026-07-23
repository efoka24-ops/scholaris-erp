"use client";

import { useCallback, useEffect, useState } from "react";
import { DEFAULT_CALCULATION_ENGINE_CONFIG } from "@scholaris/shared";
import { resourceClient } from "@/lib/api-client";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { LoadingSpinner } from "@/components/shared/loading-spinner";

interface Establishment {
  id: string;
  code: string;
  name: string;
  type: string;
  status: string;
  address: string | null;
  phone: string | null;
  email: string | null;
}

const TYPES = [
  { value: "PRIMAIRE", label: "Primaire" },
  { value: "SECONDAIRE", label: "Secondaire général" },
  { value: "SUPERIEUR", label: "Supérieur" },
  { value: "TECHNIQUE", label: "Technique" },
  { value: "FORMATION_PRO", label: "Formation professionnelle" },
];
const STATUSES = [
  { value: "PUBLIC", label: "Public" },
  { value: "PRIVE", label: "Privé" },
];
const EVALUATION_TYPES = [
  { value: "SEQUENTIAL", label: "Séquentiel (secondaire général)" },
  { value: "TRIMESTER", label: "Trimestriel (primaire)" },
  { value: "SEMESTER", label: "Semestriel (technique / pro)" },
  { value: "LMD", label: "LMD (supérieur)" },
];
const ROUNDING_RULES = [
  { value: "HUNDREDTH", label: "Centième (14,25)" },
  { value: "TENTH", label: "Dixième (14,3)" },
  { value: "HALF_POINT", label: "Demi-point (14,5)" },
  { value: "INTEGER", label: "Entier (14)" },
  { value: "NONE", label: "Aucun" },
];
const ABSENCE_RULES = [
  { value: "ZERO", label: "Note zéro" },
  { value: "NEUTRALIZED", label: "Neutralisée (exclue du calcul)" },
  { value: "POSTPONED", label: "Reportée" },
];

const fieldClass = "h-10 w-full rounded-md border border-border bg-background px-3 text-sm";

export default function EstablishmentsPage() {
  const [items, setItems] = useState<Establishment[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [showForm, setShowForm] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);

  const [form, setForm] = useState({
    name: "",
    code: "",
    type: "SECONDAIRE",
    status: "PUBLIC",
    address: "",
    phone: "",
    email: "",
    evaluationType: "SEQUENTIAL",
    sequenceWeights: "1, 1",
    trimesterWeights: "3, 3, 4",
    roundingRule: "HUNDREDTH",
    absenceRule: "ZERO",
  });

  const load = useCallback(() => {
    setIsLoading(true);
    resourceClient
      .get<Establishment[]>("/tenants")
      .then((r) => setItems(r.data))
      .catch(() => setItems([]))
      .finally(() => setIsLoading(false));
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  function set<K extends keyof typeof form>(key: K, value: string) {
    setForm((f) => ({ ...f, [key]: value }));
  }

  function parseWeights(raw: string): number[] {
    return raw
      .split(/[,;\s]+/)
      .map((s) => Number(s.trim()))
      .filter((n) => !Number.isNaN(n) && n > 0);
  }

  async function submit() {
    setError(null);
    setSuccess(null);
    setIsSubmitting(true);
    try {
      const config = {
        ...DEFAULT_CALCULATION_ENGINE_CONFIG,
        evaluationType: form.evaluationType,
        roundingRule: form.roundingRule,
        absenceRule: form.absenceRule,
        sequenceWeights: parseWeights(form.sequenceWeights),
        trimesterWeights: parseWeights(form.trimesterWeights),
      };
      const { data } = await resourceClient.post<Establishment>("/tenants", {
        name: form.name,
        code: form.code,
        type: form.type,
        status: form.status,
        address: form.address || undefined,
        phone: form.phone || undefined,
        email: form.email || undefined,
        config,
      });
      setSuccess(`Établissement « ${data.name} » créé avec succès.`);
      setForm((f) => ({ ...f, name: "", code: "", address: "", phone: "", email: "" }));
      setShowForm(false);
      load();
    } catch (e: any) {
      setError(e.response?.data?.message ?? "Impossible de créer l'établissement.");
    } finally {
      setIsSubmitting(false);
    }
  }

  if (isLoading) return <LoadingSpinner label="Chargement des établissements…" />;

  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">Établissements</h1>
          <p className="text-sm text-muted-foreground">
            Gestion multi-établissements — création et vue d&apos;ensemble (Super Admin)
          </p>
        </div>
        <Button onClick={() => setShowForm((s) => !s)}>{showForm ? "Fermer" : "Nouvel établissement"}</Button>
      </div>

      {error ? <p className="text-sm font-medium text-destructive">{error}</p> : null}
      {success ? <p className="text-sm font-medium text-primary">{success}</p> : null}

      {showForm ? (
        <Card>
          <CardHeader>
            <CardTitle>Nouvel établissement</CardTitle>
            <CardDescription>Informations générales et configuration du moteur de calcul</CardDescription>
          </CardHeader>
          <CardContent className="flex flex-col gap-6">
            <div>
              <h3 className="mb-3 text-sm font-semibold text-muted-foreground">Informations générales</h3>
              <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div className="flex flex-col gap-1.5">
                  <Label>Nom *</Label>
                  <Input value={form.name} onChange={(e) => set("name", e.target.value)} placeholder="Lycée Bilingue de Maroua" />
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label>Code *</Label>
                  <Input value={form.code} onChange={(e) => set("code", e.target.value)} placeholder="EN/EXN/LBM" />
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label>Type *</Label>
                  <select className={fieldClass} value={form.type} onChange={(e) => set("type", e.target.value)}>
                    {TYPES.map((t) => (
                      <option key={t.value} value={t.value}>{t.label}</option>
                    ))}
                  </select>
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label>Statut *</Label>
                  <select className={fieldClass} value={form.status} onChange={(e) => set("status", e.target.value)}>
                    {STATUSES.map((s) => (
                      <option key={s.value} value={s.value}>{s.label}</option>
                    ))}
                  </select>
                </div>
                <div className="flex flex-col gap-1.5 md:col-span-2">
                  <Label>Adresse</Label>
                  <Input value={form.address} onChange={(e) => set("address", e.target.value)} placeholder="Quartier Domayo, BP 46, Maroua, Extrême-Nord" />
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label>Téléphone</Label>
                  <Input value={form.phone} onChange={(e) => set("phone", e.target.value)} placeholder="+237699000001" />
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label>Email</Label>
                  <Input type="email" value={form.email} onChange={(e) => set("email", e.target.value)} placeholder="lbm@minesec.gov.cm" />
                </div>
              </div>
            </div>

            <div>
              <h3 className="mb-3 text-sm font-semibold text-muted-foreground">Moteur de calcul</h3>
              <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div className="flex flex-col gap-1.5">
                  <Label>Type d&apos;évaluation</Label>
                  <select className={fieldClass} value={form.evaluationType} onChange={(e) => set("evaluationType", e.target.value)}>
                    {EVALUATION_TYPES.map((t) => (
                      <option key={t.value} value={t.value}>{t.label}</option>
                    ))}
                  </select>
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label>Arrondi</Label>
                  <select className={fieldClass} value={form.roundingRule} onChange={(e) => set("roundingRule", e.target.value)}>
                    {ROUNDING_RULES.map((r) => (
                      <option key={r.value} value={r.value}>{r.label}</option>
                    ))}
                  </select>
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label>Pondération des séquences (par trimestre)</Label>
                  <Input value={form.sequenceWeights} onChange={(e) => set("sequenceWeights", e.target.value)} placeholder="1, 1" />
                  <span className="text-xs text-muted-foreground">Ex : « 1, 1 » = Séq1 50 % / Séq2 50 %</span>
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label>Pondération des trimestres (moyenne annuelle)</Label>
                  <Input value={form.trimesterWeights} onChange={(e) => set("trimesterWeights", e.target.value)} placeholder="3, 3, 4" />
                  <span className="text-xs text-muted-foreground">Ex : « 3, 3, 4 » = T1 30 % / T2 30 % / T3 40 %</span>
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label>Absence non justifiée</Label>
                  <select className={fieldClass} value={form.absenceRule} onChange={(e) => set("absenceRule", e.target.value)}>
                    {ABSENCE_RULES.map((a) => (
                      <option key={a.value} value={a.value}>{a.label}</option>
                    ))}
                  </select>
                </div>
              </div>
              <p className="mt-2 text-xs text-muted-foreground">
                Barème /20, seuil de réussite 10/20, mentions par défaut (Passable ≥10, AB ≥12, B ≥14, TB ≥16,
                Excellent ≥18) — ajustables ensuite dans « Moteur de calcul ».
              </p>
            </div>

            <Button disabled={!form.name || !form.code || isSubmitting} onClick={submit} className="w-fit">
              {isSubmitting ? "Création…" : "Créer l'établissement"}
            </Button>
          </CardContent>
        </Card>
      ) : null}

      <Card>
        <CardHeader>
          <CardTitle>Liste des établissements ({items.length})</CardTitle>
        </CardHeader>
        <CardContent>
          {items.length === 0 ? (
            <p className="py-6 text-center text-sm text-muted-foreground">Aucun établissement.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-border text-left text-muted-foreground">
                    <th className="py-2 pr-4 font-medium">Code</th>
                    <th className="py-2 pr-4 font-medium">Nom</th>
                    <th className="py-2 pr-4 font-medium">Type</th>
                    <th className="py-2 pr-4 font-medium">Statut</th>
                    <th className="py-2 pr-4 font-medium">Contact</th>
                  </tr>
                </thead>
                <tbody>
                  {items.map((t) => (
                    <tr key={t.id} className="border-b border-border/50">
                      <td className="py-2 pr-4 font-mono text-xs">{t.code}</td>
                      <td className="py-2 pr-4 font-medium">{t.name}</td>
                      <td className="py-2 pr-4">{TYPES.find((x) => x.value === t.type)?.label ?? t.type}</td>
                      <td className="py-2 pr-4">{STATUSES.find((x) => x.value === t.status)?.label ?? t.status}</td>
                      <td className="py-2 pr-4 text-muted-foreground">{t.phone ?? t.email ?? "—"}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
