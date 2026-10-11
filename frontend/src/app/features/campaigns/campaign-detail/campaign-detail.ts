import { DatePipe } from '@angular/common';
import { Component, OnInit, computed, inject, input, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { RouterLink } from '@angular/router';
import { interval } from 'rxjs';

import { parseApiError } from '../../../core/models/api-error.model';
import { CampaignDetail as CampaignDetailModel } from '../../../core/models/campaign-detail.model';
import { CampaignApiService } from '../../../core/services/campaign-api.service';
import { ErrorAlert } from '../../../shared/components/error-alert/error-alert';
import { LoadingSpinner } from '../../../shared/components/loading-spinner/loading-spinner';
import { StatusBadge } from '../../../shared/components/status-badge/status-badge';

const POLL_INTERVAL_MS = 5000;

@Component({
  selector: 'app-campaign-detail',
  imports: [DatePipe, RouterLink, ErrorAlert, LoadingSpinner, StatusBadge],
  templateUrl: './campaign-detail.html',
})
export class CampaignDetail implements OnInit {
  /** Bound from the :id route parameter (withComponentInputBinding). */
  readonly id = input.required<string>();

  private readonly api = inject(CampaignApiService);

  protected readonly campaign = signal<CampaignDetailModel | null>(null);
  protected readonly error = signal<string | null>(null);
  protected readonly loading = signal(true);

  protected readonly counts = computed(() => {
    const recipients = this.campaign()?.recipients ?? [];
    return {
      failed: recipients.filter((r) => r.status === 'failed').length,
      pending: recipients.filter((r) => r.status === 'pending').length,
      sent: recipients.filter((r) => r.status === 'sent').length,
    };
  });

  constructor() {
    interval(POLL_INTERVAL_MS)
      .pipe(takeUntilDestroyed())
      .subscribe(() => {
        const current = this.campaign();
        if (current && current.status !== 'done') {
          this.load(true);
        }
      });
  }

  ngOnInit(): void {
    this.load();
  }

  protected load(silent = false): void {
    if (!silent) {
      this.loading.set(true);
      this.error.set(null);
    }

    this.api.getCampaign(this.id()).subscribe({
      next: (campaign) => {
        this.campaign.set(campaign);
        this.error.set(null);
        this.loading.set(false);
      },
      error: (err: unknown) => {
        this.error.set(parseApiError(err).message);
        this.loading.set(false);
      },
    });
  }
}
