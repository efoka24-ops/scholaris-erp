export interface ApiSuccessResponse<T> {
  success: true;
  data: T;
  message?: string;
}

export interface ApiErrorResponse {
  success: false;
  error: string;
  statusCode: number;
}

export type ApiResponse<T> = ApiSuccessResponse<T> | ApiErrorResponse;

export function ok<T>(data: T, message?: string): ApiSuccessResponse<T> {
  return { success: true, data, message };
}

export function fail(error: string, statusCode: number): ApiErrorResponse {
  return { success: false, error, statusCode };
}
