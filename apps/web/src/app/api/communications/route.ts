import { NextRequest, NextResponse } from "next/server";
import { cookies } from "next/headers";
import { backendClient } from "@/lib/backend-client";
import { ACCESS_TOKEN_COOKIE } from "@/lib/cookies";

export async function GET(request: NextRequest) {
  const accessToken = cookies().get(ACCESS_TOKEN_COOKIE)?.value;
  if (!accessToken) {
    return NextResponse.json({ success: false, error: "Non authentifié" }, { status: 401 });
  }

  const page = request.nextUrl.searchParams.get("page") ?? "1";
  const limit = request.nextUrl.searchParams.get("limit") ?? "20";

  try {
    const { data } = await backendClient.get("/communications", {
      headers: { Authorization: `Bearer ${accessToken}` },
      params: { page, limit },
    });
    return NextResponse.json({ success: true, data });
  } catch (error: any) {
    const status = error.response?.status ?? 500;
    const message = error.response?.data?.message ?? "Erreur lors de la récupération du journal";
    return NextResponse.json({ success: false, error: message }, { status });
  }
}
