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
    const { data } = await backendClient.post("/auth/mfa/verify", body, {
      headers: { Authorization: `Bearer ${accessToken}` },
    });
    return NextResponse.json({ success: true, data });
  } catch (error: any) {
    const status = error.response?.status ?? 500;
    return NextResponse.json({ success: false, error: error.response?.data?.message ?? "Code MFA invalide" }, { status });
  }
}
