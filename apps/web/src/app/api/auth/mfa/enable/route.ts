import { NextResponse } from "next/server";
import { cookies } from "next/headers";
import { backendClient } from "@/lib/backend-client";
import { ACCESS_TOKEN_COOKIE } from "@/lib/cookies";

export async function POST() {
  const accessToken = cookies().get(ACCESS_TOKEN_COOKIE)?.value;
  if (!accessToken) {
    return NextResponse.json({ success: false, error: "Non authentifié" }, { status: 401 });
  }
  try {
    const { data } = await backendClient.post(
      "/auth/mfa/enable",
      {},
      { headers: { Authorization: `Bearer ${accessToken}` } },
    );
    return NextResponse.json({ success: true, data });
  } catch (error: any) {
    const status = error.response?.status ?? 500;
    return NextResponse.json({ success: false, error: error.response?.data?.message ?? "Erreur" }, { status });
  }
}
