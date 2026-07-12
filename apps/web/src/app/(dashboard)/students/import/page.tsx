"use client";

import { useState } from "react";
import Link from "next/link";
import { resourceClient } from "@/lib/api-client";
import type { ImportReport } from "@/types/students";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";

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

export default function ImportStudentsPage() {
  const [file, setFile] = useState<File | null>(null);
  const [report, setReport] = useState<ImportReport | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [isUploading, setIsUploading] = useState(false);

  async function upload() {
    if (!file) return;
    setIsUploading(true);
    setError(null);
    setReport(null);
    try {
      const contentBase64 = await fileToBase64(file);
      const { data } = await resourceClient.post<ImportReport>(
        "/students/import",
        { filename: file.name, contentBase64 },
        { timeout: 120_000 },
      );
      setReport(data);
    } catch (uploadError: any) {
      setError(uploadError.response?.data?.message ?? "Import impossible.");
    } finally {
      setIsUploading(false);
    }
  }

  return (
    <div className="flex max-w-2xl flex-col gap-6">
      <div>
        <p className="text-sm text-muted-foreground">
          <Link href="/students" className="hover:underline">
            Élèves
          </Link>{" "}
          / Import Excel
        </p>
        <h1 className="text-2xl font-semibold">Importer des élèves</h1>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Fichier Excel (.xlsx)</CardTitle>
          <CardDescription>
            Ligne 1 = en-têtes. Colonnes reconnues : Nom, Prénom, Date de naissance (JJ/MM/AAAA), Sexe (M/F), Lieu de
            naissance, Nationalité, Groupe sanguin, Allergies. Les doublons (nom + prénom + date de naissance) sont
            ignorés et comptés.
          </CardDescription>
        </CardHeader>
        <CardContent className="flex flex-col gap-4">
          <input
            type="file"
            accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
            className="text-sm"
            onChange={(event) => {
              setFile(event.target.files?.[0] ?? null);
              setReport(null);
              setError(null);
            }}
          />
          <Button className="w-fit" disabled={!file || isUploading} onClick={upload}>
            {isUploading ? "Import en cours…" : "Lancer l'import"}
          </Button>
          {error ? <p className="text-sm font-medium text-destructive">{error}</p> : null}
        </CardContent>
      </Card>

      {report ? (
        <Card>
          <CardHeader>
            <CardTitle>Rapport d'import</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-col gap-4">
            <div className="grid grid-cols-3 gap-3 text-center">
              <div className="rounded-md border border-border p-3">
                <p className="text-2xl font-semibold text-primary">{report.created}</p>
                <p className="text-sm text-muted-foreground">créé(s)</p>
              </div>
              <div className="rounded-md border border-border p-3">
                <p className="text-2xl font-semibold">{report.duplicates}</p>
                <p className="text-sm text-muted-foreground">doublon(s) ignoré(s)</p>
              </div>
              <div className="rounded-md border border-border p-3">
                <p className="text-2xl font-semibold text-destructive">{report.errors.length}</p>
                <p className="text-sm text-muted-foreground">erreur(s)</p>
              </div>
            </div>
            {report.errors.length > 0 ? (
              <div className="overflow-x-auto rounded-md border border-border">
                <table className="w-full text-sm">
                  <thead className="bg-secondary/60 text-left">
                    <tr>
                      <th className="px-4 py-2 font-medium">Ligne</th>
                      <th className="px-4 py-2 font-medium">Erreur</th>
                    </tr>
                  </thead>
                  <tbody>
                    {report.errors.map((rowError) => (
                      <tr key={`${rowError.row}-${rowError.message}`} className="border-t border-border">
                        <td className="px-4 py-2">{rowError.row}</td>
                        <td className="px-4 py-2">{rowError.message}</td>
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
