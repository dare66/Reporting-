import { ChangeDetectionStrategy, Component, input } from '@angular/core';

/** Contextual loading: names the work being done instead of "Loading…". */
@Component({
  selector: 'app-working',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [],
  templateUrl: './working.html',
  styleUrl: './working.scss',
})
export class Working {
  readonly title = input('Analysing data');
  readonly steps = input<string[]>(['Discovering patterns', 'Checking anomalies', 'Building insights']);
}
