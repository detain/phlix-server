<?php

/**
 * Phlix media server component: Controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers;

/**
 * Shared user-visible copy for the HTTP controllers (SH-041, review
 * server__copywriter#13: "copy duplicated as literals is copy that will
 * drift — one named constant per user-visible outcome").
 *
 * Two families join here, both previously byte-duplicated across files:
 *  - the segment-failure tokens, emitted verbatim by the HLS and DASH
 *    segment rails (six arms after the playback-lag era added the
 *    build-refused pair; both rails must keep answering identically,
 *    and {@see \Phlix\Tests\Unit\Server\Http\Controllers\ControllerCopySweepTest}
 *    pins the pair);
 *  - the hub-enrolment sentence, carried by three admin surfaces
 *    (HealthController, AccountLinkController, HubTokenController).
 *
 * House voice, stated at last so lanes stop re-deciding it (SH-041,
 * server__copywriter#12's "write it down"): second person, contractions
 * welcome for end-user copy, formal register for operator copy; a
 * `message` is a sentence and ends with a period; an `error` label is
 * terse and stays bare; prose spells `ID` and `characters` in full, and
 * names fields by human label first (pairing the wire key only where an
 * integrator needs it verbatim).
 *
 * @package Phlix\Server\Http\Controllers
 */
final class ControllerCopy
{
    /**
     * Transient overload on the segment rails (503 + Retry-After: 1):
     * in-flight encodes finish first. The lowercase token form is part
     * of what both players already logged against — only its home moved.
     */
    public const SEGMENT_BUSY = 'segment busy';

    /** Segment-cache filesystem low on space (503 + Retry-After: 3). */
    public const SEGMENT_CACHE_FULL = 'segment cache full';

    /**
     * Persisted segment_params refused to build an ffmpeg command
     * (playback-lag era, INV-A P3 mirror; 503 + Retry-After: 2).
     */
    public const SEGMENT_BUILD_REFUSED = 'segment build refused';

    /** Segment row resolved to nothing on disk (404 arm of both rails). */
    public const SEGMENT_UNAVAILABLE = 'segment unavailable';

    /**
     * The hub-enrolment refusal shared by every admin surface that needs
     * a live hub JWT validator, enroller, or token validator.
     */
    public const HUB_NOT_ENROLLED = "This server isn't enrolled with a Phlix Hub yet. Go to Admin > Hub to enroll it.";

    /**
     * Stream-limit 429 body prose, shared by StreamLimitMiddleware and the
     * pre-router fast path (SRV-58: two byte-identical literals, one home).
     * The `error` label `StreamLimitExceeded` is deliberately NOT lowercased:
     * it is a legacy consumer-pinned token (phlix-ui playerErrors, mobile).
     */
    public const STREAM_LIMIT_MESSAGE = 'This member profile is already streaming on the maximum number of devices.';

    /** Single home; every constant above is referenced, never instantiated. */
    private function __construct()
    {
    }
}
