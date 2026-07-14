import { Global, Module } from "@nestjs/common";
import { AuditLogsController } from "./audit-logs.controller";
import { AuditService } from "./audit.service";

/**
 * @Global : l'AuditService est injecté par tous les modules métier (années
 * académiques, périodes, tenants... puis notes, finance) sans réimporter
 * AuditModule partout.
 */
@Global()
@Module({
  controllers: [AuditLogsController],
  providers: [AuditService],
  exports: [AuditService],
})
export class AuditModule {}
