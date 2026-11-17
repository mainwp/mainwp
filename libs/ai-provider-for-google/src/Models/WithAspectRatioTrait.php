<?php

declare(strict_types=1);

namespace WordPress\GoogleAiProvider\Models;

use WordPress\AiClient\Files\Enums\MediaOrientationEnum;

/**
 * Trait for handling aspect ratio preparation.
 *
 * @since 1.1.0
 */
trait WithAspectRatioTrait
{
    /**
     * Prepares the aspect ratio parameter for the API request.
     *
     * @since 1.1.0
     *
     * @param MediaOrientationEnum|null $orientation The desired media orientation.
     * @param string|null $aspectRatio The desired media aspect ratio.
     * @return string The prepared aspect ratio parameter.
     */
    protected function prepareAspectRatioParam(?MediaOrientationEnum $orientation, ?string $aspectRatio): string
    {
        // Use aspect ratio if set, as it is more specific.
        if ($aspectRatio !== null) {
            return $aspectRatio;
        }

        // This should always have a value, as the method is only called if at least one or the other is set.
        if ($orientation !== null) {
            if ($orientation->isLandscape()) {
                return '16:9';
            }
            if ($orientation->isPortrait()) {
                return '9:16';
            }
        }
        return '1:1';
    }
}
