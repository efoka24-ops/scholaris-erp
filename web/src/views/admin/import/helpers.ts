// Fonctions pures de l'import des acteurs : contrôle du fichier, CSV et fiche imprimable des identifiants remis une seule fois.
import { IMPORT_EXTENSIONS, IMPORT_MAX_BYTES, type ProvisionalCredential } from '@/api/imports';

export type FileProblem = 'type' | 'size' | 'empty' | null;

/** Contrôle côté navigateur (le serveur contrôle de nouveau) : .csv ou .xlsx, 2 Mo au plus, non vide. */
export function checkFile(file: { name: string; size: number }): FileProblem {
  const ext = file.name.toLowerCase().split('.').pop() ?? '';
  if (!(IMPORT_EXTENSIONS as readonly string[]).includes(ext)) return 'type';
  if (file.size === 0) return 'empty';
  if (file.size > IMPORT_MAX_BYTES) return 'size';
  return null;
}

/** Neutralise l'injection de formule dans un tableur ; un identifiant téléphonique « +237… » reste lisible. */
export function csvCell(value: string | number): string {
  let text = String(value);
  if (/^[=+\-@\t\r]/.test(text) && !/^\+\d{8,15}$/.test(text)) text = `'${text}`;
  return /[";\r\n]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
}

/** Même colonnes que le fichier du serveur (?format=csv), construit ici pour ne pas consommer la remise unique deux fois. */
export function credentialsCsv(rows: ProvisionalCredential[], headers: string[]): string {
  const lines = [headers.map(csvCell).join(';')];
  for (const r of rows) {
    lines.push([r.login, r.provisional_password, r.last_name, r.first_name, r.role, r.line].map(csvCell).join(';'));
  }
  return `\u{FEFF}${lines.join('\r\n')}\r\n`;
}

export const escapeHtml = (value: string): string =>
  value.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c] ?? c);

export interface SheetText {
  title: string;
  establishment: string;
  once: string;
  steps: string;
  columns: { login: string; password: string; person: string; role: string };
}

/** Document imprimable : texte échappé, aucun script. */
export function credentialsSheetHtml(rows: Array<{ login: string; password: string; person: string; role: string }>, text: SheetText): string {
  const body = rows
    .map((r) => `<tr><td>${escapeHtml(r.person)}</td><td>${escapeHtml(r.role)}</td><td>${escapeHtml(r.login)}</td><td class="pw">${escapeHtml(r.password)}</td></tr>`)
    .join('');
  return `<!doctype html><html><head><meta charset="utf-8"><title>${escapeHtml(text.title)}</title>
<style>body{font-family:Arial,sans-serif;margin:24px;color:#111}h1{font-size:20px}p{font-size:13px;max-width:640px;line-height:1.5}
table{border-collapse:collapse;width:100%;margin-top:16px}th,td{border:1px solid #999;padding:6px 8px;font-size:13px;text-align:left}
.pw{font-family:Consolas,monospace;font-size:15px;font-weight:700;letter-spacing:.08em}tr{page-break-inside:avoid}</style></head>
<body><h1>${escapeHtml(text.title)}</h1><p>${escapeHtml(text.establishment)}</p><p><strong>${escapeHtml(text.once)}</strong></p>
<table><thead><tr><th>${escapeHtml(text.columns.person)}</th><th>${escapeHtml(text.columns.role)}</th><th>${escapeHtml(text.columns.login)}</th><th>${escapeHtml(text.columns.password)}</th></tr></thead><tbody>${body}</tbody></table>
<p>${escapeHtml(text.steps)}</p></body></html>`;
}

/** Imprime depuis un cadre masqué : rien ne reste dans la page ni dans l'historique. */
export function printHtml(html: string): void {
  const frame = document.createElement('iframe');
  frame.setAttribute('aria-hidden', 'true');
  frame.style.cssText = 'position:fixed;width:0;height:0;border:0;visibility:hidden';
  document.body.appendChild(frame);
  const doc = frame.contentDocument;
  if (!doc || !frame.contentWindow) {
    frame.remove();
    return;
  }
  doc.open();
  doc.write(html);
  doc.close();
  frame.contentWindow.focus();
  frame.contentWindow.print();
  window.setTimeout(() => frame.remove(), 1000);
}
