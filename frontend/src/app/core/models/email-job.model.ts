import { RecipientStatus } from './campaign.model';

export interface Recipient {
  email: string;
  status: RecipientStatus;
}
