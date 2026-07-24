import { Injectable } from "@nestjs/common";
import type { Bulletin } from "@scholaris/prisma";

@Injectable()
export class BulletinPdfService {
  /**
   * Génère un PDF pour un bulletin
   * Note: Pour l'instant, génère un HTML. 
   * TODO: Intégrer Puppeteer ou pdfkit pour génération PDF réelle
   */
  async generate(bulletin: any): Promise<Buffer> {
    const data = bulletin.data as any;
    // Nouveau template officiel MINESEC si le snapshot enrichi est présent ;
    // sinon on retombe sur l'ancien template simple (rétro-compatibilité).
    const html = data?.minesec
      ? this.generateSecondaryHtml(bulletin)
      : this.generateHtml(bulletin);

    // Pour l'instant, retourne le HTML en Buffer
    // TODO: Utiliser Puppeteer pour convertir HTML → PDF
    return Buffer.from(html, "utf-8");
  }

  /**
   * Template officiel du bulletin secondaire camerounais (MINESEC) :
   * en-tête bilingue RÉPUBLIQUE DU CAMEROUN / REPUBLIC OF CAMEROON, bloc
   * identité + photo, tableau des disciplines regroupées (Groupe 1/2/3) avec
   * Seq1/Seq2/Moy/Cf/Total/Rang/%Réus/Max/Min/Appréciation et sous-totaux,
   * profil de la classe, travail de l'élève, conduite et signatures.
   */
  private generateSecondaryHtml(bulletin: any): string {
    const data = bulletin.data as any;
    const m = data.minesec;
    const est = m.establishment;
    const s = data.student;
    const esc = (v: any) => String(v ?? "").replace(/[&<>]/g, (c: string) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;" }[c] as string));
    const n2 = (v: any) => (v == null ? "—" : Number(v).toFixed(2));
    const pct = (v: any) => (v == null ? "—" : `${v}%`);

    const groupRows = (m.groups as any[])
      .map((g) => {
        const rows = (g.subjects as any[])
          .map(
            (sub) => `
        <tr>
          <td class="disc"><strong>${esc(sub.name)}</strong><br><span class="teacher">${esc(sub.teacher)}</span></td>
          <td class="c">${n2(sub.seq1)}</td>
          <td class="c">${n2(sub.seq2)}</td>
          <td class="c b">${n2(sub.moy)}</td>
          <td class="c">${sub.coefficient}</td>
          <td class="c">${n2(sub.total)}</td>
          <td class="c">${sub.rank ?? "—"}</td>
          <td class="c">${pct(sub.successRate)}</td>
          <td class="c">${n2(sub.max)}</td>
          <td class="c">${n2(sub.min)}</td>
          <td class="appr">${esc(sub.appreciation)}</td>
        </tr>`,
          )
          .join("");
        return `${rows}
        <tr class="grp">
          <td class="disc"><strong>${esc(g.label)}</strong></td>
          <td class="c" colspan="3">Total</td>
          <td class="c b">${g.coefSum}</td>
          <td class="c b">${n2(g.totalSum)}</td>
          <td class="c" colspan="4">Moyenne du groupe : <strong>${n2(g.average)}/20</strong></td>
        </tr>`;
      })
      .join("");

    const cp = m.classProfile;

    return `<!DOCTYPE html>
<html lang="fr"><head><meta charset="UTF-8">
<title>Bulletin ${esc(s.matricule)}</title>
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family:'Arial',sans-serif; font-size:9pt; color:#111; padding:8mm; }
  .sheet { max-width:210mm; margin:0 auto; }
  .head { display:grid; grid-template-columns:1fr auto 1fr; align-items:center; text-align:center; border-bottom:2px solid #111; padding-bottom:3mm; }
  .head .sub { font-size:7.5pt; line-height:1.35; }
  .head .sub b { font-size:8.5pt; }
  .head .logo { width:22mm; height:22mm; border-radius:50%; border:1px solid #999; display:flex; align-items:center; justify-content:center; font-size:7pt; color:#999; margin:0 auto; }
  .title { text-align:center; font-size:13pt; font-weight:bold; letter-spacing:1px; margin:3mm 0; text-transform:uppercase; }
  .idbar { display:grid; grid-template-columns:1fr 26mm; gap:3mm; margin-bottom:2mm; }
  .idbar .info { font-size:8.5pt; line-height:1.6; }
  .idbar .photo { border:1px solid #333; display:flex; align-items:center; justify-content:center; font-size:7pt; color:#999; height:28mm; }
  table.grades { width:100%; border-collapse:collapse; margin-top:2mm; }
  table.grades th, table.grades td { border:1px solid #333; padding:1mm 1.5mm; font-size:8pt; }
  table.grades th { background:#e8e8e8; text-align:center; font-size:7.5pt; }
  td.c { text-align:center; } td.b { font-weight:bold; }
  td.disc { width:34%; } .teacher { font-size:7pt; color:#555; font-style:italic; }
  td.appr { font-size:7.5pt; }
  tr.grp td { background:#f0f0f0; font-size:7.5pt; }
  .bottom { display:grid; grid-template-columns:1fr 1fr 1fr; gap:3mm; margin-top:3mm; }
  .box { border:1px solid #333; padding:2mm; font-size:8pt; }
  .box h4 { font-size:8.5pt; border-bottom:1px solid #999; margin-bottom:1.5mm; padding-bottom:1mm; text-transform:uppercase; }
  .box table { width:100%; border-collapse:collapse; }
  .box td { padding:0.6mm 0; font-size:8pt; }
  .box td.k { color:#444; } .box td.v { text-align:right; font-weight:bold; }
  .mention { text-align:center; font-weight:bold; padding:2mm; border:1px solid #333; margin-top:3mm; font-size:11pt; }
  .sign { display:grid; grid-template-columns:1fr 1fr 1fr; gap:3mm; margin-top:4mm; text-align:center; font-size:8pt; }
  .sign .slot { height:18mm; border-bottom:1px solid #333; }
  .foot { margin-top:4mm; text-align:center; font-size:7pt; color:#777; }
</style></head>
<body><div class="sheet">
  <div class="head">
    <div class="sub"><b>RÉPUBLIQUE DU CAMEROUN</b><br>Paix – Travail – Patrie<br>Ministère des Enseignements Secondaires<br><b>${esc(est.name)}</b><br>${esc(est.address ?? "")}${est.phone ? `<br>Tél : ${esc(est.phone)}` : ""}<br>Année scolaire : ${esc(data.period.academicYear)}</div>
    <div class="logo">${est.logoUrl ? `<img src="${esc(est.logoUrl)}" style="width:100%;height:100%;border-radius:50%;object-fit:cover">` : "LOGO"}</div>
    <div class="sub"><b>REPUBLIC OF CAMEROON</b><br>Peace – Work – Fatherland<br>Ministry of Secondary Education<br><b>${esc(est.name)}</b><br>${esc(est.address ?? "")}${est.phone ? `<br>Tel: ${esc(est.phone)}` : ""}<br>School year: ${esc(data.period.academicYear)}</div>
  </div>

  <div class="title">Bulletin de notes — ${esc(data.period.name)}</div>

  <div class="idbar">
    <div class="info">
      <strong>Classe :</strong> ${esc(data.classroom.name)} (${esc(data.classroom.level)}) &nbsp;·&nbsp;
      <strong>Effectif :</strong> ${m.classSize}<br>
      <strong>Matricule :</strong> ${esc(s.matricule)} &nbsp;·&nbsp;
      <strong>Nom &amp; Prénom :</strong> ${esc(s.lastName)} ${esc(s.firstName)}<br>
      <strong>Né(e) le :</strong> ${s.dateOfBirth ? new Date(s.dateOfBirth).toLocaleDateString("fr-FR") : "—"} ${s.placeOfBirth ? `à ${esc(s.placeOfBirth)}` : ""} &nbsp;·&nbsp;
      <strong>${s.repeating ? "Redoublant(e)" : "Non redoublant(e)"}</strong>
    </div>
    <div class="photo">PHOTO</div>
  </div>

  <table class="grades">
    <thead><tr>
      <th>Discipline / Enseignant</th><th>Séq.1</th><th>Séq.2</th><th>Moy</th><th>Cf</th>
      <th>Total</th><th>Rang</th><th>%Réus</th><th>Max</th><th>Min</th><th>Appréciation</th>
    </tr></thead>
    <tbody>${groupRows}
      <tr class="grp"><td class="disc"><strong>ENSEMBLE</strong></td>
        <td class="c" colspan="3">Totaux</td>
        <td class="c b">${m.totalCoef}</td>
        <td class="c b">${n2(m.totalPoints)}</td>
        <td class="c" colspan="4"><strong>Moyenne générale : ${n2(m.generalAverage)}/20</strong></td>
      </tr>
    </tbody>
  </table>

  <div class="bottom">
    <div class="box"><h4>Travail de l'élève</h4>
      <table>
        <tr><td class="k">Points</td><td class="v">${n2(m.totalPoints)}</td></tr>
        <tr><td class="k">Total coef.</td><td class="v">${m.totalCoef}</td></tr>
        <tr><td class="k">Moyenne</td><td class="v">${n2(m.generalAverage)}/20</td></tr>
        <tr><td class="k">Rang</td><td class="v">${m.rank ?? "—"}/${m.classSize}</td></tr>
      </table>
    </div>
    <div class="box"><h4>Profil de la classe</h4>
      <table>
        <tr><td class="k">Moy. de la classe</td><td class="v">${n2(cp.classAverage)}</td></tr>
        <tr><td class="k">Plus forte moy.</td><td class="v">${n2(cp.max)}</td></tr>
        <tr><td class="k">Plus faible moy.</td><td class="v">${n2(cp.min)}</td></tr>
        <tr><td class="k">Taux de réussite</td><td class="v">${pct(cp.successRate)}</td></tr>
        <tr><td class="k">Nbre ≥ 10</td><td class="v">${cp.nbrMoy}/${m.classSize}</td></tr>
      </table>
    </div>
    <div class="box"><h4>Conduite du trimestre</h4>
      <table>
        <tr><td class="k">Absences non justifiées</td><td class="v">${m.conduct.absencesUnjustified}</td></tr>
        <tr><td class="k">Absences justifiées</td><td class="v">${m.conduct.absencesJustified}</td></tr>
        <tr><td class="k">Avertissement</td><td class="v">—</td></tr>
        <tr><td class="k">Blâme</td><td class="v">—</td></tr>
        <tr><td class="k">Conseil de discipline</td><td class="v">—</td></tr>
      </table>
    </div>
  </div>

  <div class="mention">Mention : ${esc(m.mention ?? "—")}</div>

  <div class="sign">
    <div><div class="slot"></div>Le Professeur Principal</div>
    <div><div class="slot"></div>Visa des Parents</div>
    <div><div class="slot"></div>Le Chef d'Établissement</div>
  </div>

  <div class="foot">
    Code de vérification : ${esc(bulletin.verificationCode)} · Authenticité : https://scholaris.cm/verify/${esc(bulletin.verificationCode)}
  </div>
</div></body></html>`;
  }

  /**
   * Génère le template HTML du bulletin
   */
  private generateHtml(bulletin: any): string {
    const data = bulletin.data as any;
    
    return `
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Bulletin Scolaire - ${data.student.matricule}</title>
  <style>
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }
    
    body {
      font-family: 'Arial', sans-serif;
      padding: 20mm;
      background: white;
    }
    
    .bulletin {
      max-width: 210mm;
      margin: 0 auto;
      border: 2px solid #333;
      padding: 10mm;
    }
    
    .header {
      text-align: center;
      border-bottom: 3px double #333;
      padding-bottom: 10mm;
      margin-bottom: 10mm;
    }
    
    .header h1 {
      font-size: 24pt;
      margin-bottom: 5mm;
      color: #1a1a1a;
    }
    
    .header h2 {
      font-size: 16pt;
      color: #555;
      margin-bottom: 2mm;
    }
    
    .info-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 5mm;
      margin-bottom: 10mm;
      padding: 5mm;
      background: #f9f9f9;
      border: 1px solid #ddd;
    }
    
    .info-item {
      display: flex;
      gap: 3mm;
    }
    
    .info-label {
      font-weight: bold;
      color: #333;
    }
    
    .info-value {
      color: #666;
    }
    
    table {
      width: 100%;
      border-collapse: collapse;
      margin-bottom: 10mm;
    }
    
    th, td {
      border: 1px solid #333;
      padding: 3mm;
      text-align: left;
    }
    
    th {
      background: #333;
      color: white;
      font-weight: bold;
      text-transform: uppercase;
      font-size: 10pt;
    }
    
    td {
      font-size: 11pt;
    }
    
    .text-center {
      text-align: center;
    }
    
    .text-right {
      text-align: right;
    }
    
    .summary {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 5mm;
      margin-bottom: 10mm;
    }
    
    .summary-box {
      border: 2px solid #333;
      padding: 5mm;
      text-align: center;
    }
    
    .summary-label {
      font-size: 12pt;
      color: #666;
      margin-bottom: 2mm;
    }
    
    .summary-value {
      font-size: 24pt;
      font-weight: bold;
      color: #1a1a1a;
    }
    
    .mentions {
      text-align: center;
      padding: 5mm;
      background: #f0f0f0;
      border: 1px solid #ddd;
      margin-bottom: 10mm;
    }
    
    .mentions-text {
      font-size: 14pt;
      font-weight: bold;
      color: ${this.getMentionColor(data.average)};
    }
    
    .footer {
      border-top: 2px solid #333;
      padding-top: 5mm;
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 10mm;
    }
    
    .signature-box {
      text-align: center;
    }
    
    .signature-label {
      font-weight: bold;
      margin-bottom: 15mm;
    }
    
    .qr-code {
      margin-top: 10mm;
      text-align: center;
      font-size: 8pt;
      color: #999;
    }
  </style>
</head>
<body>
  <div class="bulletin">
    <!-- En-tête -->
    <div class="header">
      <h1>BULLETIN SCOLAIRE</h1>
      <h2>${data.period.academicYear} - ${data.period.name}</h2>
    </div>
    
    <!-- Informations élève -->
    <div class="info-grid">
      <div class="info-item">
        <span class="info-label">Matricule:</span>
        <span class="info-value">${data.student.matricule}</span>
      </div>
      <div class="info-item">
        <span class="info-label">Classe:</span>
        <span class="info-value">${data.classroom.name} (${data.classroom.level})</span>
      </div>
      <div class="info-item">
        <span class="info-label">Nom:</span>
        <span class="info-value">${data.student.lastName} ${data.student.firstName}</span>
      </div>
      <div class="info-item">
        <span class="info-label">Né(e) le:</span>
        <span class="info-value">${new Date(data.student.dateOfBirth).toLocaleDateString("fr-FR")}</span>
      </div>
    </div>
    
    <!-- Tableau des notes -->
    <table>
      <thead>
        <tr>
          <th>Matière</th>
          <th class="text-center">Note</th>
          <th class="text-center">Coeff.</th>
          <th class="text-center">Moy./20</th>
          <th>Enseignant</th>
        </tr>
      </thead>
      <tbody>
        ${data.grades.map((grade: any) => `
        <tr>
          <td>${grade.subject}</td>
          <td class="text-center">${grade.score}/${grade.maxScore}</td>
          <td class="text-center">${grade.coefficient}</td>
          <td class="text-center">${grade.average.toFixed(2)}</td>
          <td>${grade.teacher}</td>
        </tr>
        `).join("")}
      </tbody>
    </table>
    
    <!-- Résumé -->
    <div class="summary">
      <div class="summary-box">
        <div class="summary-label">Moyenne Générale</div>
        <div class="summary-value">${data.average.toFixed(2)}/20</div>
      </div>
      <div class="summary-box">
        <div class="summary-label">Classement</div>
        <div class="summary-value">
          ${data.rank || "—"}/${data.totalStudents}
        </div>
      </div>
    </div>
    
    <!-- Mention -->
    <div class="mentions">
      <div class="mentions-text">
        ${this.getMention(data.average)}
      </div>
    </div>
    
    <!-- Signatures -->
    <div class="footer">
      <div class="signature-box">
        <div class="signature-label">Le Professeur Principal</div>
        <div style="height: 15mm;"></div>
      </div>
      <div class="signature-box">
        <div class="signature-label">Le Chef d'Établissement</div>
        <div style="height: 15mm;"></div>
      </div>
    </div>
    
    <!-- QR Code de vérification -->
    <div class="qr-code">
      Code de vérification: ${bulletin.verificationCode}<br>
      Vérifier l'authenticité sur: https://scholaris.cm/verify/${bulletin.verificationCode}
    </div>
  </div>
</body>
</html>
    `;
  }

  private getMention(average: number): string {
    if (average >= 18) return "EXCELLENT";
    if (average >= 16) return "TRÈS BIEN";
    if (average >= 14) return "BIEN";
    if (average >= 12) return "ASSEZ BIEN";
    if (average >= 10) return "PASSABLE";
    return "INSUFFISANT";
  }

  private getMentionColor(average: number): string {
    if (average >= 16) return "#2e7d32";
    if (average >= 14) return "#388e3c";
    if (average >= 12) return "#689f38";
    if (average >= 10) return "#afb42b";
    return "#d32f2f";
  }
}
