"use client";

import { useEffect, useState } from "react";
import { Plus, Trash2 } from "lucide-react";
import { resourceClient } from "@/lib/api-client";
import type { AcademicYear } from "@/types/students";
import type { Level } from "@/types/structure";
import { formatAmount, type FeeStructure } from "@/types/finance";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { LoadingSpinner } from "@/components/shared/loading-spinner";

interface InstallmentDraft {
  label: string;
  amount: string;
  dueDate: string;
  order: number;
}

const EMPTY_INSTALLMENT = (order: number): InstallmentDraft => ({ label: "", amount: "", dueDate: "", order });

export default function FeeStructuresPage() {
  const [structures, setStructures] = useState<FeeStructure[]>([]);
  const [levels, setLevels] = useState<Level[]>([]);
  const [years, setYears] = useState<AcademicYear[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [showForm, setShowForm] = useState(false);

  const [name, setName] = useState("");
  const [levelId, setLevelId] = useState("");
  const [academicYearId, setAcademicYearId] = useState("");
  const [totalAmount, setTotalAmount] = useState("");
  const [installments, setInstallments] = useState<InstallmentDraft[]>([EMPTY_INSTALLMENT(1)]);
  const [error, setError] = useState<string | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);

  function load() {
    setIsLoading(true);
    resourceClient
      .get<FeeStructure[]>("/fee-structures")
      .then((response) => setStructures(response.data))
      .finally(() => setIsLoading(false));
  }

  useEffect(() => {
    load();
    resourceClient.get<Level[]>("/levels").then((response) => setLevels(response.data));
    resourceClient.get<AcademicYear[]>("/academic-years").then((response) => setYears(response.data));
  }, []);

  function updateInstallment(index: number, patch: Partial<InstallmentDraft>) {
    setInstallments((current) => current.map((item, i) => (i === index ? { ...item, ...patch } : item)));
  }

  async function submit() {
    setError(null);
    setIsSubmitting(true);
    try {
      await resourceClient.post("/fee-structures", {
        name,
        ...(levelId ? { levelId } : {}),
        academicYearId,
        totalAmount: Number(totalAmount),
        installments: installments.map((installment) => ({
          label: installment.label,
          amount: Number(installment.amount),
          dueDate: installment.dueDate,
          order: installment.order,
        })),
      });
      setShowForm(false);
      setName("");
      setLevelId("");
      setAcademicYearId("");
      setTotalAmount("");
      setInstallments([EMPTY_INSTALLMENT(1)]);
      load();
    } catch (submitError: any) {
      setError(submitError.response?.data?.message ?? "Impossible de créer la grille tarifaire.");
    } finally {
      setIsSubmitting(false);
    }
  }

  const installmentsSum = installments.reduce((sum, i) => sum + (Number(i.amount) || 0), 0);

  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">Grilles tarifaires</h1>
          <p className="text-sm text-muted-foreground">Frais de scolarité par niveau et année académique</p>
        </div>
        <Button size="sm" onClick={() => setShowForm((value) => !value)}>
          <Plus className="mr-2 h-4 w-4" />
          Nouvelle grille
        </Button>
      </div>

      {showForm ? (
        <Card>
          <CardHeader>
            <CardTitle>Nouvelle grille tarifaire</CardTitle>
            <CardDescription>Le niveau est facultatif : laissez vide pour l'appliquer à tous les niveaux.</CardDescription>
          </CardHeader>
          <CardContent className="flex flex-col gap-4">
            <div className="grid grid-cols-2 gap-4">
              <div className="flex flex-col gap-1.5">
                <Label>Nom *</Label>
                <Input value={name} onChange={(e) => setName(e.target.value)} placeholder="Scolarité 6ème 2026-2027" />
              </div>
              <div className="flex flex-col gap-1.5">
                <Label>Montant total *</Label>
                <Input type="number" min={0} value={totalAmount} onChange={(e) => setTotalAmount(e.target.value)} />
              </div>
              <div className="flex flex-col gap-1.5">
                <Label>Niveau</Label>
                <select
                  className="h-10 w-full rounded-md border border-border bg-background px-3 text-sm"
                  value={levelId}
                  onChange={(e) => setLevelId(e.target.value)}
                >
                  <option value="">Tous les niveaux</option>
                  {levels.map((level) => (
                    <option key={level.id} value={level.id}>
                      {level.name}
                    </option>
                  ))}
                </select>
              </div>
              <div className="flex flex-col gap-1.5">
                <Label>Année académique *</Label>
                <select
                  className="h-10 w-full rounded-md border border-border bg-background px-3 text-sm"
                  value={academicYearId}
                  onChange={(e) => setAcademicYearId(e.target.value)}
                >
                  <option value="">Sélectionner…</option>
                  {years.map((year) => (
                    <option key={year.id} value={year.id}>
                      {year.label}
                    </option>
                  ))}
                </select>
              </div>
            </div>

            <div className="flex flex-col gap-2">
              <div className="flex items-center justify-between">
                <Label>Tranches / échéances</Label>
                <span className="text-xs text-muted-foreground">
                  Somme des tranches : {formatAmount(installmentsSum)}
                </span>
              </div>
              {installments.map((installment, index) => (
                <div key={index} className="grid grid-cols-[2fr_1fr_1fr_auto] items-end gap-2">
                  <div className="flex flex-col gap-1">
                    <Label className="text-xs">Libellé</Label>
                    <Input
                      value={installment.label}
                      onChange={(e) => updateInstallment(index, { label: e.target.value })}
                      placeholder="1ère tranche"
                    />
                  </div>
                  <div className="flex flex-col gap-1">
                    <Label className="text-xs">Montant</Label>
                    <Input
                      type="number"
                      min={0}
                      value={installment.amount}
                      onChange={(e) => updateInstallment(index, { amount: e.target.value })}
                    />
                  </div>
                  <div className="flex flex-col gap-1">
                    <Label className="text-xs">Échéance</Label>
                    <Input
                      type="date"
                      value={installment.dueDate}
                      onChange={(e) => updateInstallment(index, { dueDate: e.target.value })}
                    />
                  </div>
                  <Button
                    variant="outline"
                    size="sm"
                    disabled={installments.length <= 1}
                    onClick={() => setInstallments((current) => current.filter((_, i) => i !== index))}
                  >
                    <Trash2 className="h-4 w-4" />
                  </Button>
                </div>
              ))}
              <Button
                variant="outline"
                size="sm"
                className="self-start"
                onClick={() => setInstallments((current) => [...current, EMPTY_INSTALLMENT(current.length + 1)])}
              >
                Ajouter une tranche
              </Button>
            </div>

            {error ? <p className="text-sm font-medium text-destructive">{error}</p> : null}

            <div className="flex gap-2">
              <Button disabled={isSubmitting} onClick={submit}>
                {isSubmitting ? "Création…" : "Créer la grille"}
              </Button>
              <Button variant="outline" onClick={() => setShowForm(false)}>
                Annuler
              </Button>
            </div>
          </CardContent>
        </Card>
      ) : null}

      {isLoading ? (
        <LoadingSpinner label="Chargement des grilles…" />
      ) : structures.length === 0 ? (
        <p className="text-sm text-muted-foreground">Aucune grille tarifaire pour l'instant.</p>
      ) : (
        <div className="grid gap-4 md:grid-cols-2">
          {structures.map((structure) => (
            <Card key={structure.id}>
              <CardHeader>
                <CardTitle className="text-base">{structure.name}</CardTitle>
                <CardDescription>
                  {structure.level?.name ?? "Tous les niveaux"} — {structure.academicYear?.label ?? "—"}
                </CardDescription>
              </CardHeader>
              <CardContent className="flex flex-col gap-2">
                <p className="text-sm font-medium">Montant total : {formatAmount(structure.totalAmount)}</p>
                <ul className="flex flex-col gap-1 text-sm text-muted-foreground">
                  {structure.installments.map((installment) => (
                    <li key={installment.id} className="flex justify-between">
                      <span>{installment.label}</span>
                      <span>
                        {formatAmount(installment.amount)} — {new Date(installment.dueDate).toLocaleDateString("fr-FR")}
                      </span>
                    </li>
                  ))}
                </ul>
              </CardContent>
            </Card>
          ))}
        </div>
      )}
    </div>
  );
}
