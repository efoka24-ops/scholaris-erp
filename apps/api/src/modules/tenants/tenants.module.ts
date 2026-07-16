import { Module } from "@nestjs/common";
import { TenantsController } from "./tenants.controller";
import { PublicTenantsController } from "./public-tenants.controller";
import { TenantsService } from "./tenants.service";

@Module({
  controllers: [TenantsController, PublicTenantsController],
  providers: [TenantsService],
  exports: [TenantsService],
})
export class TenantsModule {}
