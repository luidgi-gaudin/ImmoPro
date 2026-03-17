import { HttpInterceptorFn, HttpStatusCode } from '@angular/common/http';
import { inject } from '@angular/core';
import { Router } from '@angular/router';
import { catchError, throwError } from 'rxjs';

import { AuthService } from '../services/auth.service';

export const authErrorInterceptor: HttpInterceptorFn = (req, next) => {
  const authService = inject(AuthService);
  const router = inject(Router);

  return next(req).pipe(
    catchError((error) => {
      if (error.status === HttpStatusCode.Unauthorized) {
        const url = req.url || '';
        const isAuthProbeOrAuthRequest =
          url.includes('/auth/user') ||
          url.includes('/auth/login') ||
          url.includes('/auth/register');

        if (!isAuthProbeOrAuthRequest) {
          authService.clearSession();
          void router.navigate(['/login']);
        }
      }
      return throwError(() => error);
    }),
  );
};
