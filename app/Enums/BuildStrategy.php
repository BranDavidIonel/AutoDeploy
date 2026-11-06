<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** Where the front-end assets (npm run build) of a release come from. */
enum BuildStrategy: string
{
    use HasOptions;

    /** Build on this computer (the one running AutoDeploy), then upload the result. */
    case Local = 'local';

    /** Use a build archive uploaded earlier (a zip of public/build saved on your PC). */
    case Upload = 'upload';

    /** Run the build command on the server (needs enough RAM there). */
    case Server = 'server';

    /** The project has no front-end build. */
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Local => __('Build locally'),
            self::Upload => __('Upload a saved build'),
            self::Server => __('Build on the server'),
            self::None => __('No build'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Local => __('AutoDeploy runs the build command in your local project folder and uploads the result. No RAM needed on the server.'),
            self::Upload => __('Pick a build archive you uploaded before (a .zip of public/build). Nothing is built anywhere.'),
            self::Server => __('The server runs npm ci && npm run build in the new release. Needs enough RAM (2 GB+ recommended).'),
            self::None => __('Skip the front-end step, e.g. for an API without Vite assets.'),
        };
    }

    /** Builds that end with an archive being uploaded to the server. */
    public function uploadsArchive(): bool
    {
        return $this === self::Local || $this === self::Upload;
    }

    /**
     * Options with descriptions, for the strategy cards.
     *
     * @return list<array{value: string, label: string, description: string}>
     */
    public static function cards(): array
    {
        return array_map(
            fn (self $case) => ['value' => $case->value, 'label' => $case->label(), 'description' => $case->description()],
            self::cases(),
        );
    }
}
