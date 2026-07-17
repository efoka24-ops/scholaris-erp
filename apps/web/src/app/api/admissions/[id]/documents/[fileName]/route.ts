import { NextRequest, NextResponse } from "next/server";
import { cookies } from "next/headers";
import { backendClient } from "@/lib/backend-client";
import { ACCESS_TOKEN_COOKIE } from "@/lib/cookies";

/**
 * Route Handler authentifiée dédiée au téléchargement d'une pièce jointe de
 * candidature (bulletin déposé par un parent). Hors du proxy générique
 * `/api/proxy/**` car celui-ci JSON-encode systématiquement la réponse, ce
 * qui corromprait un fichier binaire (PDF/image).
 */
export async function GET(
  _request: NextRequest,
  { params }: { params: { id: string; fileName: string } },
) {
  const accessToken = cookies().get(ACCESS_TOKEN_COOKIE)?.value;
  if (!accessToken) {
    return NextResponse.json({ message: "Non authentifié" }, { status: 401 });
  }

  try {
    const response = await backendClient.get(
      `/admissions/${encodeURIComponent(params.id)}/documents/${encodeURIComponent(params.fileName)}`,
      {
        headers: { Authorization: `Bearer ${accessToken}` },
        responseType: "arraybuffer",
      },
    );
    return new NextResponse(response.data, {
      status: 200,
      headers: {
        "Content-Type": String(response.headers["content-type"] ?? "application/octet-stream"),
        "Content-Disposition": String(response.headers["content-disposition"] ?? "attachment"),
      },
    });
  } catch (error: any) {
    const status = error.response?.status ?? 500;
    return NextResponse.json({ message: "Document introuvable" }, { status });
  }
}
