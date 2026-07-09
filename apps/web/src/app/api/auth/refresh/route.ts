import { NextResponse } from "next/server";
import { cookies } from "next/headers";
import { backendClient } from "@/lib/backend-client";
import { ACCESS_TOKEN_COOKIE, ACCESS_TOKEN_COOKIE_OPTIONS, REFRESH_TOKEN_COOKIE } from "@/lib/cookies";

export async function POST() {
  const refreshToken = cookies().get(REFRESH_TOKEN_COOKIE)?.value;
  if (!refreshToken) {
    return NextResponse.json({ success: false, error: "Session expirée" }, { status: 401 });
  }

  try {
    const { data } = await backendClient.post("/auth/refresh", { refreshToken });
    cookies().set(ACCESS_TOKEN_COOKIE, data.accessToken, ACCESS_TOKEN_COOKIE_OPTIONS);
    return NextResponse.json({ success: true });
  } catch {
    cookies().delete(ACCESS_TOKEN_COOKIE);
    cookies().delete(REFRESH_TOKEN_COOKIE);
    return NextResponse.json({ success: false, error: "Session expirée" }, { status: 401 });
  }
}
