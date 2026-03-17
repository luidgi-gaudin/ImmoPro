import { HttpInterceptorFn } from '@angular/common/http';

/**
 * Attaches credentials (session cookies) to every outgoing request.
 * Required for cross-origin Sanctum SPA authentication in production.
 * Harmless for same-origin requests made through the dev-server proxy.
 */
export const credentialsInterceptor: HttpInterceptorFn = (req, next) =>
  next(req.clone({ withCredentials: true }));
