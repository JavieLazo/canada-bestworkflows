<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\EmployeeProfile;
use App\Modules\Workforce\Application\EmployeeFields;
use App\Platform\Records\AccountEmployeeLinks;
use App\Support\Access;
use App\Support\AuditService;
use App\Support\EmployeeName;
use App\Support\Portrait;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Employee Records publishes management portrait edits; Profile submits photos for review. */
final class UpdatePortrait
{
    public function handle(Access $actor, string $employeeId, ?UploadedFile $upload, bool $ownOnly = false): bool
    {
        abort_unless($actor->mayReadAllData(ModuleCode::EMPLOYEES), 403);
        abort_if($ownOnly, 403);
        $employee = EmployeeProfile::query()->find($employeeId);
        if ($employee === null) {
            return false;
        }
        if ($upload !== null) {
            if (! str_starts_with((string) $upload->getMimeType(), 'image/')) {
                throw new RuntimeException('Pick an image file.');
            }
            $size = (int) $upload->getSize();
            if ($size > EmployeeFields::MAX_PHOTO_BYTES) {
                throw new RuntimeException('That image is '.EmployeeFields::humanSize($size).' — the limit is '.EmployeeFields::humanSize(EmployeeFields::MAX_PHOTO_BYTES).'.');
            }
            $jpeg = Portrait::toJpeg((string) file_get_contents($upload->getRealPath()));
        }
        $path = $upload === null ? null : 'portraits/'.$employee->id.'/'.Str::ulid().'.jpg';
        try {
            if ($path !== null && ! Storage::disk('public')->put($path, $jpeg)) {
                throw new RuntimeException('The photo could not be stored.');
            }
            $changed = DB::transaction(function () use ($actor, $employeeId, $path): bool {
                abort_unless(app(AccountEmployeeLinks::class)->isActive((int) $actor->userId), 403);
                if ($path !== null) {
                    DB::afterRollBack(fn () => $this->discard($path));
                }
                $current = EmployeeProfile::query()->whereKey($employeeId)->lockForUpdate()->first();
                if ($current === null) {
                    return false;
                }
                $oldPath = $current->photo_path;
                $current->photo_path = $path;
                $current->save();
                if ($oldPath !== null && $oldPath !== $path) {
                    DB::afterCommit(fn () => $this->discard($oldPath));
                }

                return true;
            });
        } catch (Throwable $error) {
            if ($path !== null) {
                $this->discard($path);
            }
            throw $error;
        }
        if (! $changed) {
            if ($path !== null) {
                $this->discard($path);
            }

            return false;
        }
        AuditService::record($actor, AuditEvent::EMPLOYEE_UPDATED, 'employee_profiles', $employee->id, EmployeeName::display($employee).' — photo updated');

        return true;
    }

    private function discard(string $path): void
    {
        try {
            if (! Storage::disk('public')->delete($path)) {
                Log::error('[portraits] cleanup failed', ['path' => $path]);
            }
        } catch (Throwable $error) {
            Log::error('[portraits] cleanup failed', ['path' => $path, 'error' => $error->getMessage()]);
        }
    }
}
