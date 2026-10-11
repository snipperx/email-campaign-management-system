import { Component, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { AbstractControl, FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { RouterLink } from '@angular/router';

import { parseApiError } from '../../../core/models/api-error.model';
import { CreateCampaignResponse } from '../../../core/models/campaign-detail.model';
import { CampaignApiService } from '../../../core/services/campaign-api.service';
import { ErrorAlert } from '../../../shared/components/error-alert/error-alert';
import { parseRecipients } from '../../../shared/utils/recipients';
import { noDuplicateRecipientsValidator } from '../../../shared/validators/duplicate-email.validator';
import { notBlankValidator, recipientEmailsValidator } from '../../../shared/validators/email.validator';

/** API field name -> form control name */
const FIELD_MAP: Record<string, string> = {
  name: 'name',
  subject: 'subject',
  body: 'body',
  recipient_emails: 'recipients',
};

@Component({
  selector: 'app-campaign-create',
  imports: [ReactiveFormsModule, RouterLink, ErrorAlert],
  templateUrl: './campaign-create.html',
})
export class CampaignCreate {
  private readonly api = inject(CampaignApiService);
  private readonly fb = inject(FormBuilder).nonNullable;

  protected readonly created = signal<CreateCampaignResponse | null>(null);
  protected readonly submitError = signal<string | null>(null);
  protected readonly submitted = signal(false);
  protected readonly submitting = signal(false);

  protected readonly form = this.fb.group({
    name: ['', [notBlankValidator, Validators.maxLength(255)]],
    subject: ['', [notBlankValidator, Validators.maxLength(255)]],
    body: ['', [notBlankValidator, Validators.maxLength(10000)]],
    recipients: ['', [recipientEmailsValidator, noDuplicateRecipientsValidator]],
  });

  private readonly recipientsText = toSignal(this.form.controls.recipients.valueChanges, {
    initialValue: '',
  });
  protected readonly recipientCount = computed(() => parseRecipients(this.recipientsText()).length);

  protected showError(control: AbstractControl): boolean {
    return control.invalid && (control.touched || this.submitted());
  }

  protected submit(): void {
    this.submitted.set(true);
    this.submitError.set(null);
    this.created.set(null);

    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }

    const value = this.form.getRawValue();
    this.submitting.set(true);

    this.api
      .createCampaign({
        name: value.name.trim(),
        subject: value.subject.trim(),
        body: value.body.trim(),
        recipient_emails: parseRecipients(value.recipients),
      })
      .subscribe({
        next: (response) => {
          this.created.set(response);
          this.submitting.set(false);
          this.submitted.set(false);
          this.form.reset();
        },
        error: (err: unknown) => {
          const parsed = parseApiError(err);
          this.submitError.set(parsed.message);
          this.applyServerErrors(parsed.fieldErrors);
          this.submitting.set(false);
        },
      });
  }

  private applyServerErrors(fieldErrors: Record<string, string>): void {
    for (const [apiField, message] of Object.entries(fieldErrors)) {
      const control = this.form.get(FIELD_MAP[apiField] ?? apiField);
      control?.setErrors({ server: message });
      control?.markAsTouched();
    }
  }
}
