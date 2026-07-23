import { NextRequest, NextResponse } from "next/server";
import { backendClient } from "@/lib/backend-client";

/** Route Handler publique : relaie une demande de création d'établissement vers l'API NestJS. */
export async function POST(request: NextRequest) {
  const body = await request.json();
  try {
    const { data } = await backendClient.post("/public/establishment-requests", body);
    return NextResponse.json({ success: true, data });
  } catch (error: any) {
    const status = error.response?.status ?? 500;
    const message = error.response?.data?.message ?? "Impossible d'envoyer la demande. Réessayez plus tard.";
    return NextResponse.json({ success: false, error: message }, { status });
  }
}
