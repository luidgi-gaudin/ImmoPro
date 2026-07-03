import { inject } from '@angular/core';
import { HttpInterceptorFn, HttpErrorResponse } from '@angular/common/http';
import { Router } from '@angular/router';
import { AuthService } from '../services/auth.service';
import { catchError, throwError, timer } from 'rxjs';
import { retry } from 'rxjs/operators';

export const authInterceptor: HttpInterceptorFn = (req, next) => {
  const authService = inject(AuthService);
  const router = inject(Router);
  const token = authService.getAuthHeader();

  let modifiedReq = req;
  if (token) {
    modifiedReq = req.clone({
      setHeaders: {
        Authorization: token,
      },
    });
  }

  let requestPipeline = next(modifiedReq);

  // Apply a light retry on transient network issues for GET requests ONLY to preserve idempotency
  if (req.method === 'GET') {
    requestPipeline = requestPipeline.pipe(
      retry({
        count: 1,
        delay: (error: any) => {
          if (error instanceof HttpErrorResponse && (error.status === 0 || error.status >= 500)) {
            return timer(1000); // Wait 1 second before retrying
          }
          return throwError(() => error);
        }
      })
    );
  }

  return requestPipeline.pipe(
    catchError((error: HttpErrorResponse) => {
      if (error.status === 401) {
        authService.clearSession();
        router.navigate(['/login']);
      }
      return throwError(() => error);
    })
  );
};
