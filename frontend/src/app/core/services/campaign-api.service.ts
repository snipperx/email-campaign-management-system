import { HttpClient, HttpHeaders } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';

import { API_BASE_URL } from '../api.config';
import { CampaignDetail, CreateCampaignRequest, CreateCampaignResponse } from '../models/campaign-detail.model';
import { Campaign } from '../models/campaign.model';

/** The API may return the payload bare or wrapped as { data }. Accept both. */
type Wrapped<T> = T | { data: T };

function unwrap<T>(response: Wrapped<T>): T {
  if (typeof response === 'object' && response !== null && 'data' in response) {
    return (response as { data: T }).data;
  }
  return response as T;
}

/** "2026-10-08 10:30:00" is not valid ISO; some browsers cannot parse it. */
function normaliseDate(value: string): string {
  return /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(value) ? value.replace(' ', 'T') : value;
}

@Injectable({ providedIn: 'root' })
export class CampaignApiService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = inject(API_BASE_URL);

  /** Pass the same idempotencyKey when retrying a submission so the API cannot create it twice. */
  createCampaign(payload: CreateCampaignRequest, idempotencyKey?: string): Observable<CreateCampaignResponse> {
    const headers = idempotencyKey ? new HttpHeaders({ 'Idempotency-Key': idempotencyKey }) : undefined;
    return this.http.post<CreateCampaignResponse>(`${this.baseUrl}/campaigns`, payload, { headers });
  }

  getCampaign(id: number | string): Observable<CampaignDetail> {
    return this.http
      .get<Wrapped<CampaignDetail>>(`${this.baseUrl}/campaigns/${id}`)
      .pipe(map((res) => this.withDate(unwrap(res))));
  }

  getCampaigns(): Observable<Campaign[]> {
    return this.http
      .get<Wrapped<Campaign[]>>(`${this.baseUrl}/campaigns`)
      .pipe(map((res) => unwrap(res).map((campaign) => this.withDate(campaign))));
  }

  private withDate<T extends Campaign>(campaign: T): T {
    return { ...campaign, created_at: normaliseDate(campaign.created_at) };
  }
}
