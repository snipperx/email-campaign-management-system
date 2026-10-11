import { Component, input, output } from '@angular/core';

@Component({
  selector: 'app-error-alert',
  template: `
    @if (message(); as text) {
      <div class="alert alert-danger d-flex justify-content-between align-items-center" role="alert">
        <span>{{ text }}</span>
        @if (retryable()) {
          <button type="button" class="btn btn-sm btn-outline-danger" (click)="retry.emit()">Retry</button>
        }
      </div>
    }
  `,
})
export class ErrorAlert {
  readonly message = input<string | null>(null);
  readonly retryable = input(false);
  readonly retry = output<void>();
}
