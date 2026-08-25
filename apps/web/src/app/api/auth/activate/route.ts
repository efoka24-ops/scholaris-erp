import { NextRequest, NextResponse } from "next/server";
import { cookies } from "next/headers";
import { backendClient } from "@/lib/backend-client";
import { ACCESS_TOKEN_COOKIE, ACCESS_TOKEN_COOKIE_OPTIONS } from "@/lib/cookies";

/** Chantier 1 : consomme le lien d'activation et pose un token restreint (change-password uniquement). */
export async function POST(request: NextRequest) {
  const body = await request.json();
  try {
    const { data } = await backendClient.post("/auth/activate", body);
    cookies().set(ACCESS_TOKEN_COOKIE, data.accessToken, { ...ACCESS_TOKEN_COOKIE_OPTIONS, maxAge: 15 * 60 });
    return NextResponse.json({ success: true });
  } catch (error: any) {
    const status = error.response?.status ?? 500;
    const message = error.response?.data?.message ?? "Lien d'activation invalide";
    return NextResponse.json({ success: false, error: message }, { status });
  }
}
