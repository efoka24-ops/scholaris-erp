import { Module } from "@nestjs/common";
import { RolesController, PermissionsController } from "./roles.controller";
import { RolesService } from "./roles.service";

@Module({
  controllers: [RolesController, PermissionsController],
  providers: [RolesService],
  exports: [RolesService],
})
export class RolesModule {}
