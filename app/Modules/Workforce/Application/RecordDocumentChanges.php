<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application;

use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\EmployeeProfile;
use App\Models\EmployeeRecordChangeRequest;
use App\Support\Access;
use RuntimeException;

/** Retains document snapshot helpers while refusing application of employee document proposals. */
final class RecordDocumentChanges
{
    /** @param array<string, mixed> $form
     * @return array{document_no: ?string, name: string, category: string, expires_on: ?string}
     */
    public static function input(array $form, string $filename): array
    {
        $category = EmployeeInput::text($form['category'] ?? '');
        if ($category === '' || DocumentType::query()->where('name', $category)->doesntExist()) {
            throw new RuntimeException('Choose a category from Document Settings.');
        }
        $expires = EmployeeInput::text($form['expires_on'] ?? '');
        if ($expires !== '' && ! EmployeeFields::isCalendarDay($expires)) {
            throw new RuntimeException('Expiration must be a real calendar date.');
        }
        $name = EmployeeInput::text($form['name'] ?? '') ?: $filename;
        $number = EmployeeInput::text($form['document_no'] ?? '');
        if (mb_strlen($name) > 200 || mb_strlen($number) > 80 || mb_strlen($category) > 80) {
            throw new RuntimeException('Document name, number or category is too long.');
        }

        return ['document_no' => $number !== '' ? $number : null, 'name' => $name,
            'category' => $category, 'expires_on' => $expires !== '' ? $expires : null];
    }

    /** @return array<string, mixed> */
    public static function snapshot(EmployeeDocument $document): array
    {
        return ['document_no' => $document->document_no, 'name' => $document->name,
            'category' => $document->category, 'expires_on' => $document->expires_on?->format('Y-m-d'),
            'file_name' => $document->file_name, 'mime' => $document->mime, 'size' => $document->size];
    }

    public function assertCurrent(EmployeeProfile $employee, EmployeeRecordChangeRequest $request): void
    {
        throw new RuntimeException('Document changes must be made by management. Reject this request and update the employee record directly.');
    }

    public function apply(Access $actor, EmployeeProfile $employee, EmployeeRecordChangeRequest $request): void
    {
        abort(403, 'Documents are managed by management.');
    }
}
