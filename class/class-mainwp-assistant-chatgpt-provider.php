<?php
/**
 * User-owned ChatGPT inference.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

use WordPress\AiClient\Providers\Contracts\ProviderInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- AI Client interfaces require these method names.

/** Keeps personal credentials outside site connectors. */
class MainWP_Assistant_ChatGPT_Provider implements ProviderInterface {
    const ID = 'mainwp-chatgpt-plan';

    /** {@inheritDoc} */
    public static function metadata(): ProviderMetadata {
        return new ProviderMetadata( self::ID, 'ChatGPT plan', ProviderTypeEnum::cloud() );
    }

    /** {@inheritDoc} */
    public static function availability(): ProviderAvailabilityInterface {
        return new MainWP_Assistant_ChatGPT_Availability();
    }

    /** {@inheritDoc} */
    public static function modelMetadataDirectory(): ModelMetadataDirectoryInterface {
        return new MainWP_Assistant_ChatGPT_Directory( get_current_user_id() );
    }

    /**
     * {@inheritDoc}
     *
     * @param string           $modelId Model identifier.
     * @param ModelConfig|null $modelConfig Prompt configuration.
     */
    public static function model( string $modelId, ?ModelConfig $modelConfig = null ): ModelInterface {
        $model = self::for_user( get_current_user_id(), $modelId );
        if ( null !== $modelConfig ) {
            $model->setConfig( $modelConfig );
        }
        return $model;
    }

    /**
     * Binds inference to the request owner before any HTTP call.
     *
     * @param int    $user_id Request owner.
     * @param string $model_id Selected model, or an obsolete slug.
     * @return MainWP_Assistant_ChatGPT_Model
     */
    public static function for_user( $user_id, $model_id ) {
        $directory = new MainWP_Assistant_ChatGPT_Directory( $user_id );
        return new MainWP_Assistant_ChatGPT_Model( $user_id, $directory->getModelMetadata( $model_id ) );
    }
}
