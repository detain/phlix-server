<?php

declare(strict_types=1);

namespace Phlex\LiveTv;

/**
 * Registers post-record hooks for Live TV recording lifecycle.
 *
 * This class wires post-processing handlers (such as ComskipPostProcessor)
 * into the Recorder's onComplete callback system.
 *
 * @since 0.12.0
 */
final class RecordingHooks
{
    /**
     * Hook name fired after a recording completes successfully.
     *
     * @since 0.12.0
     */
    public const HOOK_POST_RECORD = 'live_tv.recording.completed';

    /**
     * Register post-record hooks with a recorder instance.
     *
     * This method wires the provided ComskipPostProcessor to be called
     * automatically after each recording completes.
     *
     * @param Recorder $recorder The recorder to attach hooks to
     * @param ComskipPostProcessor $processor The post-processor to run after recordings
     *
     * @return void
     *
     * @since 0.12.0
     */
    public static function register(Recorder $recorder, ComskipPostProcessor $processor): void
    {
        $recorder->onComplete(function (string $mediaItemId, string $recordingPath) use ($processor): void {
            $processor->processRecording($mediaItemId, $recordingPath);
        });
    }
}
