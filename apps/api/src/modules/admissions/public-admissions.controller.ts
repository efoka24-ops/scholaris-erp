import { BadRequestException, Body, Controller, Param, Post, UploadedFiles, UseInterceptors } from "@nestjs/common";
import { FilesInterceptor } from "@nestjs/platform-express";
import { ApiConsumes, ApiOperation, ApiTags } from "@nestjs/swagger";
import { Throttle } from "@nestjs/throttler";
import { Public } from "../../common/decorators/public.decorator";
import { AdmissionsService } from "./admissions.service";
import { CreatePublicAdmissionDto } from "./dto/create-public-admission.dto";
import { MAX_DOCUMENTS_PER_APPLICATION, MAX_DOCUMENT_SIZE_BYTES } from "./admission-documents.storage";

/**
 * Formulaire de pré-inscription publique (page vitrine, sans authentification).
 * Rate-limité plus strictement que le throttle global de l'application
 * (100 req/min) : 5 soumissions par minute et par IP, pour limiter l'abus
 * d'un endpoint public non authentifié qui écrit en base.
 */
@ApiTags("public")
@Controller("public/admissions")
export class PublicAdmissionsController {
  constructor(private readonly admissionsService: AdmissionsService) {}

  @Public()
  @Throttle({ default: { limit: 5, ttl: 60_000 } })
  @Post()
  @ApiOperation({ summary: "Pré-inscription publique déposée par un parent (candidature DOSSIER, statut PENDING)" })
  create(@Body() dto: CreatePublicAdmissionDto) {
    return this.admissionsService.createPublic(dto);
  }

  @Public()
  @Throttle({ default: { limit: 5, ttl: 60_000 } })
  @Post(":id/documents")
  @ApiConsumes("multipart/form-data")
  @ApiOperation({ summary: "Dépôt des bulletins de l'ancien établissement pour une pré-inscription" })
  @UseInterceptors(
    FilesInterceptor("files", MAX_DOCUMENTS_PER_APPLICATION, {
      limits: { fileSize: MAX_DOCUMENT_SIZE_BYTES },
    }),
  )
  async addDocuments(@Param("id") id: string, @UploadedFiles() files: Express.Multer.File[]) {
    if (!files?.length) {
      throw new BadRequestException("Aucun fichier reçu");
    }
    const documents = await this.admissionsService.addDocuments(id, files);
    return { documents: documents.map(({ fileName, originalName, mimeType, size, uploadedAt }) => ({
      fileName,
      originalName,
      mimeType,
      size,
      uploadedAt,
    })) };
  }
}
