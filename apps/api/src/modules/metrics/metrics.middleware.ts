import { Injectable, NestMiddleware } from "@nestjs/common";
import { Request, Response, NextFunction } from "express";
import { MetricsService } from "./metrics.service";

@Injectable()
export class MetricsMiddleware implements NestMiddleware {
  constructor(private readonly metrics: MetricsService) {}

  use(req: Request, res: Response, next: NextFunction): void {
    const start = process.hrtime.bigint();

    res.on("finish", () => {
      // Ne pas laisser exploser la cardinalité : on utilise req.route si résolu par Nest,
      // sinon le path brut (les IDs dynamiques ne sont pas normalisés en Phase 0 — acceptable
      // vu le volume de trafic attendu pour un ERP scolaire mono-tenant par déploiement).
      const route = (req.route?.path as string) ?? req.path ?? "unknown";
      const durationSeconds = Number(process.hrtime.bigint() - start) / 1e9;
      this.metrics.recordRequest(req.method, route, res.statusCode, durationSeconds);
    });

    next();
  }
}
