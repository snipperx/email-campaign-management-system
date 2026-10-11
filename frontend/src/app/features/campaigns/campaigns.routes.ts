import { Routes } from '@angular/router';

export const CAMPAIGN_ROUTES: Routes = [
  {
    path: '',
    title: 'Campaigns',
    loadComponent: () => import('./campaign-list/campaign-list').then((m) => m.CampaignList),
  },
  {
    // Must stay above ':id' so "new" is not read as a campaign id
    path: 'new',
    title: 'New campaign',
    loadComponent: () => import('./campaign-create/campaign-create').then((m) => m.CampaignCreate),
  },
  {
    path: ':id',
    title: 'Campaign',
    loadComponent: () => import('./campaign-detail/campaign-detail').then((m) => m.CampaignDetail),
  },
];
