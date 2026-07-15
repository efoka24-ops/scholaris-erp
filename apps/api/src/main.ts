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

  const config = app.get(ConfigService);

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
  await app.listen(port);
  // eslint-disable-next-line no-console
  console.log(`SCHOLARIS API démarrée sur http://localhost:${port}/${globalPrefix}`);
}

bootstrap();
