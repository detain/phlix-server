<?php

declare(strict_types=1);

return [
    'enabled' => true,
    'manifest_refresh_seconds' => 30,
    'min_buffer_time' => 'PT2S',
    'min_buffer_time_live' => 'PT10S',
    'time_shift_buffer_depth' => 'PT30M',
    'default_codecs' => [
        'video' => 'avc1.64001f',   // H.264 High Profile Level 3.1
        'audio' => 'mp4a.40.2',    // AAC-LC
    ],
];
