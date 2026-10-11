import { HttpErrorResponse } from '@angular/common/http';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { of, throwError } from 'rxjs';
import { vi } from 'vitest';

import { CampaignDetail as CampaignDetailModel } from '../../../core/models/campaign-detail.model';
import { CampaignApiService } from '../../../core/services/campaign-api.service';
import { CampaignDetail } from './campaign-detail';

const DETAIL: CampaignDetailModel = {
  id: 1,
  name: 'Spring Sale',
  subject: '50% off',
  body: 'Check out our deals',
  recipient_count: 3,
  status: 'processing',
  created_at: '2026-10-08T10:30:00',
  recipients: [
    { email: 'a@test.com', status: 'sent' },
    { email: 'b@test.com', status: 'pending' },
    { email: 'c@test.com', status: 'failed' },
  ],
};

describe('CampaignDetail', () => {
  const getCampaign = vi.fn();
  let fixture: ComponentFixture<CampaignDetail>;
  let el: HTMLElement;

  async function render(): Promise<void> {
    fixture = TestBed.createComponent(CampaignDetail);
    fixture.componentRef.setInput('id', '1');
    el = fixture.nativeElement as HTMLElement;
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  beforeEach(() => {
    getCampaign.mockReset();
    TestBed.configureTestingModule({
      imports: [CampaignDetail],
      providers: [provideRouter([]), { provide: CampaignApiService, useValue: { getCampaign } }],
    });
  });

  it('renders the campaign and each recipient with its status', async () => {
    getCampaign.mockReturnValue(of(DETAIL));
    await render();

    expect(getCampaign).toHaveBeenCalledWith('1');
    expect(el.textContent).toContain('Spring Sale');
    expect(el.textContent).toContain('Check out our deals');
    expect(el.querySelectorAll('tbody tr').length).toBe(3);
    const counts = el.querySelector('[data-testid="counts"]')?.textContent?.replace(/\s+/g, '');
    expect(counts).toBe('1sent1pending1failed');
  });

  it('shows the API message when the campaign does not exist', async () => {
    getCampaign.mockReturnValue(
      throwError(() => new HttpErrorResponse({ status: 404, error: { error: 'Campaign not found' } })),
    );
    await render();

    expect(el.querySelector('[role="alert"]')?.textContent).toContain('Campaign not found');
  });
});
