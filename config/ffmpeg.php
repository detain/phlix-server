<?php

return [
    'ffmpeg_path' => '/usr/bin/ffmpeg',
    'ffprobe_path' => '/usr/bin/ffprobe',
    'transcode_dir' => '/var/transcodes',
    'segment_dir' => '/var/segments',
    'max_concurrent_transcodes' => 4,
    'transcode_timeout' => 7200,
    'hwaccel' => [
        'enabled' => true,
        'prefer_hardware' => true,
        'vendor_priority' => [
            'nvenc' => 0,
            'vaapi' => 1,
            'qsv' => 2,
            'videotoolbox' => 3,
            'amf' => 4,
            'v4l2' => 5,
        ],
    ],
    'hwaccel_profiles' => require __DIR__ . '/hwaccel_profiles.php',
];
