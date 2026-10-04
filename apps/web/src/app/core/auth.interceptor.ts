import { HttpErrorResponse, HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { Router } from '@angular/router';
import { from, switchMap, throwError, catchError } from 'rxjs';
import { Auth } from './auth.service';

/** Policy holds the API can report mid-session (e.g. MFA became mandatory). */
const HOLDS: Record<string, string> = { password_change_required: 'password', mfa_enrolment_required: 'mfa' };

/**
 * Adds the bearer token; on 401 performs one refresh-and-retry; on a security
 * policy hold, refreshes the profile and takes the person to the page that resolves it.
 */
export const authInterceptor: HttpInterceptorFn = (req, next) => {
  const auth = inject(Auth);
  const router = inject(Router);
  const isAuthCall = /\/auth\/(login|refresh|mfa)/.test(req.url);
  const withToken = (t: string | null) =>
    t && !isAuthCall ? req.clone({ setHeaders: { Authorization: `Bearer ${t}` } }) : req;

  return next(withToken(auth.accessToken())).pipe(
    catchError((err: HttpErrorResponse) => {
      const hold =
        err.status === 403 ? HOLDS[(err.error as { error?: { code?: string } })?.error?.code ?? ''] : undefined;
      if (hold) {
        auth.reloadProfile().finally(() => router.navigateByUrl(`/settings?required=${hold}`));
        return throwError(() => err);
      }
      if (err.status !== 401 || isAuthCall) return throwError(() => err);
      return from(auth.refresh()).pipe(
        switchMap((ok) => (ok ? next(withToken(auth.accessToken())) : throwError(() => err))),
      );
    }),
  );
};
