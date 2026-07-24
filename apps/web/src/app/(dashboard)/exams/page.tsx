"use client";

import { useCallback, useEffect, useState } from "react";
import Link from "next/link";
import { resourceClient } from "@/lib/api-client";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { LoadingSpinner } from "@/components/shared/loading-spinner";

interface Exam {
  id: string;
  name: string;
  code: string;
  academicYearId: string;
  levelId: string | null;
  registrationStart: string;
  registrationEnd: string;
  examStart: string | null;
  examEnd: string | null;
  feeAmount: string;
  maxAge: number | null;
  requiredSequences: number;
  isOfficial: boolean;
}

interface AcademicYear {
  id: string;
  label: string;
  status: string;
}
interface Level {
  id: string;
  name: string;
  code: string;
}

const EXAM_CODES = [
  { value: "CEP", label: "CEP (Certificat d'Études Primaires)" },
  { value: "BEPC", label: "BEPC (Brevet 1er cycle)" },
  { value: "PROBATOIRE", label: "Probatoire" },
  { value: "BAC", label: "Baccalauréat" },
  { value: "CUSTOM", label: "Examen configurable" },
];

const fieldClass = "h-10 w-full rounded-md border border-border bg-background px-3 text-sm";

export default function ExamsPage() {
  const [items, setItems] = useState<Exam[]>([]);
  const [years, setYears] = useState<AcademicYear[]>([]);
  const [levels, setLevels] = useState<Level[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [showForm, setShowForm] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);

  const [form, setForm] = useState({
    name: "",
    code: "BEPC",
    academicYearId: "",
    levelId: "",
    registrationStart: "",
    registrationEnd: "",
    examStart: "",
    examEnd: "",
    feeAmount: "5000",
    maxAge: "20",
    requiredSequences: "4",
  });

  const load = useCallback(() => {
    setIsLoading(true);
    Promise.all([
      resourceClient.get<Exam[]>("/exams").then((r) => r.data).catch(() => []),
      resourceClient.get<AcademicYear[]>("/academic-years").then((r) => r.data).catch(() => []),
      resourceClient.get<Level[]>("/levels").then((r) => r.data).catch(() => []),
    ]).then(([exams, ys, lvls]) => {
      setItems(exams);
      setYears(ys);
      setLevels(lvls);
      setForm((f) => ({
        ...f,
        academicYearId: f.academicYearId || ys.find((y) => y.status === "ACTIVE")?.id || ys[0]?.id || "",
      }));
      setIsLoading(false);
    });
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  function set<K extends keyof typeof form>(key: K, value: string) {
    setForm((f) => ({ ...f, [key]: value }));
  }

  async function submit() {
    setError(null);
    setSuccess(null);
    setIsSubmitting(true);
    try {
      const { data } = await resourceClient.post<Exam>("/exams", {
        name: form.name,
        code: form.code,
        academicYearId: form.academicYearId,
        levelId: form.levelId || undefined,
        registrationStart: form.registrationStart,
        registrationEnd: form.registrationEnd,
        examStart: form.examStart || undefined,
        examEnd: form.examEnd || undefined,
        feeAmount: form.feeAmount ? Number(form.feeAmount) : undefined,
        maxAge: form.maxAge ? Number(form.maxAge) : undefined,
        requiredSequences: form.requiredSequences ? Number(form.requiredSequences) : undefined,
        isOfficial: form.code !== "CUSTOM",
      });
      setSuccess(`Examen « ${data.name} » créé.`);
      setShowForm(false);
      setForm((f) => ({ ...f, name: "" }));
      load();
    } catch (e: any) {
      setError(e.response?.data?.message ?? "Impossible de créer l'examen.");
    } finally {
      setIsSubmitting(false);
    }
  }

  if (isLoading) return <LoadingSpinner label="Chargement des examens…" />;

  const fmt = (d: string | null) => (d ? new Date(d).toLocaleDateString("fr-FR") : "—");

  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">Examens officiels</h1>
          <p className="text-sm text-muted-foreground">
            CEP · BEPC · Probatoire · Baccalauréat et examens configurables — inscription, résultats, mentions
          </p>
        </div>
        <Button onClick={() => setShowForm((s) => !s)}>{showForm ? "Fermer" : "Nouvel examen"}</Button>
      </div>

      {error ? <p className="text-sm font-medium text-destructive">{error}</p> : null}
      {success ? <p className="text-sm font-medium text-primary">{success}</p> : null}

      {showForm ? (
        <Card>
          <CardHeader>
            <CardTitle>Nouvel examen</CardTitle>
            <CardDescription>Conditions d&apos;inscription et calendrier</CardDescription>
          </CardHeader>
          <CardContent>
            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
              <div className="flex flex-col gap-1.5">
                <Label>Nom *</Label>
                <Input value={form.name} onChange={(e) => set("name", e.target.value)} placeholder="BEPC Session 2027" />
              </div>
              <div className="flex flex-col gap-1.5">
                <Label>Type d&apos;examen *</Label>
                <select className={fieldClass} value={form.code} onChange={(e) => set("code", e.target.value)}>
                  {EXAM_CODES.map((c) => (
                    <option key={c.value} value={c.value}>{c.label}</option>
                  ))}
                </select>
              </div>
              <div className="flex flex-col gap-1.5">
                <Label>Année académique *</Label>
                <select className={fieldClass} value={form.academicYearId} onChange={(e) => set("academicYearId", e.target.value)}>
                  {years.map((y) => (
                    <option key={y.id} value={y.id}>{y.label}{y.status === "ACTIVE" ? " (active)" : ""}</option>
                  ))}
                </select>
              </div>
              <div className="flex flex-col gap-1.5">
                <Label>Niveau</Label>
                <select className={fieldClass} value={form.levelId} onChange={(e) => set("levelId", e.target.value)}>
                  <option value="">— Tous —</option>
                  {levels.map((l) => (
                    <option key={l.id} value={l.id}>{l.name}</option>
                  ))}
                </select>
              </div>
              <div className="flex flex-col gap-1.5">
                <Label>Ouverture inscriptions *</Label>
                <Input type="date" value={form.registrationStart} onChange={(e) => set("registrationStart", e.target.value)} />
              </div>
              <div className="flex flex-col gap-1.5">
                <Label>Clôture inscriptions *</Label>
                <Input type="date" value={form.registrationEnd} onChange={(e) => set("registrationEnd", e.target.value)} />
              </div>
              <div className="flex flex-col gap-1.5">
                <Label>Début épreuves</Label>
                <Input type="date" value={form.examStart} onChange={(e) => set("examStart", e.target.value)} />
              </div>
              <div className="flex flex-col gap-1.5">
                <Label>Fin épreuves</Label>
                <Input type="date" value={form.examEnd} onChange={(e) => set("examEnd", e.target.value)} />
              </div>
              <div className="flex flex-col gap-1.5">
                <Label>Frais (FCFA)</Label>
                <Input type="number" value={form.feeAmount} onChange={(e) => set("feeAmount", e.target.value)} />
              </div>
              <div className="flex flex-col gap-1.5">
                <Label>Âge maximum</Label>
                <Input type="number" value={form.maxAge} onChange={(e) => set("maxAge", e.target.value)} />
              </div>
              <div className="flex flex-col gap-1.5">
                <Label>Séquences minimum composées</Label>
                <Input type="number" value={form.requiredSequences} onChange={(e) => set("requiredSequences", e.target.value)} />
              </div>
            </div>
            <Button
              disabled={!form.name || !form.academicYearId || !form.registrationStart || !form.registrationEnd || isSubmitting}
              onClick={submit}
              className="mt-4 w-fit"
            >
              {isSubmitting ? "Création…" : "Créer l'examen"}
            </Button>
          </CardContent>
        </Card>
      ) : null}

      <Card>
        <CardHeader>
          <CardTitle>Examens ({items.length})</CardTitle>
        </CardHeader>
        <CardContent>
          {items.length === 0 ? (
            <p className="py-6 text-center text-sm text-muted-foreground">Aucun examen.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-border text-left text-muted-foreground">
                    <th className="py-2 pr-4 font-medium">Examen</th>
                    <th className="py-2 pr-4 font-medium">Type</th>
                    <th className="py-2 pr-4 font-medium">Inscriptions</th>
                    <th className="py-2 pr-4 font-medium">Épreuves</th>
                    <th className="py-2 pr-4 font-medium">Frais</th>
                    <th className="py-2 pr-4 font-medium"></th>
                  </tr>
                </thead>
                <tbody>
                  {items.map((e) => (
                    <tr key={e.id} className="border-b border-border/50">
                      <td className="py-2 pr-4 font-medium">{e.name}</td>
                      <td className="py-2 pr-4">
                        <span className="rounded bg-secondary px-2 py-0.5 text-xs font-mono">{e.code}</span>
                      </td>
                      <td className="py-2 pr-4 text-muted-foreground">{fmt(e.registrationStart)} → {fmt(e.registrationEnd)}</td>
                      <td className="py-2 pr-4 text-muted-foreground">{fmt(e.examStart)} → {fmt(e.examEnd)}</td>
                      <td className="py-2 pr-4">{Number(e.feeAmount).toLocaleString("fr-FR")} F</td>
                      <td className="py-2 pr-4">
                        <Link href={`/exams/${e.id}`} className="text-primary hover:underline">Gérer</Link>
                      </td>
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
