import { Module } from "@nestjs/common";
import { StudentsController } from "./students.controller";
import { StudentsService } from "./students.service";
import { StudentsImportService } from "./students-import.service";
import { MatriculeService } from "./matricule.service";
import { EnrollmentsModule } from "../enrollments/enrollments.module";

@Module({
  imports: [EnrollmentsModule],
  controllers: [StudentsController],
  providers: [StudentsService, StudentsImportService, MatriculeService],
  exports: [StudentsService],
})
export class StudentsModule {}
