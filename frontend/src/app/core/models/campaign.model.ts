export type CampaignStatus = 'queued' | 'processing' | 'done';
export type RecipientStatus = 'pending' | 'sent' | 'failed';

export interface Campaign {
  id: number;
  name: string;
  subject: string;
  recipient_count: number;
  status: CampaignStatus;
  created_at: string;
}
