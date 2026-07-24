import {
  Body,
  Controller,
  Get,
  Param,
  Post,
  Res,
} from "@nestjs/common";
import { Response } from "express";
import { ApiBearerAuth, ApiOperation, ApiResponse, ApiTags } from "@nestjs/swagger";
import { RequirePermissions } from "../../common/decorators/require-permissions.decorator";
import { CurrentUser } from "../../common/decorators/current-user.decorator";
import type { AuthenticatedUser } from "../auth/jwt-payload.interface";
import { BulletinsService } from "./bulletins.service";
import {
  GenerateBulletinDto,
  GenerateSingleBulletinDto,
  SendBulletinsDto,
} from "./dto/bulletin.dto";

@ApiTags("bulletins")
@ApiBearerAuth()
@Controller("bulletins")
export class BulletinsController {
  constructor(private readonly bulletinsService: BulletinsService) {}

  @Post("generate/classroom")
  @RequirePermissions("bulletins:generate")
  @ApiOperation({ summary: "Generate bulletins for all students in a classroom" })
  @ApiResponse({ status: 201, description: "Bulletins generated successfully" })
  generateBatch(
    @Body() dto: GenerateBulletinDto,
    @CurrentUser() user: AuthenticatedUser,
  ) {
    return this.bulletinsService.generateBatch(
      dto.classroomId,
      dto.periodId,
      user.tenantId,
      dto.autoSend,
    );
  }

  @Post("generate/student")
  @RequirePermissions("bulletins:generate")
  @ApiOperation({ summary: "Generate a bulletin for a single student" })
  @ApiResponse({ status: 201, description: "Bulletin generated successfully" })
  generateSingle(
    @Body() dto: GenerateSingleBulletinDto,
    @CurrentUser() user: AuthenticatedUser,
  ) {
    return this.bulletinsService.generateSingle(
      dto.studentId,
      dto.periodId,
      user.tenantId,
    );
  }

  @Post("generate/transcript")
  @RequirePermissions("bulletins:generate")
  @ApiOperation({ summary: "Générer un relevé de notes LMD (transcript) pour un étudiant" })
  @ApiResponse({ status: 201, description: "Transcript generated successfully" })
  generateTranscript(
    @Body() dto: GenerateSingleBulletinDto,
    @CurrentUser() user: AuthenticatedUser,
  ) {
    return this.bulletinsService.generateTranscript(
      dto.studentId,
      dto.periodId,
      user.tenantId,
    );
  }

  @Get(":id")
  @RequirePermissions("bulletins:read")
  @ApiOperation({ summary: "Get bulletin by ID" })
  @ApiResponse({ status: 200, description: "Bulletin retrieved successfully" })
  @ApiResponse({ status: 404, description: "Bulletin not found" })
  findOne(@Param("id") id: string, @CurrentUser() user: AuthenticatedUser) {
    return this.bulletinsService.findOne(id, user.tenantId);
  }

  @Get(":id/pdf")
  @RequirePermissions("bulletins:read")
  @ApiOperation({ summary: "Download bulletin PDF" })
  @ApiResponse({ status: 200, description: "PDF downloaded successfully" })
  async downloadPdf(
    @Param("id") id: string,
    @CurrentUser() user: AuthenticatedUser,
    @Res() res: Response,
  ) {
    const bulletin = await this.bulletinsService.findOne(id, user.tenantId);
    
    // TODO: Récupérer le vrai PDF depuis le stockage
    // Pour l'instant, générer le HTML
    const html = await this.bulletinsService["pdfService"].generate(bulletin);
    
    res.setHeader("Content-Type", "text/html");
    res.setHeader(
      "Content-Disposition",
      `inline; filename="bulletin-${bulletin.student.matricule}.html"`,
    );
    res.send(html);
  }

  @Get("verify/:code")
  @ApiOperation({ summary: "Verify bulletin authenticity via QR code" })
  @ApiResponse({ status: 200, description: "Verification result" })
  verify(@Param("code") code: string) {
    return this.bulletinsService.verify(code);
  }

  @Get("student/:studentId")
  @RequirePermissions("bulletins:read")
  @ApiOperation({ summary: "Get all bulletins for a student" })
  @ApiResponse({ status: 200, description: "Bulletins retrieved successfully" })
  findByStudent(
    @Param("studentId") studentId: string,
    @CurrentUser() user: AuthenticatedUser,
  ) {
    return this.bulletinsService.findByStudent(studentId, user.tenantId);
  }

  @Post("send")
  @RequirePermissions("bulletins:send")
  @ApiOperation({ summary: "Send bulletins to parents" })
  @ApiResponse({ status: 200, description: "Bulletins sent successfully" })
  send(@Body() dto: SendBulletinsDto, @CurrentUser() user: AuthenticatedUser) {
    return this.bulletinsService.sendForClassroom(dto.classroomId, dto.periodId, user.tenantId);
  }
}
