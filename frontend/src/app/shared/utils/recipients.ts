const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const MAX_EMAIL_LENGTH = 255;

/** One email per line: split, trim, lowercase, drop blank lines. */
export function parseRecipients(raw: string): string[] {
  return raw
    .split(/\r?\n/)
    .map((line) => line.trim().toLowerCase())
    .filter((line) => line.length > 0);
}

export function isValidEmail(value: string): boolean {
  return value.length <= MAX_EMAIL_LENGTH && EMAIL_PATTERN.test(value);
}
