import { inject } from '@angular/core';
import { HttpErrorResponse, HttpEventType, HttpInterceptorFn } from '@angular/common/http';
import { Router } from '@angular/router';
import { catchError, tap, throwError, timer } from 'rxjs';
import { retry } from 'rxjs/operators';
import { AuthService } from '../services/auth.service';
import { NotificationService } from '../services/notification.service';
import { SessionService } from '../services/session.service';

export const authInterceptor: HttpInterceptorFn = (req, next) => {
  const authService = inject(AuthService);
  const session = inject(SessionService);
  const notifications = inject(NotificationService);
  const router = inject(Router);
  const token = authService.getAuthHeader();

  const request = token ? req.clone({ setHeaders: { Authorization: token } }) : req;

  let pipeline = next(request);

  // Réessai limité aux GET : ils sont sans effet de bord, donc rejouables. Un
  // POST rejoué créerait un doublon — une seconde quittance, un second bail.
  if (req.method === 'GET') {
    pipeline = pipeline.pipe(
      retry({
        count: 1,
        delay: (error: unknown) => {
          const status = error instanceof HttpErrorResponse ? error.status : null;

          // 0 = réseau coupé, 5xx = incident serveur : les deux peuvent passer.
          // Un 4xx est une réponse définitive, insister n'y changerait rien.
          return status === 0 || (status !== null && status >= 500)
            ? timer(1000)
            : throwError(() => error);
        },
      }),
    );
  }

  return pipeline.pipe(
    tap((event) => {
      // Toute réponse aboutie prouve que le jeton était encore valable, et
      // repousse d'autant l'échéance d'inactivité côté serveur. La vue locale
      // du compte à rebours doit suivre, sinon elle avertirait à tort.
      if (event.type === HttpEventType.Response && token) {
        session.noteActivity();
      }
    }),
    catchError((error: HttpErrorResponse) => {
      if (error.status === 401) {
        // Le serveur distingue « session expirée » de « jeton invalide ». Le
        // premier appelle un message rassurant et un retour sur la page
        // quittée ; le second est anormal et ne doit pas être maquillé.
        const expired = error.error?.reason === 'session_expired';

        authService.clearSession();

        if (expired) {
          notifications.info('Votre session a expiré. Reconnectez-vous pour continuer.');
        }

        // On ne redirige pas si l'on est déjà sur un écran public : cela
        // effacerait le message d'erreur d'une tentative de connexion ratée.
        if (!router.url.startsWith('/login')) {
          router.navigate(['/login'], {
            queryParams: expired ? { expired: 1, returnUrl: router.url } : {},
          });
        }
      }

      return throwError(() => error);
    }),
  );
};
