"use client";

import { useState } from "react";
import Link from "next/link";
import { resourceClient } from "@/lib/api-client";
import { downloadBase64 } from "@/lib/download";
import type { ImportReport } from "@/types/students";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";

interface ValidationRow {
  row: number;
  name: string;
  status: "valid" | "warning" | "error";
  messages: string[];
}
interface ValidationReport {
  total: number;
  valid: number;
  warnings: number;
  errors: number;
  rows: ValidationRow[];
}

function fileToBase64(file: File): Promise<string> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => {
      const result = reader.result as string;
      resolve(result.slice(result.indexOf(",") + 1));
    };
    reader.onerror = () => reject(reader.error);
    reader.readAsDataURL(file);
  });
}

const STATUS_STYLE: Record<string, string> = {
  valid: "text-primary",
  warning: "text-amber-600",
  error: "text-destructive",
};
const STATUS_LABEL: Record<string, string> = {
  valid: "Valide",
  warning: "Avertissement",
  error: "Erreur",
};

export default function ImportStudentsPage() {
  const [file, setFile] = useState<File | null>(null);
  const [base64, setBase64] = useState<string | null>(null);
  const [validation, setValidation] = useState<ValidationReport | null>(null);
  const [report, setReport] = useState<ImportReport | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [isBusy, setIsBusy] = useState(false);

  async function onSelect(f: File | null) {
    setFile(f);
    setValidation(null);
    setReport(null);
    setError(null);
    setBase64(f ? await fileToBase64(f) : null);
  }

  async function validate() {
    if (!base64 || !file) return;
    setIsBusy(true);
    setError(null);
    setReport(null);
    try {
      const { data } = await resourceClient.post<ValidationReport>(
        "/students/import/validate",
        { filename: file.name, contentBase64: base64 },
        { timeout: 120_000 },
      );
      setValidation(data);
    } catch (e: any) {
      setError(e.response?.data?.message ?? "Validation impossible.");
    } finally {
      setIsBusy(false);
    }
  }

  async function confirmImport() {
    if (!base64 || !file) return;
    setIsBusy(true);
    setError(null);
    try {
      const { data } = await resourceClient.post<ImportReport>(
        "/students/import",
        { filename: file.name, contentBase64: base64 },
        { timeout: 300_000 },
      );
      setReport(data);
      setValidation(null);
    } catch (e: any) {
      setError(e.response?.data?.message ?? "Import impossible.");
    } finally {
      setIsBusy(false);
    }
  }

  return (
    <div className="flex max-w-3xl flex-col gap-6">
      <div>
        <p className="text-sm text-muted-foreground">
          <Link href="/students" className="hover:underline">Élèves</Link> / Import Excel
        </p>
        <h1 className="text-2xl font-semibold">Importer des élèves</h1>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>1. Télécharger le template</CardTitle>
          <CardDescription>
            Colonnes : Nom, Prénom, Sexe (M/F), Date_naissance (JJ/MM/AAAA), Lieu_naissance, Nationalité, Classe_code
            (inscription auto), Parent_nom, Parent_prénom, Parent_téléphone, Parent_email, Parent_relation.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <Button variant="outline" onClick={() => downloadBase64("/students/import/template")}>
            Télécharger le template Excel
          </Button>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>2. Charger et valider le fichier</CardTitle>
          <CardDescription>La validation vérifie les champs, les doublons et les classes avant tout import.</CardDescription>
        </CardHeader>
        <CardContent className="flex flex-col gap-4">
          <input
            type="file"
            accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
            className="text-sm"
            onChange={(e) => onSelect(e.target.files?.[0] ?? null)}
          />
          <Button className="w-fit" disabled={!file || isBusy} onClick={validate}>
            {isBusy ? "Traitement…" : "Valider le fichier"}
          </Button>
          {error ? <p className="text-sm font-medium text-destructive">{error}</p> : null}
        </CardContent>
      </Card>

      {validation ? (
        <Card>
          <CardHeader>
            <CardTitle>Rapport de pré-import</CardTitle>
            <CardDescription>Vérifiez avant de confirmer. Seules les lignes en erreur ne seront pas importées.</CardDescription>
          </CardHeader>
          <CardContent className="flex flex-col gap-4">
            <div className="grid grid-cols-4 gap-3 text-center">
              <Stat value={validation.total} label="lignes" />
              <Stat value={validation.valid} label="valides" className="text-primary" />
              <Stat value={validation.warnings} label="avertissements" className="text-amber-600" />
              <Stat value={validation.errors} label="erreurs" className="text-destructive" />
            </div>
            <div className="max-h-80 overflow-auto rounded-md border border-border">
              <table className="w-full text-sm">
                <thead className="sticky top-0 bg-secondary/60 text-left">
                  <tr>
                    <th className="px-3 py-2 font-medium">Ligne</th>
                    <th className="px-3 py-2 font-medium">Élève</th>
                    <th className="px-3 py-2 font-medium">Statut</th>
                    <th className="px-3 py-2 font-medium">Détails</th>
                  </tr>
                </thead>
                <tbody>
                  {validation.rows
                    .filter((r) => r.status !== "valid")
                    .map((r) => (
                      <tr key={r.row} className="border-t border-border">
                        <td className="px-3 py-2">{r.row}</td>
                        <td className="px-3 py-2">{r.name || "—"}</td>
                        <td className={`px-3 py-2 font-medium ${STATUS_STYLE[r.status]}`}>{STATUS_LABEL[r.status]}</td>
                        <td className="px-3 py-2 text-muted-foreground">{r.messages.join(" · ")}</td>
                      </tr>
                    ))}
                  {validation.rows.every((r) => r.status === "valid") ? (
                    <tr><td colSpan={4} className="px-3 py-4 text-center text-muted-foreground">Toutes les lignes sont valides ✓</td></tr>
                  ) : null}
                </tbody>
              </table>
            </div>
            <Button className="w-fit" disabled={isBusy || validation.total - validation.errors === 0} onClick={confirmImport}>
              {isBusy ? "Import en cours…" : `Confirmer l'import (${validation.total - validation.errors} élève(s))`}
            </Button>
          </CardContent>
        </Card>
      ) : null}

      {report ? (
        <Card>
          <CardHeader>
            <CardTitle>Import terminé</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-col gap-4">
            <div className="grid grid-cols-4 gap-3 text-center">
              <Stat value={report.created} label="créé(s)" className="text-primary" />
              <Stat value={report.enrolled ?? 0} label="inscrit(s)" />
              <Stat value={report.duplicates} label="doublon(s)" />
              <Stat value={report.errors.length} label="erreur(s)" className="text-destructive" />
            </div>
            {report.classesCreated && report.classesCreated.length > 0 ? (
              <p className="text-sm text-muted-foreground">
                Classes créées automatiquement : <strong>{report.classesCreated.join(", ")}</strong>
              </p>
            ) : null}
            {report.errors.length > 0 ? (
              <div className="max-h-64 overflow-auto rounded-md border border-border">
                <table className="w-full text-sm">
                  <thead className="bg-secondary/60 text-left"><tr><th className="px-3 py-2 font-medium">Ligne</th><th className="px-3 py-2 font-medium">Message</th></tr></thead>
                  <tbody>
                    {report.errors.map((e) => (
                      <tr key={`${e.row}-${e.message}`} className="border-t border-border">
                        <td className="px-3 py-2">{e.row}</td>
                        <td className="px-3 py-2">{e.message}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            ) : null}
            <Button asChild variant="outline" className="w-fit">
              <Link href="/students">Voir la liste des élèves</Link>
            </Button>
          </CardContent>
        </Card>
      ) : null}
    </div>
  );
}

function Stat({ value, label, className }: { value: number; label: string; className?: string }) {
  return (
    <div className="rounded-md border border-border p-3">
      <p className={`text-2xl font-semibold ${className ?? ""}`}>{value}</p>
      <p className="text-xs text-muted-foreground">{label}</p>
    </div>
  );
}
