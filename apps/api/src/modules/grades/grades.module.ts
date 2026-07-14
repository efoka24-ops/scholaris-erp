import { Module } from "@nestjs/common";
import { GradesController } from "./grades.controller";
import { GradesService } from "./grades.service";
import { GradesImportService } from "./grades-import.service";
import { CalculationEngineService } from "./calculation-engine.service";

@Module({
  controllers: [GradesController],
  providers: [GradesService, GradesImportService, CalculationEngineService],
  exports: [GradesService, CalculationEngineService],
})
export class GradesModule {}
