import { NextRequest, NextResponse } from "next/server";
import { backendClient } from "@/lib/backend-client";

/**
 * Route Handler publique (aucune session requise) : relaie le formulaire de
 * pré-inscription vers l'API NestJS. Le navigateur ne parle jamais
 * directement au backend (cohérent avec le reste du projet, voir
 * `app/api/auth/login/route.ts`).
 */
export async function POST(request: NextRequest) {
  const body = await request.json();

  try {
    const { data } = await backendClient.post("/public/admissions", body);
    return NextResponse.json({ success: true, data });
  } catch (error: any) {
    const status = error.response?.status ?? 500;
    const message = error.response?.data?.message ?? "Impossible d'envoyer la pré-inscription. Réessayez plus tard.";
    return NextResponse.json({ success: false, error: message }, { status });
  }
}
