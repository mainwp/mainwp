<?php

declare(strict_types=1);

namespace WordPress\GoogleAiProvider\Models;

use WordPress\AiClient\Providers\ApiBasedImplementation\Contracts\ApiBasedModelInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\Contracts\WithHttpTransporterInterface;
use WordPress\AiClient\Providers\Http\Contracts\WithRequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\ImageGeneration\Contracts\ImageGenerationModelInterface;
use WordPress\AiClient\Providers\Models\TextGeneration\Contracts\TextGenerationModelInterface;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;

/**
 * Class for a Google model that supports both text and image generation.
 *
 * This is a pure composition wrapper around GoogleTextGenerationModel. Both text and image generation
 * delegate to the language-model endpoint — the framework sets the image output modality on the config
 * before calling generateImageResult().
 *
 * @since 1.1.0
 */
class GoogleTextAndImageGenerationModel implements
    ApiBasedModelInterface,
    WithHttpTransporterInterface,
    WithRequestAuthenticationInterface,
    TextGenerationModelInterface,
    ImageGenerationModelInterface
{
    /**
     * @var GoogleTextGenerationModel The inner text generation model.
     */
    private GoogleTextGenerationModel $model;

    /**
     * Constructor.
     *
     * @since 1.1.0
     *
     * @param ModelMetadata    $metadata         The metadata for the model.
     * @param ProviderMetadata $providerMetadata The metadata for the model's provider.
     */
    public function __construct(ModelMetadata $metadata, ProviderMetadata $providerMetadata)
    {
        $this->model = new GoogleTextGenerationModel($metadata, $providerMetadata);
    }

    /**
     * {@inheritDoc}
     *
     * @since 1.1.0
     */
    public function metadata(): ModelMetadata
    {
        return $this->model->metadata();
    }

    /**
     * {@inheritDoc}
     *
     * @since 1.1.0
     */
    public function providerMetadata(): ProviderMetadata
    {
        return $this->model->providerMetadata();
    }

    /**
     * {@inheritDoc}
     *
     * @since 1.1.0
     */
    public function setConfig(ModelConfig $config): void
    {
        $this->model->setConfig($config);
    }

    /**
     * {@inheritDoc}
     *
     * @since 1.1.0
     */
    public function getConfig(): ModelConfig
    {
        return $this->model->getConfig();
    }

    /**
     * {@inheritDoc}
     *
     * @since 1.1.0
     */
    public function setRequestOptions(RequestOptions $requestOptions): void
    {
        $this->model->setRequestOptions($requestOptions);
    }

    /**
     * {@inheritDoc}
     *
     * @since 1.1.0
     */
    public function getRequestOptions(): ?RequestOptions
    {
        return $this->model->getRequestOptions();
    }

    /**
     * {@inheritDoc}
     *
     * @since 1.1.0
     */
    public function setHttpTransporter(HttpTransporterInterface $httpTransporter): void
    {
        $this->model->setHttpTransporter($httpTransporter);
    }

    /**
     * {@inheritDoc}
     *
     * @since 1.1.0
     */
    public function getHttpTransporter(): HttpTransporterInterface
    {
        return $this->model->getHttpTransporter();
    }

    /**
     * {@inheritDoc}
     *
     * @since 1.1.0
     */
    public function setRequestAuthentication(RequestAuthenticationInterface $requestAuthentication): void
    {
        $this->model->setRequestAuthentication($requestAuthentication);
    }

    /**
     * {@inheritDoc}
     *
     * @since 1.1.0
     */
    public function getRequestAuthentication(): RequestAuthenticationInterface
    {
        return $this->model->getRequestAuthentication();
    }

    /**
     * {@inheritDoc}
     *
     * @since 1.1.0
     */
    public function generateTextResult(array $prompt): GenerativeAiResult
    {
        return $this->model->generateTextResult($prompt);
    }

    /**
     * {@inheritDoc}
     *
     * @since 1.1.0
     */
    public function generateImageResult(array $prompt): GenerativeAiResult
    {
        return $this->model->generateTextResult($prompt);
    }
}
