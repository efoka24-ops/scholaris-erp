import { Module } from "@nestjs/common";
import { ExamsController } from "./exams.controller";
import { ExamsService } from "./exams.service";
import { ExamPrintService } from "./exam-print.service";

@Module({
  controllers: [ExamsController],
  providers: [ExamsService, ExamPrintService],
  exports: [ExamsService],
})
export class ExamsModule {}
