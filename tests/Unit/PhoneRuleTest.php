<?php

declare(strict_types=1);

use App\Livewire\Employees\EmployeeFields as LivewireEmployeeFields;
use App\Modules\Workforce\Application\EmployeeFields;
use App\Modules\Workforce\Domain\Contact\PhoneRule;

/**
 * PhoneRule is the ONE contact-number rule: an optional leading "+", 7 to 15
 * digits, and only spaces, dots, dashes or parentheses between them. Blank is
 * fine (required is the form's call), and both EmployeeFields copies forward
 * to it, so the wizard, the record page and My Profile cannot drift apart.
 *
 * Pure PHP; no container, so this runs under tests/Unit.
 */
it('accepts the spellings the company writes', function (string $value) {
    expect(PhoneRule::error($value))->toBeNull();
})->with([
    'blank' => '',
    'spaces only' => '   ',
    'mobile, spaced' => '0917 123 4567',
    'mobile, packed' => '09171234567',
    'mobile, dashed' => '0917-123-4567',
    'international' => '+63 917 123 4567',
    'landline with area code' => '(02) 8123-4567',
    'landline, dotted' => '02.8123.4567',
    'seven digits' => '8123456',
    'fifteen digits' => '+123456789012345',
    'padded' => '  0917 123 4567  ',
    // What a paste from a phone's contacts carries: folded, not refused.
    'pasted with no-break spaces' => "0917\u{00A0}123\u{202F}4567",
    'pasted with a Unicode hyphen' => "0917\u{2011}123\u{2013}4567",
    'wrapped in bidi marks' => "\u{200E}+63 917 123 4567\u{200F}",
]);

it('refuses letters, too few or too many digits and misplaced signs', function (string $value) {
    expect(PhoneRule::error($value))->toBe(PhoneRule::MESSAGE);
})->with([
    'letters' => 'abc',
    'extension in the field' => '0917 123 4567 loc 12',
    'one digit' => '1',
    'six digits' => '812345',
    'sixteen digits' => '1234567890123456',
    'plus in the middle' => '63+9171234567',
    'double plus' => '++639171234567',
    'punctuation only' => '() - .',
    'an email address' => 'name@company.com',
    'other-script digits' => '٠٩١٧١٢٣٤٥٦٧',
    'a non-breaking space' => "\u{00A0}",
    'only invisible marks' => "\u{200B}\u{200E}\u{FEFF}",
    'markup' => '<script>alert(1)</script>',
]);

it('is the rule both EmployeeFields copies apply', function () {
    foreach (['', '0917 123 4567', 'abc', '1'] as $value) {
        $expected = PhoneRule::error($value);

        expect(EmployeeFields::phoneError($value))->toBe($expected)
            ->and(LivewireEmployeeFields::phoneError($value))->toBe($expected);
    }
});
