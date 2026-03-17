import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';

import { APP_ROUTES } from '../constants/app.constants';
import { AuthService } from '../services/auth.service';

export const guestGuard: CanActivateFn = () => {
  const isAuthenticated = inject(AuthService).isAuthenticated();
  return !isAuthenticated || inject(Router).createUrlTree([APP_ROUTES.DASHBOARD]);
};
