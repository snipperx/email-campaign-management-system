import { ValidationErrors, ValidatorFn } from '@angular/forms';

import { isValidEmail, parseRecipients } from '../utils/recipients';

/** At least one recipient, and every recipient must be a valid email. */
export const recipientEmailsValidator: ValidatorFn = (control): ValidationErrors | null => {
  const emails = parseRecipients(String(control.value ?? ''));

  if (emails.length === 0) {
    return { required: true };
  }

  const invalid = emails.filter((email) => !isValidEmail(email));
  return invalid.length > 0 ? { invalidEmails: invalid } : null;
};

/** Text that is empty or only whitespace counts as missing. */
export const notBlankValidator: ValidatorFn = (control): ValidationErrors | null =>
  String(control.value ?? '').trim().length === 0 ? { required: true } : null;
