import "reflect-metadata";
import { NestFactory } from "@nestjs/core";
import { ConfigService } from "@nestjs/config";
import { ValidationPipe } from "@nestjs/common";
import { SwaggerModule, DocumentBuilder } from "@nestjs/swagger";
import { WinstonModule } from "nest-winston";
import helmet from "helmet";
import * as winston from "winston";
import "winston-daily-rotate-file";
import { AppModule } from "./app.module";

async function bootstrap() {
  console.log("🚀 SCHOLARIS API - Démarrage...");
  console.log("📋 Diagnostic des variables d'environnement :");
  console.log(`   NODE_ENV: ${process.env.NODE_ENV || 'non défini'}`);
  console.log(`   PORT: ${process.env.PORT || 'non défini'}`);
  console.log(`   DATABASE_URL: ${process.env.DATABASE_URL ? '✅ définie' : '❌ MANQUANTE'}`);
  console.log(`   JWT_ACCESS_SECRET: ${process.env.JWT_ACCESS_SECRET ? '✅ définie' : '❌ MANQUANTE'}`);
  console.log(`   JWT_REFRESH_SECRET: ${process.env.JWT_REFRESH_SECRET ? '✅ définie' : '❌ MANQUANTE'}`);
  console.log(`   CORS_ORIGIN: ${process.env.CORS_ORIGIN || 'non défini'}`);
  
  const app = await NestFactory.create(AppModule, {
    logger: WinstonModule.createLogger({
      transports: [
        new winston.transports.Console({
          format: winston.format.combine(winston.format.timestamp(), winston.format.simple()),
        }),
        new (winston.transports as any).DailyRotateFile({
          dirname: "logs",
          filename: "scholaris-api-%DATE%.log",
          datePattern: "YYYY-MM-DD",
          maxFiles: "30d",
        }),
      ],
    }),
  });
  console.log("✅ Application NestJS créée");

  const config = app.get(ConfigService);
  console.log("✅ ConfigService chargé");

  app.use(helmet());
  app.enableCors({ origin: config.get<string>("CORS_ORIGIN", "http://localhost:3000"), credentials: true });
  app.useGlobalPipes(new ValidationPipe({ whitelist: true, transform: true, forbidNonWhitelisted: true }));

  const globalPrefix = config.get<string>("API_GLOBAL_PREFIX", "api");
  app.setGlobalPrefix(globalPrefix);

  const swaggerConfig = new DocumentBuilder()
    .setTitle("SCHOLARIS ERP API")
    .setDescription("API du socle Phase 0 (Auth, Tenants, Années académiques)")
    .setVersion("0.1.0")
    .addBearerAuth()
    .build();
  const document = SwaggerModule.createDocument(app, swaggerConfig);
  SwaggerModule.setup(`${globalPrefix}/docs`, app, document);

  const port = config.get<number>("PORT") || config.get<number>("API_PORT", 3001);
  console.log(`🎯 Écoute sur le port ${port}...`);
  
  await app.listen(port);
  
  console.log(`✅ SCHOLARIS API démarrée avec succès`);
  console.log(`📍 Health: http://localhost:${port}/${globalPrefix}/health`);
  console.log(`📚 Swagger: http://localhost:${port}/${globalPrefix}/docs`);
}

bootstrap().catch((error) => {
  console.error("❌ ERREUR FATALE AU DÉMARRAGE :");
  console.error(error);
  
  if (error.message?.includes("JWT_ACCESS_SECRET")) {
    console.error("\n⚠️  Variable JWT_ACCESS_SECRET manquante !");
    console.error("   Ajoutez-la dans Railway Settings > Variables");
  }
  if (error.message?.includes("JWT_REFRESH_SECRET")) {
    console.error("\n⚠️  Variable JWT_REFRESH_SECRET manquante !");
    console.error("   Ajoutez-la dans Railway Settings > Variables");
  }
  if (error.message?.includes("DATABASE_URL") || error.message?.includes("prisma") || error.message?.includes("connect")) {
    console.error("\n⚠️  Erreur de connexion à la base de données !");
    console.error("   Vérifiez DATABASE_URL dans les variables");
  }
  
  process.exit(1);
});
