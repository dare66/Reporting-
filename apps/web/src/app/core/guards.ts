import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { Auth } from './auth.service';

export const authGuard: CanActivateFn = async (_route, state) => {
  // inject() only works synchronously: take everything needed before the first await.
  const auth = inject(Auth);
  const router = inject(Router);
  if (!auth.isAuthenticated() && !(await auth.restore())) return router.parseUrl('/');
  // An account held by the security policy can only reach the page that resolves it.
  const hold = auth.hold();
  if (hold && !state.url.startsWith('/settings')) return router.parseUrl(`/settings?required=${hold}`);
  return true;
};

export const permissionGuard =
  (...permissions: string[]): CanActivateFn =>
  () => {
    const auth = inject(Auth);
    return auth.canAny(...permissions) || inject(Router).parseUrl('/home');
  };
