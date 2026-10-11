import { Component, computed, input } from '@angular/core';

const BADGE_CLASSES: Record<string, string> = {
  queued: 'text-bg-secondary',
  processing: 'text-bg-primary',
  done: 'text-bg-success',
  pending: 'text-bg-secondary',
  sent: 'text-bg-success',
  failed: 'text-bg-danger',
};

@Component({
  selector: 'app-status-badge',
  template: `<span class="badge text-capitalize" [class]="cssClass()">{{ status() }}</span>`,
})
export class StatusBadge {
  readonly status = input.required<string>();
  protected readonly cssClass = computed(() => BADGE_CLASSES[this.status()] ?? 'text-bg-light');
}
