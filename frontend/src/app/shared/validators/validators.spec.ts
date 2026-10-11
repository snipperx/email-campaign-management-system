import { FormControl } from '@angular/forms';

import { noDuplicateRecipientsValidator } from './duplicate-email.validator';
import { notBlankValidator, recipientEmailsValidator } from './email.validator';

describe('recipientEmailsValidator', () => {
  it('requires at least one email', () => {
    expect(recipientEmailsValidator(new FormControl('  \n \n'))).toEqual({ required: true });
  });

  it('reports invalid addresses', () => {
    const result = recipientEmailsValidator(new FormControl('good@test.com\nnot-an-email'));
    expect(result).toEqual({ invalidEmails: ['not-an-email'] });
  });

  it('accepts valid addresses regardless of case and spacing', () => {
    expect(recipientEmailsValidator(new FormControl('  A@Test.com \r\nb@test.com'))).toBeNull();
  });
});

describe('noDuplicateRecipientsValidator', () => {
  it('flags duplicates case-insensitively', () => {
    const result = noDuplicateRecipientsValidator(new FormControl('a@test.com\nA@TEST.com\nb@test.com'));
    expect(result).toEqual({ duplicateEmails: ['a@test.com'] });
  });

  it('passes when every address is unique', () => {
    expect(noDuplicateRecipientsValidator(new FormControl('a@test.com\nb@test.com'))).toBeNull();
  });
});

describe('notBlankValidator', () => {
  it('treats whitespace as empty', () => {
    expect(notBlankValidator(new FormControl('   '))).toEqual({ required: true });
    expect(notBlankValidator(new FormControl('x'))).toBeNull();
  });
});
