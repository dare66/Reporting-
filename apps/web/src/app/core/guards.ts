import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { Auth } from './auth.service';

export const authGuard: CanActivateFn = async () => {
  const auth = inject(Auth);
  if (auth.isAuthenticated() || (await auth.restore())) return true;
  return inject(Router).parseUrl('/');
};

export const permissionGuard = (...permissions: string[]): CanActivateFn => () => {
  const auth = inject(Auth);
  return auth.canAny(...permissions) || inject(Router).parseUrl('/home');
};
