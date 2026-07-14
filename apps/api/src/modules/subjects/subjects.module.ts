import { Module } from "@nestjs/common";
import { SubjectsController } from "./subjects.controller";
import { SubjectsService } from "./subjects.service";
import { SubjectsImportService } from "./subjects-import.service";
import { TeachingUnitsController } from "./teaching-units.controller";
import { TeachingUnitsService } from "./teaching-units.service";
import { CourseElementsController } from "./course-elements.controller";
import { CourseElementsService } from "./course-elements.service";
import { SubjectAssignmentsController } from "./subject-assignments.controller";
import { SubjectAssignmentsService } from "./subject-assignments.service";

@Module({
  controllers: [SubjectsController, TeachingUnitsController, CourseElementsController, SubjectAssignmentsController],
  providers: [
    SubjectsService,
    SubjectsImportService,
    TeachingUnitsService,
    CourseElementsService,
    SubjectAssignmentsService,
  ],
})
export class SubjectsModule {}
