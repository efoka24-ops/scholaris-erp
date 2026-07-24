import { resourceClient } from "./api-client";

/**
 * Le proxy /api/proxy ré-encode les réponses en JSON ; axios les re-parse donc
 * en chaîne côté client. On récupère la chaîne CSV/HTML puis on déclenche le
 * téléchargement (CSV) ou l'ouverture d'une fenêtre d'impression (HTML).
 */
export async function downloadCsv(path: string, filename: string): Promise<void> {
  const { data } = await resourceClient.get<string>(path);
  const blob = new Blob(["﻿" + String(data)], { type: "text/csv;charset=utf-8" });
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(url);
}

/** Télécharge un fichier binaire renvoyé en base64 (ex: template Excel via le proxy JSON). */
export async function downloadBase64(path: string): Promise<void> {
  const { data } = await resourceClient.get<{ filename: string; contentType: string; base64: string }>(path);
  const bytes = Uint8Array.from(atob(data.base64), (c) => c.charCodeAt(0));
  const blob = new Blob([bytes], { type: data.contentType });
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = data.filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(url);
}

export async function openPrintable(path: string): Promise<void> {
  const { data } = await resourceClient.get<string>(path);
  const w = window.open("", "_blank");
  if (!w) return;
  w.document.open();
  w.document.write(String(data));
  w.document.close();
  w.focus();
  // Laisse le rendu se faire avant d'ouvrir la boîte d'impression.
  setTimeout(() => w.print(), 400);
}
