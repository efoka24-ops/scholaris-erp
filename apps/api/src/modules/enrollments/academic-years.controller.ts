import { Controller, Get } from "@nestjs/common";
import { ApiBearerAuth, ApiTags } from "@nestjs/swagger";
import { RequirePermissions } from "../../common/decorators/require-permissions.decorator";
import { PrismaService } from "../../prisma/prisma.service";

/**
 * Lecture seule : les inscriptions et candidatures référencent une année
 * académique, le frontend a donc besoin de les lister. La gestion complète
 * (création/clôture) reste hors périmètre du Module 4.
 */
@ApiTags("academic-years")
@ApiBearerAuth()
@Controller("academic-years")
export class AcademicYearsController {
  constructor(private readonly prisma: PrismaService) {}

  @Get()
  @RequirePermissions("academic-years:read")
  findAll() {
    return this.prisma.academicYear.findMany({ orderBy: { startDate: "desc" } });
  }
}
