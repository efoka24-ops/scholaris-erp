import { Module } from "@nestjs/common";
import { FeeStructuresController } from "./fee-structures.controller";
import { FeeStructuresService } from "./fee-structures.service";
import { InvoicesController } from "./invoices.controller";
import { InvoicesService } from "./invoices.service";
import { PaymentsController } from "./payments.controller";
import { PaymentsService } from "./payments.service";
import { DiscountsController } from "./discounts.controller";
import { DiscountsService } from "./discounts.service";
import { StudentsFinanceController } from "./students-finance.controller";
import { FinancialSummaryService } from "./financial-summary.service";
import { FinanceDashboardController } from "./finance-dashboard.controller";
import { FinanceDashboardService } from "./finance-dashboard.service";
import { ReceiptService } from "./receipt.service";

@Module({
  controllers: [
    FeeStructuresController,
    InvoicesController,
    PaymentsController,
    DiscountsController,
    StudentsFinanceController,
    FinanceDashboardController,
  ],
  providers: [
    FeeStructuresService,
    InvoicesService,
    PaymentsService,
    DiscountsService,
    FinancialSummaryService,
    FinanceDashboardService,
    ReceiptService,
  ],
  exports: [FeeStructuresService, InvoicesService],
})
export class FinanceModule {}
