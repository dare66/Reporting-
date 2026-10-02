import { HttpClient, HttpParams } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { firstValueFrom } from 'rxjs';

export const API = '/api/v1';
export const AI = '/ai-api/v1';

/** Thin promise-based client. Errors surface as ApiFailure with the API's friendly message. */
@Injectable({ providedIn: 'root' })
export class Api {
  private http = inject(HttpClient);

  get<T = any>(path: string, params?: Record<string, any>): Promise<T> {
    let p = new HttpParams();
    Object.entries(params ?? {}).forEach(([k, v]) => v !== undefined && v !== null && v !== '' && (p = p.set(k, String(v))));
    return firstValueFrom(this.http.get<T>(API + path, { params: p }));
  }
  post<T = any>(path: string, body: any = {}): Promise<T> { return firstValueFrom(this.http.post<T>(API + path, body)); }
  put<T = any>(path: string, body: any = {}): Promise<T> { return firstValueFrom(this.http.put<T>(API + path, body)); }
  patch<T = any>(path: string, body: any = {}): Promise<T> { return firstValueFrom(this.http.patch<T>(API + path, body)); }
  delete<T = any>(path: string): Promise<T> { return firstValueFrom(this.http.delete<T>(API + path)); }
  upload<T = any>(path: string, form: FormData): Promise<T> { return firstValueFrom(this.http.post<T>(API + path, form)); }
  async download(path: string, filename: string): Promise<void> {
    const blob = await firstValueFrom(this.http.get(API + path, { responseType: 'blob' }));
    const url = URL.createObjectURL(blob);
    const a = Object.assign(document.createElement('a'), { href: url, download: filename });
    a.click();
    setTimeout(() => URL.revokeObjectURL(url), 2000);
  }
}

export function errorMessage(e: any): string {
  return e?.error?.error?.message ?? e?.error?.detail ?? e?.error?.message ?? (e?.status === 0 ? 'The service is unreachable.' : 'Something went wrong.');
}
