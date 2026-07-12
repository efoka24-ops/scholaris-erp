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
import { StudentsModule } from "./modules/students/students.module";
import { EnrollmentsModule } from "./modules/enrollments/enrollments.module";
import { AdmissionsModule } from "./modules/admissions/admissions.module";

@Module({
  imports: [
    ConfigModule.forRoot({ isGlobal: true }),
    ThrottlerModule.forRoot([{ ttl: 60_000, limit: 100 }]),
    RequestContextModule,
    PrismaModule,
    AuthModule,
    HealthModule,
    StructureModule,
    CommunicationsModule,
    StudentsModule,
    EnrollmentsModule,
    AdmissionsModule,
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
