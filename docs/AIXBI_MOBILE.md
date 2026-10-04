# Mobile

## Today: responsive web app (built)

The Angular app is designed for phones as well as desktops ([ADR 0006](adr/0006-angular-web-flutter-mobile.md)):

- **Navigation:** a bottom navigation bar replaces the sidebar on small screens.
- **Dashboards:** reflow from 12 to 8 to 4 columns. Widgets keep their reading order by priority instead of shrinking.
- **Reports:** open in a swipeable reader.
- **Home briefing:** cached, and shown immediately when offline, clearly marked as cached.
- **Installable:** a web manifest lets the app be added to a phone's home screen.
- **Notification channels** for alerts, anomalies, reports, mentions and data quality, chosen per category in Settings. In-app and email are delivered. Push goes through a `PushGateway` interface whose current implementation only logs; delivering to phones needs a provider such as FCM to be configured.

Tables that are too wide for a phone hide their least important columns, for example in the Metric Store and Trust Center.

## Not built yet (brief §49–50)

| Capability | Plan |
|---|---|
| Push delivery | A `PushGateway` implementation for FCM/APNs, plus device registration |
| Native app (Flutter) | Same API; home briefing, AI chat over the streaming endpoint, dashboards through the mobile layout, report reader, push with deep links, biometric unlock, secure token storage |
| Offline cache beyond the briefing | Service worker caching recently viewed dashboards and reports |
| Approvals on mobile | Approving a metric certification or an action from a notification (needs the action engine) |
| Voice | Speech-to-text and text-to-speech behind an interface, feeding the existing analyst |

## How it is tested

The browser journeys run at desktop size. A phone-size pass, at a 390 px viewport, of home, a dashboard and a report is part of the planned UI test additions in [AIXBI_TESTING.md](AIXBI_TESTING.md).
