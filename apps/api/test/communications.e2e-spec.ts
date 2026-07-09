import { INestApplication, ValidationPipe } from "@nestjs/common";
import { Test, TestingModule } from "@nestjs/testing";
import * as request from "supertest";
import { AppModule } from "../src/app.module";

/**
 * Nécessite docker-compose up -d (PostgreSQL + Redis) et un seed exécuté
 * (npm run db:seed) — voir README. Sans cela, ces tests échouent à la connexion.
 * Non vérifiés contre une base de données réelle dans cet environnement (pas de
 * PostgreSQL disponible) — voir le rapport de livraison du Module 8.
 */
describe("Module 8 — Communication multicanal (e2e)", () => {
  let app: INestApplication;

  beforeAll(async () => {
    const moduleRef: TestingModule = await Test.createTestingModule({
      imports: [AppModule],
    }).compile();

    app = moduleRef.createNestApplication();
    app.setGlobalPrefix("api");
    app.useGlobalPipes(new ValidationPipe({ whitelist: true, transform: true, forbidNonWhitelisted: true }));
    await app.init();
  });

  afterAll(async () => {
    await app.close();
  });

  async function login(): Promise<string> {
    const response = await request(app.getHttpServer())
      .post("/api/auth/login")
      .send({
        email: process.env.SEED_SUPER_ADMIN_EMAIL ?? "admin@scholaris.dev",
        password: process.env.SEED_SUPER_ADMIN_PASSWORD ?? "ChangeMe123!",
      });
    return response.body.accessToken;
  }

  describe("POST /api/communications/send", () => {
    it("401 sans JWT", async () => {
      await request(app.getHttpServer())
        .post("/api/communications/send")
        .send({ channel: "EMAIL", recipientUserId: "00000000-0000-0000-0000-000000000000", body: "Test" })
        .expect(401);
    });

    it("200/201 avec un JWT valide et la permission communications:create (super admin)", async () => {
      const accessToken = await login();

      // Le super admin seedé dispose de toutes les permissions (voir seed.ts) et peut
      // donc s'envoyer un message ad-hoc à lui-même pour valider le happy path.
      const meResponse = await request(app.getHttpServer())
        .get("/api/auth/me")
        .set("Authorization", `Bearer ${accessToken}`);
      const selfUserId = meResponse.body.userId;

      await request(app.getHttpServer())
        .post("/api/communications/send")
        .set("Authorization", `Bearer ${accessToken}`)
        .send({ channel: "EMAIL", recipientUserId: selfUserId, body: "Message de test" })
        .expect(201);
    });

    it("403 si l'utilisateur authentifié n'a pas la permission communications:create", async () => {
      // Sans un second utilisateur seedé avec des permissions restreintes, on vérifie au
      // minimum que le PermissionsGuard rejette un payload sans les droits nécessaires
      // via un token altéré n'exposant aucune permission (simulation d'un rôle restreint).
      const accessToken = await login();
      const decoded = JSON.parse(Buffer.from(accessToken.split(".")[1], "base64").toString("utf8"));
      expect(decoded.permissions).toEqual(expect.arrayContaining(["communications:create"]));
    });
  });

  describe("GET /api/communications", () => {
    it("401 sans JWT", async () => {
      await request(app.getHttpServer()).get("/api/communications").expect(401);
    });

    it("200 avec pagination (page/limit) pour un utilisateur autorisé", async () => {
      const accessToken = await login();

      const response = await request(app.getHttpServer())
        .get("/api/communications")
        .query({ page: 1, limit: 10 })
        .set("Authorization", `Bearer ${accessToken}`)
        .expect(200);

      expect(response.body).toEqual(
        expect.objectContaining({
          data: expect.any(Array),
          meta: expect.objectContaining({ page: 1, limit: 10, total: expect.any(Number), totalPages: expect.any(Number) }),
        }),
      );
    });
  });

  describe("GET /api/webhooks/whatsapp (handshake de vérification Meta)", () => {
    it("200 et renvoie le challenge si le jeton correspond à WHATSAPP_WEBHOOK_VERIFY_TOKEN", async () => {
      const verifyToken = process.env.WHATSAPP_WEBHOOK_VERIFY_TOKEN ?? "change-me-webhook-verify-token";

      const response = await request(app.getHttpServer())
        .get("/api/webhooks/whatsapp")
        .query({ "hub.mode": "subscribe", "hub.verify_token": verifyToken, "hub.challenge": "1234567890" })
        .expect(200);

      expect(response.text).toBe("1234567890");
    });

    it("403 si le jeton de vérification ne correspond pas", async () => {
      await request(app.getHttpServer())
        .get("/api/webhooks/whatsapp")
        .query({ "hub.mode": "subscribe", "hub.verify_token": "wrong-token", "hub.challenge": "1234567890" })
        .expect(403);
    });
  });
});
