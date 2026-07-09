import { SetMetadata } from "@nestjs/common";

export const PERMISSIONS_KEY = "requiredPermissions";

/** Exige qu'au moins un des permission codes ("resource:action") soit présent sur le JWT. */
export const RequirePermissions = (...permissions: string[]) => SetMetadata(PERMISSIONS_KEY, permissions);
