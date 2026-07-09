import { INestApplication, ValidationPipe } from "@nestjs/common";
import { Test, TestingModule } from "@nestjs/testing";
import * as request from "supertest";
import { AppModule } from "../src/app.module";

/**
 * Nécessite docker-compose up -d (PostgreSQL + Redis) et un seed exécuté
 * (npm run db:seed) — voir README. Sans cela, ces tests échouent à la connexion.
 */
describe("Module 2 — Structure pédagogique (e2e)", () => {
  let app: INestApplication;
  let accessToken: string;

  beforeAll(async () => {
    const moduleRef: TestingModule = await Test.createTestingModule({ imports: [AppModule] }).compile();
    app = moduleRef.createNestApplication();
    app.setGlobalPrefix("api");
    app.useGlobalPipes(new ValidationPipe({ whitelist: true, transform: true }));
    await app.init();

    const login = await request(app.getHttpServer())
      .post("/api/auth/login")
      .send({
        email: process.env.SEED_SUPER_ADMIN_EMAIL ?? "admin@scholaris.dev",
        password: process.env.SEED_SUPER_ADMIN_PASSWORD ?? "ChangeMe123!",
      });
    accessToken = login.body.accessToken;
  });

  afterAll(async () => {
    await app.close();
  });

  it("GET /api/cycles → 401 sans JWT", async () => {
    await request(app.getHttpServer()).get("/api/cycles").expect(401);
  });

  it("POST /api/cycles → 201 puis le cycle apparaît dans /api/structure/tree", async () => {
    const code = `CYC-${Date.now()}`;
    const createResponse = await request(app.getHttpServer())
      .post("/api/cycles")
      .set("Authorization", `Bearer ${accessToken}`)
      .send({ code, name: "Cycle e2e" })
      .expect(201);

    expect(createResponse.body.code).toBe(code);
    expect(createResponse.body.order).toEqual(expect.any(Number));

    const tree = await request(app.getHttpServer())
      .get("/api/structure/tree")
      .set("Authorization", `Bearer ${accessToken}`)
      .expect(200);

    expect(tree.body.some((cycle: { code: string }) => cycle.code === code)).toBe(true);
  });

  it("POST /api/cycles → 409 sur un code déjà utilisé", async () => {
    const code = `CYC-DUP-${Date.now()}`;
    await request(app.getHttpServer())
      .post("/api/cycles")
      .set("Authorization", `Bearer ${accessToken}`)
      .send({ code, name: "Premier" })
      .expect(201);

    await request(app.getHttpServer())
      .post("/api/cycles")
      .set("Authorization", `Bearer ${accessToken}`)
      .send({ code, name: "Doublon" })
      .expect(409);
  });

  it("POST /api/levels → 400 si le cycle indiqué n'existe pas", async () => {
    await request(app.getHttpServer())
      .post("/api/levels")
      .set("Authorization", `Bearer ${accessToken}`)
      .send({ code: `LVL-${Date.now()}`, name: "Niveau orphelin", cycleId: "00000000-0000-0000-0000-000000000000" })
      .expect(400);
  });
});
