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
    const html = this.generateHtml(bulletin);
    
    // Pour l'instant, retourne le HTML en Buffer
    // TODO: Utiliser Puppeteer pour convertir HTML → PDF
    return Buffer.from(html, "utf-8");
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
