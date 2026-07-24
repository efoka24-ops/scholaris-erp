import { Injectable } from "@nestjs/common";
import { PrismaService } from "../../prisma/prisma.service";
import { ExamsService } from "./exams.service";

/**
 * Génère les documents imprimables (HTML A4/A5, impression directe navigateur)
 * et les exports CSV du module Examens : liste officielle des candidats,
 * récépissés d'inscription, tableau d'affichage des résultats.
 */
@Injectable()
export class ExamPrintService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly exams: ExamsService,
  ) {}

  private esc(v: any): string {
    return String(v ?? "").replace(/[&<>]/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;" }[c] as string));
  }

  private csvCell(v: any): string {
    const s = String(v ?? "");
    return /[",;\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
  }

  private async header(tenantId: string): Promise<{ name: string; address: string | null; phone: string | null }> {
    const t = await this.prisma.tenant.findFirst({
      where: { id: tenantId },
      select: { name: true, address: true, phone: true },
    });
    return { name: t?.name ?? "Établissement", address: t?.address ?? null, phone: t?.phone ?? null };
  }

  private officialHead(est: { name: string; address: string | null }, title: string): string {
    return `
    <div class="offhead">
      <div class="col"><b>RÉPUBLIQUE DU CAMEROUN</b><br>Paix – Travail – Patrie<br>Ministère des Enseignements Secondaires</div>
      <div class="col center"><b>${this.esc(est.name)}</b>${est.address ? `<br>${this.esc(est.address)}` : ""}</div>
      <div class="col right"><b>REPUBLIC OF CAMEROON</b><br>Peace – Work – Fatherland<br>Ministry of Secondary Education</div>
    </div>
    <h1 class="doctitle">${this.esc(title)}</h1>`;
  }

  private baseCss(): string {
    return `
    * { margin:0; padding:0; box-sizing:border-box; }
    body { font-family:'Arial',sans-serif; font-size:10pt; color:#111; padding:10mm; }
    .offhead { display:grid; grid-template-columns:1fr 1fr 1fr; font-size:8pt; line-height:1.4; border-bottom:2px solid #111; padding-bottom:2mm; }
    .offhead .center { text-align:center; } .offhead .right { text-align:right; }
    .doctitle { text-align:center; font-size:13pt; margin:4mm 0; text-transform:uppercase; letter-spacing:1px; }
    table { width:100%; border-collapse:collapse; margin-top:2mm; }
    th,td { border:1px solid #333; padding:1.5mm 2mm; font-size:9pt; }
    th { background:#e8e8e8; text-align:center; }
    .c { text-align:center; }
    .meta { font-size:9pt; margin:2mm 0; }
    @media print { body { padding:0; } }`;
  }

  // --- Liste officielle des candidats (A4 paysage) ------------------------
  async candidateListHtml(examId: string, tenantId: string): Promise<string> {
    const exam = await this.exams.findOne(examId);
    const candidates = await this.exams.getCandidates(examId);
    const est = await this.header(tenantId);
    const rows = candidates
      .map(
        (c: any, i: number) => `
      <tr>
        <td class="c">${i + 1}</td>
        <td>${this.esc(c.registrationNumber)}</td>
        <td>${this.esc(c.student ? `${c.student.lastName} ${c.student.firstName}` : "—")}</td>
        <td class="c">${this.esc(c.student?.gender === "MALE" ? "M" : c.student?.gender === "FEMALE" ? "F" : "—")}</td>
        <td class="c">${c.student?.dateOfBirth ? new Date(c.student.dateOfBirth).toLocaleDateString("fr-FR") : "—"}</td>
        <td class="c">${this.esc(c.series ?? "—")}</td>
        <td>${this.esc(c.centerName ?? "—")}</td>
      </tr>`,
      )
      .join("");
    return `<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><title>Liste candidats ${this.esc(exam.name)}</title>
    <style>${this.baseCss()} @page { size:A4 landscape; }</style></head><body>
    ${this.officialHead(est, `Liste officielle des candidats — ${exam.name}`)}
    <div class="meta"><b>Examen :</b> ${this.esc(exam.name)} (${this.esc(exam.code)}) · <b>Nombre de candidats :</b> ${candidates.length}</div>
    <table><thead><tr><th>#</th><th>N° inscription</th><th>Nom &amp; Prénom</th><th>Sexe</th><th>Date naiss.</th><th>Série</th><th>Centre</th></tr></thead>
    <tbody>${rows || `<tr><td colspan="7" class="c">Aucun candidat</td></tr>`}</tbody></table>
    </body></html>`;
  }

  async candidatesCsv(examId: string): Promise<string> {
    const candidates = await this.exams.getCandidates(examId);
    const head = ["N_inscription", "Nom", "Prenom", "Sexe", "Date_naissance", "Serie", "Centre", "Statut"];
    const lines = candidates.map((c: any) =>
      [
        c.registrationNumber,
        c.student?.lastName ?? "",
        c.student?.firstName ?? "",
        c.student?.gender === "MALE" ? "M" : c.student?.gender === "FEMALE" ? "F" : "",
        c.student?.dateOfBirth ? new Date(c.student.dateOfBirth).toLocaleDateString("fr-FR") : "",
        c.series ?? "",
        c.centerName ?? "",
        c.status,
      ]
        .map((x) => this.csvCell(x))
        .join(";"),
    );
    return [head.join(";"), ...lines].join("\r\n");
  }

  // --- Récépissés d'inscription (A5, 4 par page A4) -----------------------
  async receiptsHtml(examId: string, tenantId: string): Promise<string> {
    const exam = await this.exams.findOne(examId);
    const candidates = await this.exams.getCandidates(examId);
    const est = await this.header(tenantId);
    const cards = candidates
      .map(
        (c: any) => `
      <div class="card">
        <div class="ch"><b>${this.esc(est.name)}</b><br><span>Récépissé d'inscription — ${this.esc(exam.name)}</span></div>
        <div class="cbody">
          <div class="row"><span>N° d'inscription</span><b>${this.esc(c.registrationNumber)}</b></div>
          <div class="row"><span>Candidat</span><b>${this.esc(c.student ? `${c.student.lastName} ${c.student.firstName}` : "—")}</b></div>
          <div class="row"><span>Matricule</span><b>${this.esc(c.student?.matricule ?? "—")}</b></div>
          <div class="row"><span>Série</span><b>${this.esc(c.series ?? "—")}</b></div>
          <div class="row"><span>Centre</span><b>${this.esc(c.centerName ?? "—")}</b></div>
          <div class="row"><span>Frais</span><b>${c.feePaid ? "Payés" : "Non payés"}</b></div>
        </div>
        <div class="cfoot">Fait le ${new Date().toLocaleDateString("fr-FR")} · Le Chef d'Établissement</div>
      </div>`,
      )
      .join("");
    return `<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><title>Récépissés ${this.esc(exam.name)}</title>
    <style>${this.baseCss()}
    .grid { display:grid; grid-template-columns:1fr 1fr; gap:4mm; }
    .card { border:1px dashed #333; padding:3mm; height:138mm; display:flex; flex-direction:column; }
    .ch { text-align:center; border-bottom:1px solid #999; padding-bottom:2mm; font-size:9pt; }
    .cbody { flex:1; padding:3mm 0; } .row { display:flex; justify-content:space-between; padding:1mm 0; font-size:9pt; border-bottom:1px dotted #ccc; }
    .cfoot { font-size:7.5pt; text-align:right; color:#555; }
    @page { size:A4; }</style></head><body>
    <div class="grid">${cards || "<p>Aucun candidat</p>"}</div>
    </body></html>`;
  }

  // --- Tableau d'affichage des résultats ----------------------------------
  async resultsBoardHtml(examId: string, tenantId: string): Promise<string> {
    const exam = await this.exams.findOne(examId);
    const results = await this.exams.getResults(examId);
    const est = await this.header(tenantId);
    const rows = results.candidates
      .map(
        (c: any) => `
      <tr class="${c.status === "PASSED" ? "ok" : ""}">
        <td class="c">${c.rank ?? "—"}</td>
        <td>${this.esc(c.registrationNumber)}</td>
        <td>${this.esc(c.student ? `${c.student.lastName} ${c.student.firstName}` : "—")}</td>
        <td class="c">${c.average != null ? Number(c.average).toFixed(2) : "—"}</td>
        <td class="c">${this.esc(c.mention ?? "—")}</td>
        <td class="c"><b>${c.status === "PASSED" ? "ADMIS" : c.status === "FAILED" ? "ÉCHOUÉ" : "—"}</b></td>
      </tr>`,
      )
      .join("");
    return `<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><title>Résultats ${this.esc(exam.name)}</title>
    <style>${this.baseCss()} tr.ok { background:#e8f5e9; } @page { size:A4; }</style></head><body>
    ${this.officialHead(est, `Résultats — ${exam.name}`)}
    <div class="meta"><b>Taux de réussite :</b> ${results.stats.successRate}% · <b>Admis :</b> ${results.stats.passedCount}/${results.stats.totalGraded}${results.stats.best ? ` · <b>Major :</b> ${this.esc(results.stats.best.name)} (${results.stats.best.average.toFixed(2)})` : ""}</div>
    <table><thead><tr><th>Rang</th><th>N° inscription</th><th>Nom &amp; Prénom</th><th>Moyenne</th><th>Mention</th><th>Décision</th></tr></thead>
    <tbody>${rows || `<tr><td colspan="6" class="c">Aucun résultat</td></tr>`}</tbody></table>
    </body></html>`;
  }

  async resultsCsv(examId: string): Promise<string> {
    const results = await this.exams.getResults(examId);
    const head = ["Rang", "N_inscription", "Nom", "Prenom", "Moyenne", "Mention", "Decision"];
    const lines = results.candidates.map((c: any) =>
      [
        c.rank ?? "",
        c.registrationNumber,
        c.student?.lastName ?? "",
        c.student?.firstName ?? "",
        c.average != null ? Number(c.average).toFixed(2) : "",
        c.mention ?? "",
        c.status === "PASSED" ? "ADMIS" : c.status === "FAILED" ? "ECHOUE" : "",
      ]
        .map((x) => this.csvCell(x))
        .join(";"),
    );
    return [head.join(";"), ...lines].join("\r\n");
  }
}
