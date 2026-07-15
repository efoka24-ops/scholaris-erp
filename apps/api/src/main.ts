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
  console.log(`   JWT_ACCESS_SECRET: ${process.env.JWT_ACCESS_SECRET ? '✅ définie' : '⚠️  VALEUR PAR DÉFAUT (DEV)'}`);
  console.log(`   JWT_REFRESH_SECRET: ${process.env.JWT_REFRESH_SECRET ? '✅ définie' : '⚠️  VALEUR PAR DÉFAUT (DEV)'}`);
  console.log(`   CORS_ORIGIN: ${process.env.CORS_ORIGIN || 'non défini'}`);
  console.log("");
  
  // Vérification critique : DATABASE_URL est obligatoire
  if (!process.env.DATABASE_URL) {
    console.error("❌ ERREUR FATALE : DATABASE_URL n'est pas définie !");
    console.error("");
    console.error("L'application ne peut pas démarrer sans connexion à la base de données.");
    console.error("");
    console.error("Solutions :");
    console.error("  1. En local : Créez un fichier .env à la racine avec DATABASE_URL");
    console.error("  2. Sur Railway : Ajoutez DATABASE_URL dans Settings > Variables");
    console.error("     Utilisez 'Add a Reference' pour lier au service PostgreSQL");
    console.error("");
    process.exit(1);
  }
  
  if (!process.env.JWT_ACCESS_SECRET || !process.env.JWT_REFRESH_SECRET) {
    console.warn("⚠️  ATTENTION : Secrets JWT non configurés !");
    console.warn("   L'application utilise des valeurs par défaut pour le développement.");
    console.warn("   En production, configurez JWT_ACCESS_SECRET et JWT_REFRESH_SECRET.");
    console.warn("");
  }
  
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
