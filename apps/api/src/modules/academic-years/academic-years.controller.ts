import { Body, Controller, Get, Param, Patch, Post } from "@nestjs/common";
import { ApiBearerAuth, ApiOperation, ApiTags } from "@nestjs/swagger";
import { RequirePermissions } from "../../common/decorators/require-permissions.decorator";
import { CurrentUser } from "../../common/decorators/current-user.decorator";
import type { AuthenticatedUser } from "../auth/jwt-payload.interface";
import { AcademicYearsService } from "./academic-years.service";
import { CreateAcademicYearDto } from "./dto/create-academic-year.dto";

@ApiTags("academic-years")
@ApiBearerAuth()
@Controller("academic-years")
export class AcademicYearsController {
  constructor(private readonly academicYearsService: AcademicYearsService) {}

  @Get()
  @RequirePermissions("academic-years:read")
  @ApiOperation({ summary: "Liste les années académiques (avec leurs périodes)" })
  findAll() {
    return this.academicYearsService.findAll();
  }

  @Get(":id")
  @RequirePermissions("academic-years:read")
  findOne(@Param("id") id: string) {
    return this.academicYearsService.findOne(id);
  }

  @Post()
  @RequirePermissions("academic-years:create")
  @ApiOperation({ summary: "Crée une année (ACTIVE) — archive automatiquement l'année active précédente" })
  create(@Body() dto: CreateAcademicYearDto, @CurrentUser() user: AuthenticatedUser) {
    return this.academicYearsService.create(dto, user.tenantId);
  }

  @Patch(":id/activate")
  @RequirePermissions("academic-years:update")
  @ApiOperation({ summary: "Active une année existante — archive automatiquement l'année active précédente" })
  activate(@Param("id") id: string, @CurrentUser() user: AuthenticatedUser) {
    return this.academicYearsService.activate(id, user.tenantId);
  }

  @Patch(":id/close")
  @RequirePermissions("academic-years:update")
  @ApiOperation({ summary: "Clôture l'année active (statut CLOSED)" })
  close(@Param("id") id: string) {
    return this.academicYearsService.close(id);
  }
}
