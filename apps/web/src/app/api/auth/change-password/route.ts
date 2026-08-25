import { NextRequest, NextResponse } from "next/server";
import { cookies } from "next/headers";
import { backendClient } from "@/lib/backend-client";
import { ACCESS_TOKEN_COOKIE } from "@/lib/cookies";

export async function POST(request: NextRequest) {
  const accessToken = cookies().get(ACCESS_TOKEN_COOKIE)?.value;
  if (!accessToken) {
    return NextResponse.json({ success: false, error: "Non authentifié" }, { status: 401 });
  }

  const body = await request.json();
  try {
    const { data } = await backendClient.post("/auth/change-password", body, {
      headers: { Authorization: `Bearer ${accessToken}` },
    });
    return NextResponse.json({ success: true, data });
  } catch (error: any) {
    const status = error.response?.status ?? 500;
    const message = error.response?.data?.message ?? "Erreur lors du changement de mot de passe";
    return NextResponse.json({ success: false, error: message }, { status });
  }
}
