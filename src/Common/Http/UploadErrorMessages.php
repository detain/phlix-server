<?php

/**
 * Phlix media server component: Common.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Common\Http;

/**
 * The ONE human-readable wording for PHP's UPLOAD_ERR_* constants.
 *
 * The two upload endpoints (UserAvatarController and ProfilesController)
 * each carried a private `match` table, and they drifted: the same failure
 * read "File exceeds size limit" on one surface and "File exceeds server
 * upload limit" on the other, while one docblock claimed the other file as
 * its mirror (server minors M-SERVER-08, batch W-min103). The UserAvatar
 * wording is canonical here: it distinguishes the ini limit from the form
 * limit, and it is the wording already pinned by UserAvatarControllerTest.
 *
 * Wire copy only — no machine codes are introduced; callers keep prefixing
 * ("Upload error: …") as they choose. Pure function, no state.
 */
final class UploadErrorMessages
{
    /**
     * Describe a PHP upload error constant in user-facing English.
     *
     * @param int $errorCode One of the UPLOAD_ERR_* constants (or any int —
     *                       unknown values degrade to the generic arm).
     */
    public static function describe(int $errorCode): string
    {
        return match ($errorCode) {
            UPLOAD_ERR_INI_SIZE => 'File exceeds server upload limit',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds form upload limit',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'Upload blocked by extension',
            default => 'Unknown upload error',
        };
    }
}
