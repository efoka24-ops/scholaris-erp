import { INestApplication, ValidationPipe } from "@nestjs/common";
import { Test, TestingModule } from "@nestjs/testing";
import * as request from "supertest";
import { AppModule } from "../src/app.module";

/**
 * Nécessite docker-compose up -d (PostgreSQL + Redis) et un seed exécuté
 * (npm run db:seed) — voir README. Sans cela, ces tests échouent à la connexion.
 */
describe("SCHOLARIS API (e2e)", () => {
  let app: INestApplication;

  beforeAll(async () => {
    const moduleRef: TestingModule = await Test.createTestingModule({
      imports: [AppModule],
    }).compile();

    app = moduleRef.createNestApplication();
    app.setGlobalPrefix("api");
    app.useGlobalPipes(new ValidationPipe({ whitelist: true, transform: true }));
    await app.init();
  });

  afterAll(async () => {
    await app.close();
  });

  it("GET /api/health → 200 { status: ok, database: connected, redis: connected }", async () => {
    const response = await request(app.getHttpServer()).get("/api/health").expect(200);
    expect(response.body).toEqual({ status: "ok", database: "connected", redis: "connected" });
  });

  it("POST /api/auth/login → 200 avec un access + refresh token pour le super admin seedé", async () => {
    const response = await request(app.getHttpServer())
      .post("/api/auth/login")
      .send({
        email: process.env.SEED_SUPER_ADMIN_EMAIL ?? "admin@scholaris.dev",
        password: process.env.SEED_SUPER_ADMIN_PASSWORD ?? "ChangeMe123!",
      })
      .expect(200);

    expect(response.body.accessToken).toEqual(expect.any(String));
    expect(response.body.refreshToken).toEqual(expect.any(String));
  });

  it("POST /api/auth/login → 401 pour des identifiants invalides", async () => {
    await request(app.getHttpServer())
      .post("/api/auth/login")
      .send({ email: "admin@scholaris.dev", password: "wrong-password" })
      .expect(401);
  });

  it("GET /api/auth/me → 401 sans JWT", async () => {
    await request(app.getHttpServer()).get("/api/auth/me").expect(401);
  });
});
