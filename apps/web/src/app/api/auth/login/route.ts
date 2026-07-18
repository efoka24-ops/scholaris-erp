import { NextRequest, NextResponse } from "next/server";
import { cookies } from "next/headers";
import { backendClient } from "@/lib/backend-client";
import { ACCESS_TOKEN_COOKIE, ACCESS_TOKEN_COOKIE_OPTIONS, REFRESH_TOKEN_COOKIE, REFRESH_TOKEN_COOKIE_OPTIONS } from "@/lib/cookies";

export async function POST(request: NextRequest) {
  const body = await request.json();

  try {
    const { data } = await backendClient.post("/auth/login", body);

    cookies().set(ACCESS_TOKEN_COOKIE, data.accessToken, ACCESS_TOKEN_COOKIE_OPTIONS);
    cookies().set(REFRESH_TOKEN_COOKIE, data.refreshToken, REFRESH_TOKEN_COOKIE_OPTIONS);

    return NextResponse.json({ success: true });
  } catch (error: any) {
    const status = error.response?.status ?? 500;
    const message = error.response?.data?.message ?? "Erreur de connexion au serveur";
    // mfaRequired : le compte exige un code TOTP — le formulaire de login affiche le champ.
    const mfaRequired = error.response?.data?.mfaRequired === true;
    // Diagnostic temporaire (retirer une fois la connexion Vercel<->Railway confirmée stable) :
    // sans .response, error.code (ENOTFOUND/ECONNREFUSED/ETIMEDOUT...) révèle la vraie cause
    // réseau sans avoir besoin des Runtime Logs Vercel.
    const debug = !error.response
      ? { code: error.code ?? null, cause: error.cause?.message ?? null, baseURL: error.config?.baseURL ?? null }
      : undefined;
    return NextResponse.json({ success: false, error: message, mfaRequired, debug }, { status });
  }
}
