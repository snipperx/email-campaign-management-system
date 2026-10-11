import { ValidationErrors, ValidatorFn } from '@angular/forms';

import { parseRecipients } from '../utils/recipients';

/** Rejects the list when the same address appears more than once (case-insensitive). */
export const noDuplicateRecipientsValidator: ValidatorFn = (control): ValidationErrors | null => {
  const seen = new Set<string>();
  const duplicates = new Set<string>();

  for (const email of parseRecipients(String(control.value ?? ''))) {
    if (seen.has(email)) {
      duplicates.add(email);
    }
    seen.add(email);
  }

  return duplicates.size > 0 ? { duplicateEmails: [...duplicates] } : null;
};
