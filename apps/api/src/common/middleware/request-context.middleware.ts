import { Injectable, NestMiddleware } from "@nestjs/common";
import { NextFunction, Request, Response } from "express";
import { RequestContextService } from "../context/request-context.service";

@Injectable()
export class RequestContextMiddleware implements NestMiddleware {
  constructor(private readonly context: RequestContextService) {}

  use(req: Request, _res: Response, next: NextFunction): void {
    this.context.run({ ip: req.ip }, () => next());
  }
}
