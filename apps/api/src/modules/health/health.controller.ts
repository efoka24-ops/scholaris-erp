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
    this.redis = new Redis(this.config.getOrThrow<string>("REDIS_URL"), {
      lazyConnect: true,
      maxRetriesPerRequest: 1,
    });
  }

  @Public()
  @Get()
  @ApiOperation({ summary: "État de santé : API, PostgreSQL, Redis" })
  async check() {
    const [database, redis] = await Promise.all([this.checkDatabase(), this.checkRedis()]);

    const status = database === "connected" && redis === "connected" ? "ok" : "degraded";
    const payload = { status, database, redis };

    if (status === "degraded") {
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
