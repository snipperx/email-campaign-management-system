import { HttpErrorResponse, provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';

import { parseApiError } from '../models/api-error.model';
import { CampaignApiService } from './campaign-api.service';

const BASE = '/api';

describe('CampaignApiService', () => {
  let service: CampaignApiService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
    service = TestBed.inject(CampaignApiService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('getCampaigns() calls the list endpoint and unwraps { data }', () => {
    let result: unknown;
    service.getCampaigns().subscribe((campaigns) => (result = campaigns));

    const req = http.expectOne(`${BASE}/campaigns`);
    expect(req.request.method).toBe('GET');
    req.flush({
      data: [
        { id: 1, name: 'A', subject: 'S', recipient_count: 2, status: 'queued', created_at: '2026-10-08 10:30:00' },
      ],
    });

    expect(result).toEqual([
      { id: 1, name: 'A', subject: 'S', recipient_count: 2, status: 'queued', created_at: '2026-10-08T10:30:00' },
    ]);
  });

  it('getCampaigns() also accepts a bare array', () => {
    let result: unknown[] = [];
    service.getCampaigns().subscribe((campaigns) => (result = campaigns));

    http.expectOne(`${BASE}/campaigns`).flush([
      { id: 2, name: 'B', subject: 'S', recipient_count: 1, status: 'done', created_at: '2026-10-08T10:30:00Z' },
    ]);

    expect(result.length).toBe(1);
  });

  it('getCampaign() requests the detail endpoint', () => {
    let name = '';
    service.getCampaign(7).subscribe((campaign) => (name = campaign.name));

    const req = http.expectOne(`${BASE}/campaigns/7`);
    expect(req.request.method).toBe('GET');
    req.flush({
      data: {
        id: 7, name: 'Seven', subject: 'S', body: 'B', recipient_count: 1, status: 'processing',
        created_at: '2026-10-08 10:30:00', recipients: [{ email: 'a@test.com', status: 'pending' }],
      },
    });

    expect(name).toBe('Seven');
  });

  it('createCampaign() posts the payload', () => {
    const payload = { name: 'N', subject: 'S', body: 'B', recipient_emails: ['a@test.com'] };
    service.createCampaign(payload).subscribe();

    const req = http.expectOne(`${BASE}/campaigns`);
    expect(req.request.method).toBe('POST');
    expect(req.request.body).toEqual(payload);
    req.flush({ campaign_id: 1, recipient_count: 1, status: 'queued' }, { status: 201, statusText: 'Created' });
  });

  it('surfaces validation errors from a 422', () => {
    let error: unknown;
    service
      .createCampaign({ name: 'N', subject: '', body: 'B', recipient_emails: [] })
      .subscribe({ error: (e: unknown) => (error = e) });

    http.expectOne(`${BASE}/campaigns`).flush(
      { error: 'Invalid input', details: { subject: 'Subject is required', recipient_emails: 'Must contain valid emails' } },
      { status: 422, statusText: 'Unprocessable Entity' },
    );

    expect(error).toBeInstanceOf(HttpErrorResponse);
    expect(parseApiError(error)).toEqual({
      message: 'Invalid input',
      fieldErrors: { subject: 'Subject is required', recipient_emails: 'Must contain valid emails' },
    });
  });

  it('also understands Laravel\'s default validation shape', () => {
    const error = new HttpErrorResponse({
      status: 422,
      error: { message: 'The given data was invalid.', errors: { 'recipient_emails.0': ['The email is invalid.'] } },
    });

    expect(parseApiError(error)).toEqual({
      message: 'The given data was invalid.',
      fieldErrors: { recipient_emails: 'The email is invalid.' },
    });
  });

  it('reports an unreachable server', () => {
    const error = new HttpErrorResponse({ status: 0 });
    expect(parseApiError(error).message).toContain('Cannot reach the server');
  });
});
