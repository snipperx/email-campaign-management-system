import { Component, input } from '@angular/core';

@Component({
  selector: 'app-loading-spinner',
  template: `
    <div class="d-flex justify-content-center align-items-center gap-2 py-5 text-secondary" role="status">
      <div class="spinner-border spinner-border-sm" aria-hidden="true"></div>
      <span>{{ label() }}</span>
    </div>
  `,
})
export class LoadingSpinner {
  readonly label = input('Loading...');
}
