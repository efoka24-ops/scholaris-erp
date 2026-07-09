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
    return NextResponse.json({ success: false, error: message }, { status });
  }
}
