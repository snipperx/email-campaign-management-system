import { Routes } from '@angular/router';

export const routes: Routes = [
  { path: '', pathMatch: 'full', redirectTo: 'campaigns' },
  {
    path: 'campaigns',
    loadChildren: () => import('./features/campaigns/campaigns.routes').then((m) => m.CAMPAIGN_ROUTES),
  },
  { path: '**', redirectTo: 'campaigns' },
];
