import { NextRequest, NextResponse } from "next/server";
import { cookies } from "next/headers";
import { backendClient } from "@/lib/backend-client";
import { ACCESS_TOKEN_COOKIE, ACCESS_TOKEN_COOKIE_OPTIONS, REFRESH_TOKEN_COOKIE, REFRESH_TOKEN_COOKIE_OPTIONS } from "@/lib/cookies";

export async function POST(request: NextRequest) {
  const body = await request.json();

  try {
    const { data } = await backendClient.post("/auth/login", body);

    // Chantier 1 : mot de passe temporaire non changé — token restreint (change-password
    // uniquement), aucun refresh token émis, aucun accès complet tant que le changement
    // n'a pas eu lieu.
    if (data.requiresPasswordChange) {
      cookies().set(ACCESS_TOKEN_COOKIE, data.accessToken, { ...ACCESS_TOKEN_COOKIE_OPTIONS, maxAge: 15 * 60 });
      return NextResponse.json({ success: true, requiresPasswordChange: true });
    }

    cookies().set(ACCESS_TOKEN_COOKIE, data.accessToken, ACCESS_TOKEN_COOKIE_OPTIONS);
    cookies().set(REFRESH_TOKEN_COOKIE, data.refreshToken, REFRESH_TOKEN_COOKIE_OPTIONS);

    // Chantier 3 : MFA non activé sur un rôle à privilèges — ne bloque pas la connexion,
    // signale au front de rediriger vers l'enrôlement MFA.
    return NextResponse.json({ success: true, mfaSetupRequired: data.mfaSetupRequired === true });
  } catch (error: any) {
    const status = error.response?.status ?? 500;
    const message = error.response?.data?.message ?? "Erreur de connexion au serveur";
    // mfaRequired : le compte exige un code TOTP — le formulaire de login affiche le champ.
    const mfaRequired = error.response?.data?.mfaRequired === true;
    return NextResponse.json({ success: false, error: message, mfaRequired }, { status });
  }
}
