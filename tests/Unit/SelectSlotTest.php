<?php

declare(strict_types=1);

use App\Livewire\Kit\Support\SelectSlot;

/**
 * <x-kit.select> shows on its trigger, before any script runs, the option a
 * browser would show for the same <option> markup: the LAST one marked
 * `selected`, else the first that is not disabled. Its text is what
 * `HTMLOptionElement.text` reports, so the server's first label and the
 * label the script reads back afterwards are the same string.
 */
it('picks the option a browser would select', function (string $html, string $label, bool $placeholder) {
    expect(SelectSlot::initial($html))->toBe(['label' => $label, 'placeholder' => $placeholder]);
})->with([
    'first option when none is selected' => ['<option value="A">Alpha</option><option value="B">Beta</option>', 'Alpha', false],
    'the selected option' => ['<option value="A">Alpha</option><option value="B" selected>Beta</option>', 'Beta', false],
    'the last of several selected' => ['<option selected>Alpha</option><option selected="selected">Beta</option><option>Gamma</option>', 'Beta', false],
    'skips a disabled first option' => ['<option disabled>Alpha</option><option>Beta</option>', 'Beta', false],
    'the Flux placeholder' => ['<option value="" disabled selected class="placeholder">Select role</option><option value="1">Admin</option>', 'Select role', true],
    'a later selected option over the placeholder' => ['<option value="" disabled selected class="placeholder">Select</option><option value="1" selected>Admin</option>', 'Admin', false],
    'no options at all' => ['', '', true],
    'every option disabled' => ['<option disabled>Alpha</option>', '', true],
]);

it('reads boolean attributes, not words inside attribute values', function () {
    expect(SelectSlot::initial('<option value="selected">Alpha</option><option class="disabled-row">Beta</option>')['label'])->toBe('Alpha');
    expect(SelectSlot::initial('<option data-x="a selected b">Alpha</option><option value="B" selected>Beta</option>')['label'])->toBe('Beta');
    expect(SelectSlot::initial('<option wire:key="A" value="A" disabled>Alpha</option><option wire:key="B" value="B">Beta</option>')['label'])->toBe('Beta');
});

it('reports the text the browser reports', function () {
    // Tags dropped, entities decoded, ASCII whitespace collapsed — and the
    // no-break-space indent the department filter gives a unit kept.
    expect(SelectSlot::initial("<option value=\"x\">\n    Quezon   Depot\n    · 50 m\n</option>")['label'])->toBe('Quezon Depot · 50 m');
    expect(SelectSlot::initial('<option value="u">&#160;&#160;&#160;Payroll &amp; Benefits</option>')['label'])->toBe("\u{00A0}\u{00A0}\u{00A0}Payroll & Benefits");
    expect(SelectSlot::initial('<option value="x"><b>Bold</b> text</option>')['label'])->toBe('Bold text');
});
