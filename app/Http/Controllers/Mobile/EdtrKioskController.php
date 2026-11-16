<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateEdtrMobile;
use App\Modules\IdentityAccess\Application\MobileEdtrDevice;
use App\Modules\IdentityAccess\Application\MobileEdtrSession;
use App\Modules\IdentityAccess\Application\MobileEdtrSignInRefused;
use App\Modules\Organization\Application\Queries\KioskSites;
use App\Modules\Timekeeping\Application\Commands\SyncMobilePunches;
use App\Modules\Workforce\Application\Queries\KioskEmployees;
use App\Support\Access;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The biometric kiosk. A System Admin can register any staff member and pick
 * the site the device stands at. Anyone else can register only the employee
 * linked to their own account, then sign out so the next person can. Punches
 * sync with the eDTR station rules.
 */
final class EdtrKioskController extends Controller
{
    public function login(Request $request, MobileEdtrSession $sessions, KioskEmployees $employees): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'device_id' => ['required', 'uuid'],
            'code' => ['nullable', 'string', 'max:32'],
        ]);

        try {
            $account = $sessions->verify($data['email'], $data['password'], $data['code'] ?? null);
        } catch (MobileEdtrSignInRefused $refused) {
            return response()->json(['message' => $refused->getMessage()], $refused->status);
        }

        $employee = $employees->own(Access::forActor($account->user));
        if (! $account->systemAdmin && $employee === null) {
            return response()->json(['message' => 'This account is not linked to an employee record.'], 422);
        }

        $tokens = $sessions->start($account, $data['device_id']);

        return response()->json([
            'token_type' => 'Bearer',
            'admin_token' => $tokens['admin'],
            'kiosk_token' => $tokens['kiosk'],
            'device_id' => $data['device_id'],
            'account' => [
                'name' => $account->name,
                'email' => $account->email,
                'role' => $account->role,
                'system_admin' => $account->systemAdmin,
                'employee' => $employee,
            ],
        ]);
    }

    public function logout(Request $request, MobileEdtrSession $sessions): JsonResponse
    {
        $device = $this->device($request, 'Sign in to register biometrics.');
        $sessions->signOut(Access::forActor($device->user), $device->tokenId);

        return response()->json(['message' => 'Signed out. This device can keep syncing punches.']);
    }

    public function employees(Request $request, MobileEdtrSession $sessions, KioskEmployees $employees): JsonResponse
    {
        $actor = Access::forActor($this->device($request, 'Sign in to register biometrics.')->user);
        if (! $sessions->isSystemAdmin($actor)) {
            $own = $employees->own($actor);

            return response()->json(['employees' => $own === null ? [] : [$own]]);
        }

        return response()->json(['employees' => $employees->search($actor, $request->string('q')->toString())]);
    }

    /**
     * The sites a device can be set up at. Choosing where a device stands is
     * the System Admin's, like connecting it: a staff sign-in gets no list.
     */
    public function sites(Request $request, MobileEdtrSession $sessions, KioskSites $sites): JsonResponse
    {
        $actor = Access::forActor($this->device($request, 'Sign in to register biometrics.')->user);

        return response()->json(['sites' => $sessions->isSystemAdmin($actor) ? $sites->all($actor) : []]);
    }

    public function punches(Request $request, SyncMobilePunches $sync): JsonResponse
    {
        $data = $request->validate([
            'punches' => ['required', 'array', 'min:1', 'max:40'],
            'punches.*.client_id' => ['required', 'uuid'],
            'punches.*.employee_id' => ['required', 'string', 'max:26'],
            'punches.*.log_type' => ['required', 'in:TIME_IN,TIME_OUT'],
            'punches.*.logged_at' => ['required', 'string', 'max:40'],
            // The site the device stands at, when it was set up at one.
            'punches.*.site_id' => ['nullable', 'string', 'max:26'],
            'punches.*.latitude' => ['nullable', 'numeric'],
            'punches.*.longitude' => ['nullable', 'numeric'],
            'punches.*.accuracy' => ['nullable', 'numeric'],
        ]);

        $actor = Access::forActor($this->device($request, 'This device is not connected to EMS.')->user);

        try {
            $results = $sync->handle($actor, $data['punches']);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        }

        return response()->json(['results' => $results]);
    }

    /** The device {@see AuthenticateEdtrMobile} authenticated for this request. */
    private function device(Request $request, string $unauthenticated): MobileEdtrDevice
    {
        $device = $request->attributes->get(AuthenticateEdtrMobile::DEVICE);
        if (! $device instanceof MobileEdtrDevice) {
            abort(401, $unauthenticated);
        }

        return $device;
    }
}
