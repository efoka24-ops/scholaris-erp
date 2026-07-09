import { NextRequest, NextResponse } from "next/server";
import { cookies } from "next/headers";
import { backendClient } from "@/lib/backend-client";
import { ACCESS_TOKEN_COOKIE } from "@/lib/cookies";

/**
 * Proxy générique httpOnly-cookie → Bearer token vers l'API NestJS.
 *
 * Sans ceci, chaque nouvelle ressource (cycles, classes, notes, paiements...)
 * exigerait sa propre Route Handler pour transporter le JWT (qui ne peut pas
 * être lu par le JS du navigateur, cf. §1.4 du guide). Avec ~200 endpoints sur
 * 18 modules, dupliquer ce transport à la main n'est pas praticable — ce seul
 * fichier couvre tout `GET|POST|PUT|PATCH|DELETE /api/proxy/**`. Les routes
 * qui posent/effacent les cookies eux-mêmes (login/refresh/logout) restent des
 * Route Handlers dédiées sous `/api/auth/*`, pas ce proxy.
 */
interface RouteContext {
  params: { path: string[] };
}

async function forward(request: NextRequest, { params }: RouteContext) {
  const accessToken = cookies().get(ACCESS_TOKEN_COOKIE)?.value;
  const targetPath = `/${params.path.join("/")}${request.nextUrl.search}`;

  const hasBody = !["GET", "HEAD", "DELETE"].includes(request.method);
  let body: unknown;
  if (hasBody) {
    const raw = await request.text();
    body = raw ? JSON.parse(raw) : undefined;
  }

  try {
    const response = await backendClient.request({
      url: targetPath,
      method: request.method,
      data: body,
      headers: accessToken ? { Authorization: `Bearer ${accessToken}` } : {},
    });
    return NextResponse.json(response.data, { status: response.status });
  } catch (error: any) {
    const status = error.response?.status ?? 500;
    const data = error.response?.data ?? { message: "Erreur de communication avec le serveur" };
    return NextResponse.json(data, { status });
  }
}

export { forward as GET, forward as POST, forward as PUT, forward as PATCH, forward as DELETE };
