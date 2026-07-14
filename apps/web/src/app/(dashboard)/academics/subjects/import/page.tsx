"use client";

import { useState } from "react";
import Link from "next/link";
import { ArrowLeft, FileUp } from "lucide-react";
import { SUBJECT_CATEGORY_LABELS, type SubjectImportReport } from "@scholaris/shared";
import { resourceClient } from "@/lib/api-client";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { LoadingSpinner } from "@/components/shared/loading-spinner";

/** Lit un fichier et renvoie son contenu encodé en base64 (sans le préfixe data:). */
function readFileAsBase64(file: File): Promise<string> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => {
      const result = String(reader.result ?? "");
      resolve(result.slice(result.indexOf(",") + 1));
    };
    reader.onerror = () => reject(reader.error);
    reader.readAsDataURL(file);
  });
}

export default function ImportSubjectsPage() {
  const [file, setFile] = useState<File | null>(null);
  const [fileBase64, setFileBase64] = useState<string | null>(null);
  const [preview, setPreview] = useState<SubjectImportReport | null>(null);
  const [report, setReport] = useState<SubjectImportReport | null>(null);
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleFileChange(selected: File | null) {
    setFile(selected);
    setPreview(null);
    setReport(null);
    setError(null);
    if (!selected) {
      setFileBase64(null);
      return;
    }
    setIsLoading(true);
    try {
      const base64 = await readFileAsBase64(selected);
      setFileBase64(base64);
      // Prévisualisation : parse + validation côté serveur, sans rien créer.
      const response = await resourceClient.post<SubjectImportReport>("/subjects/import", {
        fileBase64: base64,
        filename: selected.name,
        dryRun: true,
      });
      setPreview(response.data);
    } catch (requestError: any) {
      setError(requestError.response?.data?.message ?? "Impossible de lire le fichier.");
      setFileBase64(null);
    } finally {
      setIsLoading(false);
    }
  }

  async function handleImport() {
    if (!fileBase64) return;
    setIsLoading(true);
    setError(null);
    try {
      const response = await resourceClient.post<SubjectImportReport>("/subjects/import", {
        fileBase64,
        filename: file?.name,
      });
      setReport(response.data);
      setPreview(null);
    } catch (requestError: any) {
      setError(requestError.response?.data?.message ?? "L'import a échoué.");
    } finally {
      setIsLoading(false);
    }
  }

  const shown = report ?? preview;

  return (
    <div className="flex max-w-3xl flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">Import de matières</h1>
          <p className="text-sm text-muted-foreground">
            Classeur Excel (.xlsx) avec les colonnes : code, nom, coefficient, heures, catégorie
          </p>
        </div>
        <Button asChild variant="outline" size="sm">
          <Link href="/academics/subjects">
            <ArrowLeft className="mr-2 h-4 w-4" />
            Retour aux matières
          </Link>
        </Button>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>1. Sélectionner le fichier</CardTitle>
          <CardDescription>
            Catégories acceptées : littéraire, scientifique, technique, langue, sport.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <input
            type="file"
            accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
            onChange={(event) => handleFileChange(event.target.files?.[0] ?? null)}
            className="text-sm"
          />
        </CardContent>
      </Card>

      {isLoading ? <LoadingSpinner label="Analyse du fichier…" /> : null}
      {error ? <p className="text-sm font-medium text-destructive">{error}</p> : null}

      {shown ? (
        <Card>
          <CardHeader>
            <CardTitle>{report ? "3. Rapport d'import" : "2. Prévisualisation"}</CardTitle>
            <CardDescription>
              {report
                ? `${report.created} matière(s) créée(s), ${report.errors.length} erreur(s).`
                : `${shown.rows.length} ligne(s) prête(s) à être importée(s), ${shown.errors.length} erreur(s) détectée(s).`}
            </CardDescription>
          </CardHeader>
          <CardContent className="flex flex-col gap-4">
            {shown.rows.length > 0 && !report ? (
              <div className="overflow-x-auto rounded-md border border-border">
                <table className="w-full text-sm">
                  <thead className="bg-secondary/60 text-left">
                    <tr>
                      <th className="px-4 py-2 font-medium">Code</th>
                      <th className="px-4 py-2 font-medium">Nom</th>
                      <th className="px-4 py-2 font-medium">Coefficient</th>
                      <th className="px-4 py-2 font-medium">Heures</th>
                      <th className="px-4 py-2 font-medium">Catégorie</th>
                    </tr>
                  </thead>
                  <tbody>
                    {shown.rows.map((row) => (
                      <tr key={row.code} className="border-t border-border">
                        <td className="px-4 py-2">{row.code}</td>
                        <td className="px-4 py-2">{row.name}</td>
                        <td className="px-4 py-2">{row.coefficient}</td>
                        <td className="px-4 py-2">{row.weeklyHours}</td>
                        <td className="px-4 py-2">{SUBJECT_CATEGORY_LABELS[row.category]}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            ) : null}

            {shown.errors.length > 0 ? (
              <div className="rounded-md border border-destructive/40 bg-destructive/5 p-3">
                <p className="mb-1 text-sm font-medium text-destructive">Lignes rejetées</p>
                <ul className="list-inside list-disc text-sm text-destructive">
                  {shown.errors.map((rowError) => (
                    <li key={`${rowError.row}-${rowError.message}`}>{rowError.message}</li>
                  ))}
                </ul>
              </div>
            ) : null}

            {!report && shown.rows.length > 0 ? (
              <Button onClick={handleImport} disabled={isLoading} className="self-start">
                <FileUp className="mr-2 h-4 w-4" />
                Importer {shown.rows.length} matière(s)
              </Button>
            ) : null}

            {report ? (
              <Button asChild variant="outline" className="self-start">
                <Link href="/academics/subjects">Voir les matières</Link>
              </Button>
            ) : null}
          </CardContent>
        </Card>
      ) : null}
    </div>
  );
}
