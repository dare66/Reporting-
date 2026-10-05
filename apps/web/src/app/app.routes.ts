import { Routes } from '@angular/router';
import { authGuard, permissionGuard } from './core/guards';

export const routes: Routes = [
  {
    path: '',
    loadComponent: () => import('./features/landing/landing').then((m) => m.Landing),
    title: 'AIXBI — Enterprise intelligence, reimagined',
  },
  {
    path: '',
    canActivate: [authGuard],
    loadComponent: () => import('./layout/shell').then((m) => m.Shell),
    children: [
      {
        path: 'home',
        loadComponent: () => import('./features/home/home').then((m) => m.Home),
        title: 'Command Centre · AIXBI',
      },
      {
        path: 'insights',
        loadComponent: () => import('./features/insights/insights').then((m) => m.Insights),
        title: 'Insights · AIXBI',
      },
      {
        path: 'investigate',
        loadComponent: () => import('./features/investigate/investigate').then((m) => m.Investigate),
        title: 'Investigate · AIXBI',
      },
      {
        path: 'explore',
        canActivate: [permissionGuard('query.run')],
        loadComponent: () => import('./features/explore/explore').then((m) => m.Explore),
        title: 'Explore · AIXBI',
      },
      {
        path: 'ai',
        canActivate: [permissionGuard('ai.use')],
        loadComponent: () => import('./features/ai/ai').then((m) => m.AiAnalyst),
        title: 'AI Analyst · AIXBI',
      },
      {
        path: 'dashboards',
        loadComponent: () => import('./features/dashboards/dashboards').then((m) => m.Dashboards),
        title: 'Dashboards · AIXBI',
      },
      {
        path: 'dashboards/:id',
        loadComponent: () => import('./features/dashboards/dashboard-view').then((m) => m.DashboardView),
        title: 'Dashboard · AIXBI',
      },
      {
        path: 'reports',
        canActivate: [permissionGuard('reports.view')],
        loadComponent: () => import('./features/reports/reports').then((m) => m.Reports),
        title: 'Reports · AIXBI',
      },
      {
        path: 'reports/:id',
        canActivate: [permissionGuard('reports.view')],
        loadComponent: () => import('./features/reports/report-view').then((m) => m.ReportView),
        title: 'Report · AIXBI',
      },
      {
        path: 'forecast',
        canActivate: [permissionGuard('analytics.advanced')],
        loadComponent: () => import('./features/forecast/forecast').then((m) => m.ForecastPage),
        title: 'Forecast & Scenarios · AIXBI',
      },
      {
        path: 'alerts',
        canActivate: [permissionGuard('alerts.view')],
        loadComponent: () => import('./features/alerts/alerts').then((m) => m.Alerts),
        title: 'Alerts · AIXBI',
      },
      {
        path: 'data',
        canActivate: [permissionGuard('data.view')],
        loadComponent: () => import('./features/data/data').then((m) => m.DataPage),
        title: 'Data · AIXBI',
      },
      {
        path: 'data/sources/:id/auto-bi',
        canActivate: [permissionGuard('data.view')],
        loadComponent: () => import('./features/data/auto-bi/auto-bi').then((m) => m.AutoBiPage),
        title: 'Auto BI Designer · AIXBI',
      },
      {
        path: 'data/datasets/:id',
        canActivate: [permissionGuard('data.view')],
        loadComponent: () => import('./features/data/dataset').then((m) => m.DatasetPage),
        title: 'Dataset · AIXBI',
      },
      {
        path: 'trust',
        canActivate: [permissionGuard('data.view')],
        loadComponent: () => import('./features/trust/trust').then((m) => m.TrustCenterPage),
        title: 'Data Trust Center · AIXBI',
      },
      {
        path: 'metrics',
        canActivate: [permissionGuard('semantic.view')],
        loadComponent: () => import('./features/metrics/metric-store').then((m) => m.MetricStorePage),
        title: 'Metric Store · AIXBI',
      },
      {
        path: 'semantic',
        canActivate: [permissionGuard('semantic.view')],
        loadComponent: () => import('./features/semantic/semantic').then((m) => m.Semantic),
        title: 'Semantic Model · AIXBI',
      },
      {
        path: 'governance',
        canActivate: [permissionGuard('governance.view', 'audit.view')],
        loadComponent: () => import('./features/governance/governance').then((m) => m.Governance),
        title: 'Governance · AIXBI',
      },
      {
        path: 'admin',
        canActivate: [permissionGuard('admin.users', 'admin.system', 'admin.org')],
        loadComponent: () => import('./features/admin/admin').then((m) => m.Admin),
        title: 'Administration · AIXBI',
      },
      {
        path: 'actions',
        canActivate: [permissionGuard('actions.request', 'actions.approve')],
        loadComponent: () => import('./features/actions/actions').then((m) => m.Actions),
        title: 'Actions · AIXBI',
      },
      {
        path: 'projects',
        loadComponent: () => import('./features/projects/projects').then((m) => m.Projects),
        title: 'Projects · AIXBI',
      },
      {
        path: 'notifications',
        loadComponent: () => import('./features/notifications/notifications').then((m) => m.Notifications),
        title: 'Notifications · AIXBI',
      },
      {
        path: 'settings',
        loadComponent: () => import('./features/settings/settings').then((m) => m.Settings),
        title: 'Settings · AIXBI',
      },
    ],
  },
  { path: '**', redirectTo: 'home' },
];
