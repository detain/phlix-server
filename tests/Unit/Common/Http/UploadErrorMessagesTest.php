<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Http;

use Phlix\Common\Http\UploadErrorMessages;
use PHPUnit\Framework\TestCase;

/**
 * Pins the single-source wording of the UPLOAD_ERR_* arms (minors M-SERVER-08,
 * batch W-min103). Before this class existed the two upload controllers each
 * carried a private copy of this table and they had drifted; every arm below
 * is the canonical (UserAvatar) wording, and UserAvatarControllerTest pins
 * the INI-size arm end-to-end through the HTTP surface.
 */
final class UploadErrorMessagesTest extends TestCase
{
    public function testIniSizeArm(): void
    {
        self::assertSame('File exceeds server upload limit', UploadErrorMessages::describe(UPLOAD_ERR_INI_SIZE));
    }

    public function testFormSizeArmIsDistinctFromIniSize(): void
    {
        // The drift this class cures: the Profiles copy merged these two arms.
        self::assertSame('File exceeds form upload limit', UploadErrorMessages::describe(UPLOAD_ERR_FORM_SIZE));
    }

    public function testPartialArm(): void
    {
        self::assertSame('File was only partially uploaded', UploadErrorMessages::describe(UPLOAD_ERR_PARTIAL));
    }

    public function testNoFileArm(): void
    {
        self::assertSame('No file was uploaded', UploadErrorMessages::describe(UPLOAD_ERR_NO_FILE));
    }

    public function testNoTmpDirArm(): void
    {
        self::assertSame('Missing temporary folder', UploadErrorMessages::describe(UPLOAD_ERR_NO_TMP_DIR));
    }

    public function testCantWriteArm(): void
    {
        self::assertSame('Failed to write file to disk', UploadErrorMessages::describe(UPLOAD_ERR_CANT_WRITE));
    }

    public function testExtensionArm(): void
    {
        self::assertSame('Upload blocked by extension', UploadErrorMessages::describe(UPLOAD_ERR_EXTENSION));
    }

    public function testUnknownCodeDegradesToGenericArm(): void
    {
        self::assertSame('Unknown upload error', UploadErrorMessages::describe(999));
    }

    public function testOkCodeIsNotDescribedAsAnErrorSurface(): void
    {
        // Defensive pin: UPLOAD_ERR_OK never reaches describe() in the callers
        // (both gate on !== UPLOAD_ERR_OK first). If someone passes it anyway,
        // it must not silently render as "No file was uploaded".
        self::assertSame('Unknown upload error', UploadErrorMessages::describe(UPLOAD_ERR_OK));
    }
}
