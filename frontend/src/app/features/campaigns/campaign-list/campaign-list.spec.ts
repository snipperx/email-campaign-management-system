import { HttpErrorResponse } from '@angular/common/http';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { Subject, of, throwError } from 'rxjs';
import { vi } from 'vitest';

import { Campaign } from '../../../core/models/campaign.model';
import { CampaignApiService } from '../../../core/services/campaign-api.service';
import { CampaignList } from './campaign-list';

const CAMPAIGNS: Campaign[] = [
  { id: 1, name: 'Spring Sale', subject: '50% off', recipient_count: 25, status: 'processing', created_at: '2026-10-08T10:30:00' },
  { id: 2, name: 'Autumn', subject: 'Leaves', recipient_count: 3, status: 'done', created_at: '2026-10-07T09:00:00' },
];

describe('CampaignList', () => {
  const getCampaigns = vi.fn();
  let fixture: ComponentFixture<CampaignList>;
  let el: HTMLElement;

  async function render(): Promise<void> {
    fixture = TestBed.createComponent(CampaignList);
    el = fixture.nativeElement as HTMLElement;
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  beforeEach(() => {
    getCampaigns.mockReset();
    TestBed.configureTestingModule({
      imports: [CampaignList],
      providers: [provideRouter([]), { provide: CampaignApiService, useValue: { getCampaigns } }],
    });
  });

  it('shows a loading state until the request completes', () => {
    getCampaigns.mockReturnValue(new Subject<Campaign[]>());
    fixture = TestBed.createComponent(CampaignList);
    fixture.detectChanges();

    expect((fixture.nativeElement as HTMLElement).textContent).toContain('Loading campaigns');
  });

  it('renders a row per campaign with its status', async () => {
    getCampaigns.mockReturnValue(of(CAMPAIGNS));
    await render();

    expect(el.querySelectorAll('tbody tr').length).toBe(2);
    expect(el.textContent).toContain('Spring Sale');
    expect(el.textContent).toContain('processing');
    expect(el.textContent).toContain('done');
  });

  it('shows the empty state when there are no campaigns', async () => {
    getCampaigns.mockReturnValue(of([]));
    await render();

    expect(el.textContent).toContain('No campaigns yet');
    expect(el.querySelector('table')).toBeNull();
  });

  it('shows an error with a retry button when the request fails', async () => {
    getCampaigns.mockReturnValue(throwError(() => new HttpErrorResponse({ status: 0 })));
    await render();

    expect(el.querySelector('[role="alert"]')?.textContent).toContain('Cannot reach the server');
    expect(el.querySelector('button')?.textContent).toContain('Retry');
  });
});
