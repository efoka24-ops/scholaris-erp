import { Module } from "@nestjs/common";
import { AdmissionsController } from "./admissions.controller";
import { PublicAdmissionsController } from "./public-admissions.controller";
import { AdmissionsService } from "./admissions.service";

@Module({
  controllers: [AdmissionsController, PublicAdmissionsController],
  providers: [AdmissionsService],
})
export class AdmissionsModule {}
