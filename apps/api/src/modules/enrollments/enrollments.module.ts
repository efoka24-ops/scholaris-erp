import { Module } from "@nestjs/common";
import { AcademicYearsController } from "./academic-years.controller";
import { EnrollmentsController } from "./enrollments.controller";
import { EnrollmentsService } from "./enrollments.service";

@Module({
  controllers: [EnrollmentsController, AcademicYearsController],
  providers: [EnrollmentsService],
  exports: [EnrollmentsService],
})
export class EnrollmentsModule {}
