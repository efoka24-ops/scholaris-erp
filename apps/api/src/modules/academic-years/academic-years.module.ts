import { Module } from "@nestjs/common";
import { AcademicYearsController } from "./academic-years.controller";
import { AcademicYearsService } from "./academic-years.service";
import { PeriodsController } from "./periods.controller";
import { PeriodsService } from "./periods.service";

@Module({
  controllers: [AcademicYearsController, PeriodsController],
  providers: [AcademicYearsService, PeriodsService],
  exports: [AcademicYearsService, PeriodsService],
})
export class AcademicYearsModule {}
