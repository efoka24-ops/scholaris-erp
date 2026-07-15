import { NextRequest, NextResponse } from "next/server";
import { ACCESS_TOKEN_COOKIE } from "@/lib/cookies";

// Garde de premier niveau (présence du cookie). Le contrôle fin des permissions
// reste appliqué côté API par JwtAuthGuard/PermissionsGuard — cette redirection
// n'est qu'un confort de navigation.
export function middleware(request: NextRequest) {
  const hasSession = request.cookies.has(ACCESS_TOKEN_COOKIE);

  if (!hasSession) {
    const loginUrl = new URL("/login", request.url);
    loginUrl.searchParams.set("from", request.nextUrl.pathname);
    return NextResponse.redirect(loginUrl);
  }

  return NextResponse.next();
}

export const config = {
  matcher: [
    "/dashboard/:path*",
    "/dashboard",
    "/settings/:path*",
    "/settings",
    "/academics/:path*",
    "/admissions/:path*",
    "/students/:path*",
    "/enrollments/:path*",
    "/grades/:path*",
    "/finance/:path*",
    "/communications/:path*",
  ],
};
