import { Injectable } from "@nestjs/common";
import * as QRCode from "qrcode";
import { PrismaService } from "../../prisma/prisma.service";
import { StudentsService } from "./students.service";
import { FindStudentsQueryDto } from "./dto/find-students-query.dto";

/**
 * Génère les cartes scolaires (photo + QR code, 8 par page A4) et les étiquettes
 * de dossier imprimables. Le QR encode l'URL de vérification du matricule.
 */
@Injectable()
export class StudentCardsService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly studentsService: StudentsService,
  ) {}

  private esc(v: any): string {
    return String(v ?? "").replace(/[&<>]/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;" }[c] as string));
  }

  async cardsHtml(query: FindStudentsQueryDto, tenantId: string): Promise<string> {
    const { data } = await this.studentsService.findAll({ ...query, page: 1, limit: 5000 } as FindStudentsQueryDto);
    const tenant = await this.prisma.tenant.findFirst({
      where: { id: tenantId },
      select: { name: true, logoUrl: true },
    });
    const students = data as any[];

    // Pré-génère les QR (data URL) en parallèle.
    const qrs = await Promise.all(
      students.map((s) =>
        QRCode.toDataURL(`https://scholaris.cm/verify-student/${encodeURIComponent(s.matricule)}`, {
          margin: 0,
          width: 120,
        }).catch(() => ""),
      ),
    );

    const cards = students
      .map((s, i) => {
        const classroom = s.enrollments?.[0]?.classroom?.name ?? "—";
        const photo = s.photoUrl
          ? `<img src="${this.esc(s.photoUrl)}" class="photo" alt="">`
          : `<div class="photo ph">PHOTO</div>`;
        return `
      <div class="card">
        <div class="chead">${this.esc(tenant?.name ?? "Établissement")}</div>
        <div class="cbody">
          ${photo}
          <div class="cinfo">
            <div class="nm">${this.esc(s.lastName)} ${this.esc(s.firstName)}</div>
            <div class="ln"><b>Mat.</b> ${this.esc(s.matricule)}</div>
            <div class="ln"><b>Classe</b> ${this.esc(classroom)}</div>
            <div class="ln"><b>Né(e)</b> ${s.dateOfBirth ? new Date(s.dateOfBirth).toLocaleDateString("fr-FR") : "—"}</div>
          </div>
          ${qrs[i] ? `<img src="${qrs[i]}" class="qr" alt="QR">` : ""}
        </div>
        <div class="cfoot">Carte scolaire — Année en cours</div>
      </div>`;
      })
      .join("");

    return `<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><title>Cartes scolaires</title>
    <style>
    *{margin:0;padding:0;box-sizing:border-box}
    body{font-family:Arial,sans-serif;padding:8mm}
    .grid{display:grid;grid-template-columns:repeat(2,1fr);gap:4mm}
    .card{border:1px solid #333;border-radius:2mm;height:32mm;padding:2mm;display:flex;flex-direction:column;justify-content:space-between;page-break-inside:avoid}
    .chead{font-size:7pt;font-weight:bold;text-align:center;border-bottom:1px solid #ccc;padding-bottom:0.5mm;text-transform:uppercase}
    .cbody{display:flex;gap:2mm;align-items:center;flex:1;padding:1mm 0}
    .photo{width:16mm;height:20mm;object-fit:cover;border:1px solid #999}
    .photo.ph{display:flex;align-items:center;justify-content:center;font-size:6pt;color:#999}
    .cinfo{flex:1;font-size:7.5pt;line-height:1.4}
    .cinfo .nm{font-weight:bold;font-size:8.5pt}
    .qr{width:18mm;height:18mm}
    .cfoot{font-size:6pt;color:#777;text-align:center}
    @page{size:A4}
    @media print{body{padding:0}}
    </style></head><body>
    <div class="grid">${cards || "<p>Aucun élève</p>"}</div>
    </body></html>`;
  }

  async labelsHtml(query: FindStudentsQueryDto, tenantId: string): Promise<string> {
    const { data } = await this.studentsService.findAll({ ...query, page: 1, limit: 5000 } as FindStudentsQueryDto);
    const tenant = await this.prisma.tenant.findFirst({ where: { id: tenantId }, select: { name: true } });
    const labels = (data as any[])
      .map((s) => {
        const classroom = s.enrollments?.[0]?.classroom?.name ?? "—";
        return `<div class="label">
        <div class="et">${this.esc(tenant?.name ?? "")}</div>
        <div class="nm">${this.esc(s.lastName)} ${this.esc(s.firstName)}</div>
        <div class="mt">${this.esc(s.matricule)} · ${this.esc(classroom)}</div>
      </div>`;
      })
      .join("");
    // Format proche Avery L7163 : 2 colonnes.
    return `<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><title>Étiquettes dossiers</title>
    <style>*{margin:0;padding:0;box-sizing:border-box}body{font-family:Arial,sans-serif;padding:6mm}
    .grid{display:grid;grid-template-columns:repeat(2,1fr);gap:2mm}
    .label{border:1px dashed #999;height:24mm;padding:2mm;display:flex;flex-direction:column;justify-content:center;page-break-inside:avoid}
    .et{font-size:7pt;color:#555}.nm{font-weight:bold;font-size:10pt}.mt{font-size:8pt;color:#333}
    @page{size:A4}@media print{body{padding:0}}</style></head><body>
    <div class="grid">${labels || "<p>Aucun élève</p>"}</div></body></html>`;
  }
}
