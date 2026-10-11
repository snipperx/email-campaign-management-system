import { Component, input } from '@angular/core';

@Component({
  selector: 'app-empty-state',
  template: `
    <div class="text-center text-secondary py-5">
      <p class="mb-1 fw-semibold">{{ title() }}</p>
      @if (hint()) {
        <p class="mb-3 small">{{ hint() }}</p>
      }
      <ng-content />
    </div>
  `,
})
export class EmptyState {
  readonly title = input.required<string>();
  readonly hint = input<string | null>(null);
}
