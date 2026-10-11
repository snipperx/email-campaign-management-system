import { HttpErrorResponse } from '@angular/common/http';

/** Error body documented in the API contract. */
export interface ApiError {
  error: string;
  details?: Record<string, string | string[]>;
}

export interface ParsedApiError {
  message: string;
  /** First message per top-level field, e.g. { subject: 'Subject is required' } */
  fieldErrors: Record<string, string>;
}

interface RawErrorBody {
  error?: string;
  message?: string;
  details?: Record<string, string | string[]>;
  errors?: Record<string, string | string[]>; // Laravel's default validation shape
}

const GENERIC_MESSAGE = 'Something went wrong. Please try again.';

export function parseApiError(err: unknown): ParsedApiError {
  if (!(err instanceof HttpErrorResponse)) {
    return { message: GENERIC_MESSAGE, fieldErrors: {} };
  }

  if (err.status === 0) {
    return { message: 'Cannot reach the server. Check that the API is running.', fieldErrors: {} };
  }

  const body = (err.error ?? {}) as RawErrorBody;
  const fieldErrors: Record<string, string> = {};

  for (const [key, value] of Object.entries(body.details ?? body.errors ?? {})) {
    const field = key.split('.')[0]; // recipient_emails.0 -> recipient_emails
    const message = Array.isArray(value) ? value[0] : value;
    if (message && !(field in fieldErrors)) {
      fieldErrors[field] = message;
    }
  }

  return { message: body.error ?? body.message ?? GENERIC_MESSAGE, fieldErrors };
}
