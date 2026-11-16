<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Modules\WorkManagement\Application\Queries\ProjectRecordFiles;
use App\Support\Access;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class RecordFileController extends Controller
{
    public function __invoke(Request $request, string $file): StreamedResponse
    {
        $data = $request->validate(['preview' => 'nullable|boolean', 'submission' => 'nullable|string|size:26', 'project' => 'required_with:submission|string|size:26']);

        if (isset($data['submission'], $data['project'])) {
            return app(ProjectRecordFiles::class)->submitted(Access::forActor(auth()->user()), $data['project'], $data['submission'], $file, $request->boolean('preview'));
        }

        return app(ProjectRecordFiles::class)->download(Access::forActor(auth()->user()), $file, $request->boolean('preview'));
    }
}
