<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application;

use App\Models\EmployeeProfile;
use App\Models\EmployeeRecordChangeRequest;
use App\Platform\Database\TransactionMutex;
use App\Platform\Files\AttachmentStore;
use App\Platform\Files\AttachmentUsage;
use App\Platform\Records\AccountEmployeeLinks;
use App\Support\Access;
use App\Support\Portrait;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Private, immutable portrait evidence survives later replacements of the public current portrait. */
final class RecordPortraitChanges
{
    public function submit(Access $access, ?UploadedFile $upload, string $reason = ''): string
    {
        abort_unless($access->isSignedIn() && $access->hasEmployeeLink(), 403);
        $next = null;
        if ($upload !== null) {
            if (! str_starts_with((string) $upload->getMimeType(), 'image/')) {
                throw new RuntimeException('Pick an image file.');
            }
            if ((int) $upload->getSize() > EmployeeFields::MAX_PHOTO_BYTES) {
                throw new RuntimeException('That image exceeds the portrait size limit.');
            }
            $bytes = file_get_contents($upload->getRealPath());
            if ($bytes === false) {
                throw new RuntimeException('The photo could not be read.');
            }
            $next = Portrait::toJpeg($bytes);
        }
        $temp = [];
        $stored = [];
        try {
            return DB::transaction(function () use ($access, $next, $reason, &$temp, &$stored): string {
                TransactionMutex::acquire('copems:attachment-budget');
                abort_unless(app(AccountEmployeeLinks::class)->isActiveLinked((int) $access->userId, $access->ownEmployeeId()), 403);
                $employee = EmployeeProfile::query()->whereKey($access->ownEmployeeId())->lockForUpdate()->firstOrFail();
                $old = null;
                if ($employee->photo_path !== null) {
                    $old = Storage::disk('public')->get($employee->photo_path);
                    if ($old === null) {
                        throw new RuntimeException('The current photo is missing. Ask management to restore it before requesting a replacement.');
                    }
                }
                if ($old === $next) {
                    throw new RuntimeException('There are no changes to submit.');
                }
                $size = strlen($old ?? '') + strlen($next ?? '');
                if (AttachmentUsage::bytes() + $size > EmployeeFields::storageCeilingBytes()) {
                    throw new RuntimeException('The file store is full.');
                }
                $form = ['photo_previous_path' => null, 'photo_proposed_path' => null, 'photo_previous_size' => strlen($old ?? ''), 'photo_proposed_size' => strlen($next ?? '')];
                $persist = function (array $evidence) use ($access, $employee, $reason, $next): string {
                    $before = ['photo_path' => $employee->photo_path];
                    $after = ['photo_path' => $next === null ? null : $evidence['photo_proposed_path']];
                    $changes = [['field' => 'photo_path', 'label' => 'Photo', 'before' => $employee->photo_path === null ? null : 'Previous photo', 'after' => $next === null ? null : 'Requested photo', 'before_known' => true, 'after_known' => true]];

                    return app(RecordChangeSubmission::class)->store($access, $employee, 'PORTRAIT', 'portrait', $next === null ? 'DELETE' : 'UPDATE', null, $before, $after, $evidence, $changes, $reason)->id;
                };
                $storeNext = function (array $evidence) use ($next, $persist, &$temp, &$stored): string {
                    if ($next === null) {
                        return $persist($evidence);
                    }
                    $file = $this->temporary($next, $temp);

                    return app(AttachmentStore::class)->store($file, 'employee-record-changes', Str::ulid().'.jpg', static function (string $path) use ($evidence, $persist, &$stored): string {
                        $stored[] = $path;
                        $evidence['photo_proposed_path'] = $path;

                        return $persist($evidence);
                    });
                };
                if ($old === null) {
                    return $storeNext($form);
                }
                $file = $this->temporary($old, $temp);

                return app(AttachmentStore::class)->store($file, 'employee-record-changes', Str::ulid().'.jpg', static function (string $path) use ($form, $storeNext, &$stored): string {
                    $stored[] = $path;
                    $form['photo_previous_path'] = $path;

                    return $storeNext($form);
                });
            });
        } catch (Throwable $error) {
            foreach ($stored as $path) {
                try {
                    Storage::disk('local')->delete($path);
                } catch (Throwable $cleanup) {
                    Log::error('[record-photo] evidence cleanup failed', ['path' => $path, 'error' => $cleanup->getMessage()]);
                }
            }
            throw $error;
        } finally {
            foreach ($temp as $path) {
                @unlink($path);
            }
        }
    }

    /** Caller holds the employee lock; returns the public path to compensate if the outer transaction fails. */
    public function apply(EmployeeProfile $employee, EmployeeRecordChangeRequest $request): ?string
    {
        RecordChangeData::assertCurrent($request->baseline, ['photo_path' => $employee->photo_path]);
        $private = $request->form['photo_proposed_path'] ?? null;
        $path = null;
        if (is_string($private)) {
            $bytes = Storage::disk('local')->get($private);
            if ($bytes === null) {
                throw new RuntimeException('The requested photo evidence is missing.');
            }
            $path = 'portraits/'.$employee->id.'/'.Str::ulid().'.jpg';
            if (! Storage::disk('public')->put($path, $bytes)) {
                throw new RuntimeException('The approved photo could not be stored.');
            }
            DB::afterRollBack(fn () => $this->discard($path));
        }
        $old = $employee->photo_path;
        try {
            $employee->photo_path = $path;
            $employee->save();
        } catch (Throwable $error) {
            if ($path !== null) {
                $this->discard($path);
            }
            throw $error;
        }
        if ($old !== null) {
            DB::afterCommit(fn () => $this->discard($old));
        }

        return $path;
    }

    public function discard(string $path): void
    {
        try {
            if (! Storage::disk('public')->delete($path)) {
                Log::error('[record-photo] cleanup failed', ['path' => $path]);
            }
        } catch (Throwable $error) {
            Log::error('[record-photo] cleanup failed', ['path' => $path, 'error' => $error->getMessage()]);
        }
    }

    /** @param list<string> $paths */
    private function temporary(string $bytes, array &$paths): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'ems-portrait-');
        if ($path === false) {
            throw new RuntimeException('The portrait could not be prepared.');
        }
        $paths[] = $path;
        if (file_put_contents($path, $bytes) === false) {
            throw new RuntimeException('The portrait could not be prepared.');
        }

        return new UploadedFile($path, 'portrait.jpg', 'image/jpeg', null, true);
    }
}
