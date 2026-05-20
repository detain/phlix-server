<?php

declare(strict_types=1);

namespace Phlix\Media\Extras;

/**
 * Extra represents a non-trailer extra for a media item.
 *
 * Supported extra types: featurette, behind_the_scenes, interview, clip,
 * deleted_scene, trailer.
 *
 * @since 0.14.0
 */
final readonly class Extra
{
    public const TYPE_FEATURETTE = 'featurette';
    public const TYPE_BEHIND_THE_SCENES = 'behind_the_scenes';
    public const TYPE_INTERVIEW = 'interview';
    public const TYPE_CLIP = 'clip';
    public const TYPE_DELETED_SCENE = 'deleted_scene';
    public const TYPE_TRAILER = 'trailer';

    /**
     * Valid extra type values.
     *
     * @var array<string>
     */
    public const VALID_TYPES = [
        self::TYPE_FEATURETTE,
        self::TYPE_BEHIND_THE_SCENES,
        self::TYPE_INTERVIEW,
        self::TYPE_CLIP,
        self::TYPE_DELETED_SCENE,
        self::TYPE_TRAILER,
    ];

    /**
     * @param string $id Unique identifier for the extra
     * @param string $mediaItemId Associated media item ID
     * @param string $title Display title
     * @param string $type Extra type (featurette|behind_the_scenes|interview|clip|deleted_scene|trailer)
     * @param string $source Source of the extra: 'local' or 'tmdb'
     * @param string $url Absolute URL (local file path or TMDB URL)
     * @param int $duration Duration in seconds; 0 if unknown
     * @param int $quality Video quality (480/720/1080/2160); 0 if unknown
     * @param bool $isLocal True for local extras
     * @param string $filePath Empty string for TMDB sources
     */
    public function __construct(
        public string $id,
        public string $mediaItemId,
        public string $title,
        public string $type,
        public string $source,
        public string $url,
        public int $duration,
        public int $quality,
        public bool $isLocal,
        public string $filePath,
    ) {
    }

    /**
     * Convert to array representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'media_item_id' => $this->mediaItemId,
            'title' => $this->title,
            'type' => $this->type,
            'source' => $this->source,
            'url' => $this->url,
            'duration' => $this->duration,
            'quality' => $this->quality,
            'is_local' => $this->isLocal,
            'file_path' => $this->filePath,
        ];
    }
}
