<?php
/**
 * Model choices belong to the connected account.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- AI Client interfaces require these method names.

/** Avoids caching one user's model list for another request owner. */
class MainWP_Assistant_ChatGPT_Directory implements ModelMetadataDirectoryInterface {
    /**
     * Request owner.
     *
     * @var int
     */
    private $user_id;

    /**
     * Keeps account ownership explicit.
     *
     * @param int $user_id Owning user.
     */
    public function __construct( $user_id ) {
        $this->user_id = (int) $user_id;
    }

    /** {@inheritDoc} */
    public function listModelMetadata(): array {
        $models = MainWP_Assistant_ChatGPT_Plan::list_models( $this->user_id );
        if ( is_wp_error( $models ) ) {
            $data = (array) $models->get_error_data();
            MainWP_Assistant_ChatGPT_Model::fail( $this->user_id, $models->get_error_code(), (int) ( $data['http_status'] ?? 503 ), $data['request_id'] ?? '' );
        }
        return array_map(
            static function ( $model ) {
                return new ModelMetadata( $model['slug'], $model['display_name'], array( CapabilityEnum::textGeneration(), CapabilityEnum::chatHistory() ), array() );
            },
            $models
        );
    }

    /**
     * {@inheritDoc}
     *
     * @param string $modelId Model identifier.
     */
    public function hasModelMetadata( string $modelId ): bool {
        foreach ( $this->listModelMetadata() as $model ) {
            if ( $modelId === $model->getId() ) {
                return true;
            }
        }
        return false;
    }

    /**
     * {@inheritDoc}
     *
     * @param string $modelId Model identifier.
     */
    public function getModelMetadata( string $modelId ): ModelMetadata {
        $models = $this->listModelMetadata();
        foreach ( $models as $model ) {
            if ( $modelId === $model->getId() ) {
                return $model;
            }
        }
        if ( empty( $models ) ) {
            MainWP_Assistant_ChatGPT_Model::fail( $this->user_id, 'models_unavailable', 503 );
        }
        return $models[0];
    }
}
