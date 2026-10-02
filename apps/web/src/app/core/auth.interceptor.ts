import { HttpErrorResponse, HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { from, switchMap, throwError, catchError } from 'rxjs';
import { Auth } from './auth.service';

/** Adds the bearer token; on 401 performs one refresh-and-retry. */
export const authInterceptor: HttpInterceptorFn = (req, next) => {
  const auth = inject(Auth);
  const isAuthCall = /\/auth\/(login|refresh|mfa)/.test(req.url);
  const withToken = (t: string | null) =>
    t && !isAuthCall ? req.clone({ setHeaders: { Authorization: `Bearer ${t}` } }) : req;

  return next(withToken(auth.accessToken())).pipe(
    catchError((err: HttpErrorResponse) => {
      if (err.status !== 401 || isAuthCall) return throwError(() => err);
      return from(auth.refresh()).pipe(
        switchMap((ok) => (ok ? next(withToken(auth.accessToken())) : throwError(() => err))),
      );
    }),
  );
};
