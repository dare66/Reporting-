import { ChangeDetectionStrategy, Component, input, output } from '@angular/core';

import { Icon } from '../icon';

@Component({
  selector: 'app-empty',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Icon],
  templateUrl: './empty.html',
  styleUrl: './empty.scss',
})
export class Empty {
  readonly eyebrow = input('Ready');
  readonly title = input.required<string>();
  readonly body = input('');
  readonly action = input<string | null>(null);
  readonly act = output();
}
