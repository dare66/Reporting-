const NOTIFICATION_ICONS: Record<string, string> = {
  alert: 'alert',
  anomaly: 'warning',
  report: 'report',
  mention: 'comment',
  data: 'data',
  forecast: 'forecast',
};

/** Icon name for a notification type. */
export function notificationIcon(type: string): string {
  return NOTIFICATION_ICONS[type] ?? 'info';
}
