<?php

declare(strict_types=1);

use App\Livewire\Portal\Support\LeaveFormText;

/**
 * The Application for Leave lists HR's leave types in the printed form's
 * order, each with the legal basis the form prints. A type is matched by
 * what its name contains, and one the form does not list keeps no citation
 * and follows the listed ones in the order it came.
 */
it('cites the legal basis the form prints for a type it lists', function (string $name, string $citation) {
    expect(LeaveFormText::citation($name))->toBe($citation);
})->with([
    'vacation' => ['Vacation Leave', 'Sec. 51, Rule XVI, Omnibus Rules Implementing E.O. No. 292'],
    'forced, on its own' => ['Forced Leave', 'Sec. 25, Rule XVI, Omnibus Rules Implementing E.O. No. 292'],
    'mandatory/forced' => ['Mandatory/Forced Leave', 'Sec. 25, Rule XVI, Omnibus Rules Implementing E.O. No. 292'],
    'special privilege' => ['Special Privilege Leave', 'Sec. 21, Rule XVI, Omnibus Rules Implementing E.O. No. 292'],
    'women, not privilege' => ['Special Leave Benefits for Women', 'RA No. 9710 / CSC MC No. 25, s. 2010'],
    'violence against women' => ['10-Day VAWC Leave', 'RA No. 9262 / CSC MC No. 15, s. 2005'],
    'calamity' => ['Special Emergency (Calamity) Leave', 'CSC MC No. 2, s. 2012, as amended'],
]);

it('cites nothing for a type the form does not list', function (string $name) {
    expect(LeaveFormText::citation($name))->toBeNull();
})->with(['Emergency Leave', 'Leave Without Pay', 'Bereavement Leave']);

it('puts the rows in the form\'s order, the unlisted ones last as they came', function () {
    $rows = [['label' => 'Leave Without Pay'], ['label' => 'Sick Leave'], ['label' => 'Emergency Leave'], ['label' => 'Adoption Leave'], ['label' => 'Vacation Leave']];

    expect(array_column(LeaveFormText::arrange($rows, 'label'), 'label'))
        ->toBe(['Vacation Leave', 'Sick Leave', 'Adoption Leave', 'Leave Without Pay', 'Emergency Leave']);
});
