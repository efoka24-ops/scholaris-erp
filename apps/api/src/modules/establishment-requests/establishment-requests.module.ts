import { Module } from "@nestjs/common";
import { EstablishmentRequestsService } from "./establishment-requests.service";
import { EstablishmentRequestsController } from "./establishment-requests.controller";
import { PublicEstablishmentRequestsController } from "./public-establishment-requests.controller";

@Module({
  controllers: [EstablishmentRequestsController, PublicEstablishmentRequestsController],
  providers: [EstablishmentRequestsService],
})
export class EstablishmentRequestsModule {}
