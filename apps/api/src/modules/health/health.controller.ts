import { Controller, Get, ServiceUnavailableException } from "@nestjs/common";
import { ApiOperation, ApiTags } from "@nestjs/swagger";
import { ConfigService } from "@nestjs/config";
import Redis from "ioredis";
import { Public } from "../../common/decorators/public.decorator";
import { PrismaService } from "../../prisma/prisma.service";

type ConnectionStatus = "connected" | "disconnected";

@ApiTags("health")
@Controller("health")
export class HealthController {
  private readonly redis: Redis;

  constructor(
    private readonly prisma: PrismaService,
    private readonly config: ConfigService,
  ) {
    const redisUrl = this.config.get<string>("REDIS_URL")?.trim();
    if (redisUrl) {
      this.redis = new Redis(redisUrl, {
        lazyConnect: true,
        maxRetriesPerRequest: 1,
        enableOfflineQueue: false,
        // Ne pas boucler indéfiniment sur un Redis injoignable (ex: hôte "redis"
        // absent sur Railway) : on abandonne au lieu de spammer des reconnexions.
        retryStrategy: () => null,
        reconnectOnError: () => false,
      });
      // IMPÉRATIF : sans ce handler, un événement 'error' ioredis non géré
      // (ex: getaddrinfo ENOTFOUND redis) fait CRASHER le process Node → boucle
      // de redémarrage. On l'absorbe silencieusement ; l'état réel est reporté
      // par checkRedis().
      this.redis.on("error", () => undefined);
    } else {
      this.redis = null as unknown as Redis;
    }
  }

  @Public()
  @Get()
  @ApiOperation({ summary: "État de santé : API, PostgreSQL, Redis" })
  async check() {
    const [database, redis] = await Promise.all([this.checkDatabase(), this.checkRedis()]);

    const status = database === "connected" ? "ok" : "degraded";
    const payload = { status, database, redis };

    if (database === "disconnected") {
      throw new ServiceUnavailableException(payload);
    }
    return payload;
  }

  private async checkDatabase(): Promise<ConnectionStatus> {
    try {
      await this.prisma.$queryRaw`SELECT 1`;
      return "connected";
    } catch {
      return "disconnected";
    }
  }

  private async checkRedis(): Promise<ConnectionStatus> {
    if (!this.redis) return "disconnected";
    try {
      if (this.redis.status !== "ready") {
        await this.redis.connect();
      }
      const pong = await this.redis.ping();
      return pong === "PONG" ? "connected" : "disconnected";
    } catch {
      return "disconnected";
    }
  }
}
