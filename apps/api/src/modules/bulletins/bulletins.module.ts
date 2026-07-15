import { Module } from "@nestjs/common";
import { BulletinsController } from "./bulletins.controller";
import { BulletinsService } from "./bulletins.service";
import { BulletinPdfService } from "./bulletin-pdf.service";

@Module({
  controllers: [BulletinsController],
  providers: [BulletinsService, BulletinPdfService],
  exports: [BulletinsService],
})
export class BulletinsModule {}
