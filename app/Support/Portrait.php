<?php

declare(strict_types=1);

namespace App\Support;

use App\Modules\Workforce\Application\EmployeeFields;
use RuntimeException;

/**
 * The portrait resize — portrait.ts:29-63 in ~20 lines of raw GD, in one place.
 *
 * `Employees\Show` and `Profile\ProfilePage` both resize through here (via
 * Workforce's `UpdatePortrait`), so a change to the edge, the quality or the
 * white fill is made once. Their INTAKES stay separate on purpose —
 * Show writes the file the moment a photo is picked, ProfilePage stages it
 * until "Save account" — so this class owns only the decode-redraw-encode
 * step, never the storage or the audit row.
 *
 * Limits live in `EmployeeFields` (`PORTRAIT_MAX_EDGE`,
 * `PORTRAIT_JPEG_QUALITY`), where the pick-time `MAX_PHOTO_BYTES` cap also is.
 */
final class Portrait
{
    /**
     * Decode any GD-readable image and return it as a JPEG of at most
     * PORTRAIT_MAX_EDGE on the longest side, redrawn over white.
     *
     * Throws RuntimeException with the user-facing sentence the callers toast.
     */
    public static function toJpeg(string $bytes): string
    {
        $source = @imagecreatefromstring($bytes);

        if ($source === false) {
            throw new RuntimeException('Could not read that image.');
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1.0, EmployeeFields::PORTRAIT_MAX_EDGE / max($width, $height));
        // portrait.ts:41-42 uses Math.round, which is half-up on a positive
        // number; floor(x + 0.5) is that function. PHP round() is BANNED under
        // app/Support by PayrollArchitectureTest (it is the payroll rule, and
        // the scan cannot tell pixels from pesos), so it is not used here.
        $newWidth = max(1, (int) floor($width * $scale + 0.5));
        $newHeight = max(1, (int) floor($height * $scale + 0.5));

        $canvas = imagecreatetruecolor($newWidth, $newHeight);
        // Over white — transparent corners would go black otherwise.
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        ob_start();
        imagejpeg($canvas, null, EmployeeFields::PORTRAIT_JPEG_QUALITY);
        $jpeg = (string) ob_get_clean();

        imagedestroy($canvas);
        imagedestroy($source);

        if ($jpeg === '') {
            throw new RuntimeException('Could not read that image.');
        }

        return $jpeg;
    }
}
