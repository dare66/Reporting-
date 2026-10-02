import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { Auth } from './auth.service';

export const authGuard: CanActivateFn = async () => {
  // inject() only works synchronously: take everything needed before the first await.
  const auth = inject(Auth);
  const router = inject(Router);
  if (auth.isAuthenticated() || (await auth.restore())) return true;
  return router.parseUrl('/');
};

export const permissionGuard =
  (...permissions: string[]): CanActivateFn =>
  () => {
    const auth = inject(Auth);
    return auth.canAny(...permissions) || inject(Router).parseUrl('/home');
  };
