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
    // On récupère la réponse en octets bruts pour pouvoir relayer aussi bien du
    // JSON que des documents (PDF/HTML/CSV/XLSX) sans les corrompre.
    const response = await backendClient.request({
      url: targetPath,
      method: request.method,
      data: body,
      responseType: "arraybuffer",
      headers: accessToken ? { Authorization: `Bearer ${accessToken}` } : {},
      // On gère nous-mêmes les statuts >= 400 (pas d'exception) pour relayer le corps tel quel.
      validateStatus: () => true,
    });

    const contentType = String(response.headers["content-type"] ?? "");
    const buffer = Buffer.from(response.data);

    // Réponses JSON : reparsées et renvoyées en JSON (comportement historique).
    if (contentType.includes("application/json") || contentType === "") {
      const text = buffer.toString("utf8");
      const json = text ? JSON.parse(text) : null;
      return NextResponse.json(json, { status: response.status });
    }

    // Autres types (HTML, CSV, PDF, XLSX…) : relayés tels quels avec leurs en-têtes.
    const headers: Record<string, string> = { "content-type": contentType };
    const disposition = response.headers["content-disposition"];
    if (disposition) headers["content-disposition"] = String(disposition);
    return new NextResponse(buffer, { status: response.status, headers });
  } catch (error: any) {
    const status = error.response?.status ?? 500;
    let data: unknown = { message: "Erreur de communication avec le serveur" };
    if (error.response?.data) {
      try {
        data = JSON.parse(Buffer.from(error.response.data).toString("utf8"));
      } catch {
        data = error.response.data;
      }
    }
    return NextResponse.json(data, { status });
  }
}

export { forward as GET, forward as POST, forward as PUT, forward as PATCH, forward as DELETE };
