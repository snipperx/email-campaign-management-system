import { Campaign } from './campaign.model';
import { Recipient } from './email-job.model';

export interface CampaignDetail extends Campaign {
  body: string;
  recipients: Recipient[];
}

export interface CreateCampaignRequest {
  name: string;
  subject: string;
  body: string;
  recipient_emails: string[];
}

export interface CreateCampaignResponse {
  campaign_id: number;
  recipient_count: number;
  status: string;
}
