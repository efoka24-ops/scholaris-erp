export const ACCESS_TOKEN_COOKIE = "scholaris_access_token";
export const REFRESH_TOKEN_COOKIE = "scholaris_refresh_token";

export const ACCESS_TOKEN_COOKIE_OPTIONS = {
  httpOnly: true,
  sameSite: "lax" as const,
  secure: process.env.NODE_ENV === "production",
  path: "/",
  maxAge: 15 * 60, // 15 minutes, aligné sur JWT_ACCESS_EXPIRES_IN
};

export const REFRESH_TOKEN_COOKIE_OPTIONS = {
  httpOnly: true,
  sameSite: "lax" as const,
  secure: process.env.NODE_ENV === "production",
  path: "/",
  maxAge: 7 * 24 * 60 * 60, // 7 jours, aligné sur JWT_REFRESH_EXPIRES_IN
};
