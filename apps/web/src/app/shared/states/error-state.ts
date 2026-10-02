import { ChangeDetectionStrategy, Component, input, output, signal } from '@angular/core';

import { Icon } from '../icon';

@Component({
  selector: 'app-error',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Icon],
  templateUrl: './error-state.html',
  styleUrl: './error-state.scss',
})
export class ErrorState {
  readonly title = input("We couldn't refresh this data");
  readonly message = input('');
  readonly details = input<string | null>(null);
  readonly lastSuccess = input<string | null>(null);
  readonly retry = output();
  readonly open = signal(false);
}
