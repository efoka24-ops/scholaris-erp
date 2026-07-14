import { Controller, Get, Param } from "@nestjs/common";
import { ApiBearerAuth, ApiTags } from "@nestjs/swagger";
import { FinancialSummaryService } from "./financial-summary.service";

/**
 * Endpoint volontairement ouvert à tout utilisateur authentifié (pas de
 * @RequirePermissions) : un enseignant ou un parent-utilisateur doit pouvoir
 * consulter le résumé financier d'un élève sans permission "finance"
 * dédiée — cf. spec Module 7 ("GET /students/:studentId/financial-summary
 * (authentifié)").
 */
@ApiTags("students-finance")
@ApiBearerAuth()
@Controller("students")
export class StudentsFinanceController {
  constructor(private readonly financialSummaryService: FinancialSummaryService) {}

  @Get(":studentId/financial-summary")
  getSummary(@Param("studentId") studentId: string) {
    return this.financialSummaryService.getSummary(studentId);
  }
}
