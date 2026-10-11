import { DatePipe } from '@angular/common';
import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { RouterLink } from '@angular/router';
import { interval } from 'rxjs';

import { parseApiError } from '../../../core/models/api-error.model';
import { Campaign } from '../../../core/models/campaign.model';
import { CampaignApiService } from '../../../core/services/campaign-api.service';
import { EmptyState } from '../../../shared/components/empty-state/empty-state';
import { ErrorAlert } from '../../../shared/components/error-alert/error-alert';
import { LoadingSpinner } from '../../../shared/components/loading-spinner/loading-spinner';
import { StatusBadge } from '../../../shared/components/status-badge/status-badge';

const POLL_INTERVAL_MS = 5000;

@Component({
  selector: 'app-campaign-list',
  imports: [DatePipe, RouterLink, EmptyState, ErrorAlert, LoadingSpinner, StatusBadge],
  templateUrl: './campaign-list.html',
})
export class CampaignList implements OnInit {
  private readonly api = inject(CampaignApiService);

  protected readonly campaigns = signal<Campaign[]>([]);
  protected readonly error = signal<string | null>(null);
  protected readonly loading = signal(true);

  private readonly hasActiveCampaigns = computed(() =>
    this.campaigns().some((campaign) => campaign.status !== 'done'),
  );

  constructor() {
    // Keep statuses fresh while anything is still being processed
    interval(POLL_INTERVAL_MS)
      .pipe(takeUntilDestroyed())
      .subscribe(() => {
        if (this.hasActiveCampaigns()) {
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

    this.api.getCampaigns().subscribe({
      next: (campaigns) => {
        this.campaigns.set(campaigns);
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
