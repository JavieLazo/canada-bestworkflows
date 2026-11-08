<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Modules\IdentityAccess\Application\MobileEdtrDevice;
use App\Modules\IdentityAccess\Application\MobileEdtrSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer auth for the biometric kiosk. The account is read from the database
 * on every request, so a disabled user loses the device on its next call.
 * Punch sync stays with a System Admin. Registration is any active account.
 */
final class AuthenticateEdtrMobile
{
    /** The request attribute holding the authenticated {@see MobileEdtrDevice}. */
    public const string DEVICE = 'edtrMobileDevice';

    public function __construct(private readonly MobileEdtrSession $sessions) {}

    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $device = $this->sessions->authenticate($request->bearerToken(), $ability);

        $request->attributes->set(self::DEVICE, $device);
        $request->setUserResolver(static fn () => $device->user);

        return $next($request);
    }
}
