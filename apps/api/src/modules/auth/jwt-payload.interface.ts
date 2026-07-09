export interface JwtAccessPayload {
  sub: string; // userId
  tenantId: string;
  email: string;
  permissions: string[];
}

export interface JwtRefreshPayload {
  sub: string; // userId
  tenantId: string;
}

/** Attaché à req.user par JwtAccessStrategy une fois le token access validé. */
export interface AuthenticatedUser {
  userId: string;
  tenantId: string;
  email: string;
  permissions: string[];
}
