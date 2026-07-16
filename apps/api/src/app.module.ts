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
import { MetricsModule } from "./modules/metrics/metrics.module";
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
import { GradesModule } from "./modules/grades/grades.module";
import { UsersModule } from "./modules/users/users.module";
import { RolesModule } from "./modules/roles/roles.module";
import { BulletinsModule } from "./modules/bulletins/bulletins.module";
// Modules 9-18
import { TimetablesModule } from "./modules/timetables/timetables.module";
import { AttendanceModule } from "./modules/attendance/attendance.module";
import { DisciplineModule } from "./modules/discipline/discipline.module";
import { SchoolLifeModule } from "./modules/school-life/school-life.module";
import { LibraryModule } from "./modules/library/library.module";
import { TransportModule } from "./modules/transport/transport.module";
import { CateringModule } from "./modules/catering/catering.module";
import { AssetsModule } from "./modules/assets/assets.module";
import { HrModule } from "./modules/hr/hr.module";

@Module({
  imports: [
    ConfigModule.forRoot({
      isGlobal: true,
      // Charge .env depuis la racine du monorepo (2 niveaux au-dessus de apps/api/src)
      envFilePath: [
        "../../.env",
        ".env", // Fallback vers .env local si présent dans apps/api
      ],
    }),
    ThrottlerModule.forRoot([{ ttl: 60_000, limit: 100 }]),
    RequestContextModule,
    PrismaModule,
    AuditModule,
    AuthModule,
    HealthModule,
    MetricsModule,
    StructureModule,
    CommunicationsModule,
    AcademicYearsModule,
    TenantsModule,
    SubjectsModule,
    StudentsModule,
    EnrollmentsModule,
    AdmissionsModule,
    FinanceModule,
    GradesModule,
    UsersModule,
    RolesModule,
    BulletinsModule,
    // Modules 9-18
    TimetablesModule,
    AttendanceModule,
    DisciplineModule,
    SchoolLifeModule,
    LibraryModule,
    TransportModule,
    CateringModule,
    AssetsModule,
    HrModule,
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
    // Le middleware de métriques Prometheus est appliqué via MetricsModule (voir metrics.module.ts).
  }
}
