import { NextResponse } from "next/server";
import { cookies } from "next/headers";
import { ACCESS_TOKEN_COOKIE, REFRESH_TOKEN_COOKIE } from "@/lib/cookies";

export async function POST() {
  cookies().delete(ACCESS_TOKEN_COOKIE);
  cookies().delete(REFRESH_TOKEN_COOKIE);
  return NextResponse.json({ success: true });
}
