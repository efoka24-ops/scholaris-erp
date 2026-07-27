import { Body, Controller, Get, Param, Post } from "@nestjs/common";
import { ApiBearerAuth, ApiOperation, ApiTags } from "@nestjs/swagger";
import { RequirePermissions } from "../../common/decorators/require-permissions.decorator";
import { CurrentUser } from "../../common/decorators/current-user.decorator";
import type { AuthenticatedUser } from "../auth/jwt-payload.interface";
import { HealthService } from "./health.service";
import { CreateHealthRecordDto } from "./dto/create-health-record.dto";

/**
 * Dossiers médicaux des élèves (santé scolaire). Route dédiée `/health-records`
 * pour ne pas entrer en collision avec le health-check d'infrastructure `/health`.
 */
@ApiTags("health-records")
@ApiBearerAuth()
@Controller("health-records")
export class HealthRecordsController {
  constructor(private readonly health: HealthService) {}

  @Get()
  @RequirePermissions("health:read")
  @ApiOperation({ summary: "Liste des dossiers médicaux de l'établissement" })
  findAll(@CurrentUser() user: AuthenticatedUser) {
    return this.health.findAll(user.tenantId);
  }

  @Get(":studentId")
  @RequirePermissions("health:read")
  @ApiOperation({ summary: "Dossiers médicaux d'un élève" })
  findByStudent(@Param("studentId") studentId: string, @CurrentUser() user: AuthenticatedUser) {
    return this.health.findStudentRecords(user.tenantId, studentId);
  }

  @Post()
  @RequirePermissions("health:create")
  @ApiOperation({ summary: "Créer / mettre à jour le dossier médical d'un élève" })
  upsert(@Body() dto: CreateHealthRecordDto, @CurrentUser() user: AuthenticatedUser) {
    return this.health.upsertRecord(user.tenantId, dto);
  }
}
