import { Directive, output } from '@angular/core';

/**
 * Backdrop behind a sheet or dialog: clicking it or pressing Escape dismisses
 * the overlay. Hidden from assistive technology; the dialog itself is the
 * accessible surface (and traps focus with cdkTrapFocus).
 */
@Directive({
  selector: '[appScrim]',
  host: {
    'aria-hidden': 'true',
    '(click)': 'dismiss.emit()',
    '(document:keydown.escape)': 'dismiss.emit()',
  },
})
export class Scrim {
  readonly dismiss = output();
}
