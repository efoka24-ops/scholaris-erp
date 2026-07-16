import { Injectable } from "@nestjs/common";

/**
 * Collecteur de métriques Prometheus minimaliste, sans dépendance externe
 * (évite d'ajouter une nouvelle dépendance npm juste pour /metrics).
 * Expose des compteurs de requêtes HTTP et un histogramme de latence simplifié.
 */
@Injectable()
export class MetricsService {
  private readonly requestCounters = new Map<string, number>();
  private readonly durationBuckets = [0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10];
  private readonly durationHistogram = new Map<string, number[]>(); // key -> counts per bucket (+ +Inf)
  private readonly durationSum = new Map<string, number>();
  private readonly durationCount = new Map<string, number>();
  private readonly startedAt = Date.now();

  recordRequest(method: string, route: string, statusCode: number, durationSeconds: number): void {
    const counterKey = `${method}|${route}|${statusCode}`;
    this.requestCounters.set(counterKey, (this.requestCounters.get(counterKey) ?? 0) + 1);

    const histKey = `${method}|${route}`;
    if (!this.durationHistogram.has(histKey)) {
      this.durationHistogram.set(histKey, new Array(this.durationBuckets.length + 1).fill(0));
    }
    const buckets = this.durationHistogram.get(histKey)!;
    let placed = false;
    for (let i = 0; i < this.durationBuckets.length; i++) {
      if (durationSeconds <= this.durationBuckets[i]) {
        buckets[i]++;
        placed = true;
        break;
      }
    }
    if (!placed) buckets[this.durationBuckets.length]++; // +Inf bucket

    this.durationSum.set(histKey, (this.durationSum.get(histKey) ?? 0) + durationSeconds);
    this.durationCount.set(histKey, (this.durationCount.get(histKey) ?? 0) + 1);
  }

  /** Rendu au format texte exposé par Prometheus (content-type text/plain; version=0.0.4). */
  renderPrometheus(): string {
    const lines: string[] = [];

    lines.push("# HELP scholaris_process_uptime_seconds Durée depuis le démarrage du process API.");
    lines.push("# TYPE scholaris_process_uptime_seconds gauge");
    lines.push(`scholaris_process_uptime_seconds ${((Date.now() - this.startedAt) / 1000).toFixed(3)}`);

    lines.push("# HELP scholaris_http_requests_total Nombre total de requêtes HTTP traitées.");
    lines.push("# TYPE scholaris_http_requests_total counter");
    for (const [key, value] of this.requestCounters.entries()) {
      const [method, route, statusCode] = key.split("|");
      lines.push(
        `scholaris_http_requests_total{method="${method}",route="${route}",status_code="${statusCode}"} ${value}`,
      );
    }

    lines.push("# HELP scholaris_http_request_duration_seconds Latence des requêtes HTTP (histogramme).");
    lines.push("# TYPE scholaris_http_request_duration_seconds histogram");
    for (const [key, buckets] of this.durationHistogram.entries()) {
      const [method, route] = key.split("|");
      let cumulative = 0;
      for (let i = 0; i < this.durationBuckets.length; i++) {
        cumulative += buckets[i];
        lines.push(
          `scholaris_http_request_duration_seconds_bucket{method="${method}",route="${route}",le="${this.durationBuckets[i]}"} ${cumulative}`,
        );
      }
      cumulative += buckets[this.durationBuckets.length];
      lines.push(
        `scholaris_http_request_duration_seconds_bucket{method="${method}",route="${route}",le="+Inf"} ${cumulative}`,
      );
      lines.push(
        `scholaris_http_request_duration_seconds_sum{method="${method}",route="${route}"} ${(this.durationSum.get(key) ?? 0).toFixed(6)}`,
      );
      lines.push(
        `scholaris_http_request_duration_seconds_count{method="${method}",route="${route}"} ${this.durationCount.get(key) ?? 0}`,
      );
    }

    return lines.join("\n") + "\n";
  }
}
