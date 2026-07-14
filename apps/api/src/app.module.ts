import { MiddlewareConsumer, Module, NestModule } from "@nestjs/common";
import { ConfigModule } from "@nestjs/config";
import { APP_GUARD } from "@nestjs/core";
import { ThrottlerGuard, ThrottlerModule } from "@nestjs/throttler";
import { RequestContextModule } from "./common/context/request-context.module";
import { RequestContextMiddleware } from "./common/middleware/request-context.middleware";
import { JwtAuthGuard } from "./common/guards/jwt-auth.guard";
import { PermissionsGuard } from "./common/guards/permissions.guard";
import { PrismaModule } from "./prisma/prisma.module";
import { AuthModule } from "./modules/auth/auth.module";
import { HealthModule } from "./modules/health/health.module";
import { StructureModule } from "./modules/structure/structure.module";
import { CommunicationsModule } from "./modules/communications/communications.module";
import { AuditModule } from "./modules/audit/audit.module";
import { AcademicYearsModule } from "./modules/academic-years/academic-years.module";
import { TenantsModule } from "./modules/tenants/tenants.module";
import { SubjectsModule } from "./modules/subjects/subjects.module";
import { StudentsModule } from "./modules/students/students.module";
import { EnrollmentsModule } from "./modules/enrollments/enrollments.module";
import { AdmissionsModule } from "./modules/admissions/admissions.module";
import { FinanceModule } from "./modules/finance/finance.module";

@Module({
  imports: [
    ConfigModule.forRoot({ isGlobal: true }),
    ThrottlerModule.forRoot([{ ttl: 60_000, limit: 100 }]),
    RequestContextModule,
    PrismaModule,
    AuditModule,
    AuthModule,
    HealthModule,
    StructureModule,
    CommunicationsModule,
    AcademicYearsModule,
    TenantsModule,
    SubjectsModule,
    StudentsModule,
    EnrollmentsModule,
    AdmissionsModule,
    FinanceModule,
  ],
  providers: [
    { provide: APP_GUARD, useClass: ThrottlerGuard },
    { provide: APP_GUARD, useClass: JwtAuthGuard },
    { provide: APP_GUARD, useClass: PermissionsGuard },
  ],
})
export class AppModule implements NestModule {
  configure(consumer: MiddlewareConsumer): void {
    consumer.apply(RequestContextMiddleware).forRoutes("*");
  }
}
