import { Controller, Get, Header } from "@nestjs/common";
import { ApiExcludeController } from "@nestjs/swagger";
import { Public } from "../../common/decorators/public.decorator";
import { MetricsService } from "./metrics.service";

/**
 * Endpoint Prometheus (non préfixé par le globalPrefix "api" habituel n'est pas possible
 * simplement avec setGlobalPrefix ; l'endpoint est donc exposé en /api/metrics, à scraper
 * en conséquence dans monitoring/prometheus.yml).
 */
@ApiExcludeController()
@Controller("metrics")
export class MetricsController {
  constructor(private readonly metrics: MetricsService) {}

  @Public()
  @Get()
  @Header("Content-Type", "text/plain; version=0.0.4; charset=utf-8")
  getMetrics(): string {
    return this.metrics.renderPrometheus();
  }
}
