import { HttpClient, HttpErrorResponse, HttpParams } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { firstValueFrom } from 'rxjs';

export const API = '/api/v1';
export const AI = '/ai-api/v1';

export type QueryParams = Record<string, string | number | boolean | null | undefined>;

/** Error body shapes produced by the API (`{error}`) and the AI service (`{detail}`). */
interface ErrorBody {
  error?: { message?: string };
  detail?: string;
  message?: string;
}

/** Thin promise-based client. Errors surface as ApiFailure with the API's friendly message. */
@Injectable({ providedIn: 'root' })
export class Api {
  private http = inject(HttpClient);

  get<T>(path: string, params?: QueryParams): Promise<T> {
    let p = new HttpParams();
    for (const [key, value] of Object.entries(params ?? {})) {
      if (value !== undefined && value !== null && value !== '') p = p.set(key, String(value));
    }
    return firstValueFrom(this.http.get<T>(API + path, { params: p }));
  }
  post<T>(path: string, body: unknown = {}): Promise<T> {
    return firstValueFrom(this.http.post<T>(API + path, body));
  }
  put<T>(path: string, body: unknown = {}): Promise<T> {
    return firstValueFrom(this.http.put<T>(API + path, body));
  }
  patch<T>(path: string, body: unknown = {}): Promise<T> {
    return firstValueFrom(this.http.patch<T>(API + path, body));
  }
  delete<T = void>(path: string): Promise<T> {
    return firstValueFrom(this.http.delete<T>(API + path));
  }
  upload<T>(path: string, form: FormData): Promise<T> {
    return firstValueFrom(this.http.post<T>(API + path, form));
  }
  async download(path: string, filename: string): Promise<void> {
    const blob = await firstValueFrom(this.http.get(API + path, { responseType: 'blob' }));
    const url = URL.createObjectURL(blob);
    const a = Object.assign(document.createElement('a'), { href: url, download: filename });
    a.click();
    setTimeout(() => URL.revokeObjectURL(url), 2000);
  }
}

/** The friendliest message available for a failed call. */
export function errorMessage(e: unknown): string {
  if (e instanceof HttpErrorResponse) {
    const body = (e.error ?? {}) as ErrorBody;
    return (
      body.error?.message ??
      body.detail ??
      body.message ??
      (e.status === 0 ? 'The service is unreachable.' : 'Something went wrong.')
    );
  }
  return e instanceof Error ? e.message : 'Something went wrong.';
}
