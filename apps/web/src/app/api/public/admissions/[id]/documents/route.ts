import { NextRequest, NextResponse } from "next/server";

/**
 * Route Handler publique : relaie l'upload multipart des bulletins de
 * l'ancien établissement vers l'API NestJS. Utilise `fetch` natif (pas
 * `backendClient`/axios) : le FormData reçu du navigateur se transmet tel
 * quel, sans devoir le reconstruire en `multipart/form-data` manuellement.
 */
const NEST_API_URL = process.env.NEST_API_URL ?? "http://localhost:3001/api";

export async function POST(request: NextRequest, { params }: { params: { id: string } }) {
  const formData = await request.formData();

  try {
    const response = await fetch(`${NEST_API_URL}/public/admissions/${encodeURIComponent(params.id)}/documents`, {
      method: "POST",
      body: formData,
    });
    const data = await response.json();
    if (!response.ok) {
      return NextResponse.json(
        { success: false, error: data.message ?? "Impossible d'envoyer les documents." },
        { status: response.status },
      );
    }
    return NextResponse.json({ success: true, data });
  } catch {
    return NextResponse.json(
      { success: false, error: "Impossible d'envoyer les documents. Réessayez plus tard." },
      { status: 500 },
    );
  }
}
