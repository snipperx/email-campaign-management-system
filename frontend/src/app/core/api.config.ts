import { InjectionToken } from '@angular/core';

/** Same-origin API path; the dev server and Docker Nginx proxy it to Laravel. */
export const API_BASE_URL = new InjectionToken<string>('API_BASE_URL', {
  providedIn: 'root',
  factory: () => '/api',
});
