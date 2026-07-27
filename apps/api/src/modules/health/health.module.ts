import { Module } from "@nestjs/common";
import { HealthController } from "./health.controller";
import { HealthRecordsController } from "./health-records.controller";
import { HealthService } from "./health.service";

@Module({
  controllers: [HealthController, HealthRecordsController],
  providers: [HealthService],
})
export class HealthModule {}
