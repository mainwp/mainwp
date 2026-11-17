<?php

declare(strict_types=1);

namespace WordPress\GoogleAiProvider\Metadata;

use WordPress\AiClient\Files\Enums\FileTypeEnum;
use WordPress\AiClient\Files\Enums\MediaOrientationEnum;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleModelMetadataDirectory;
use WordPress\GoogleAiProvider\Authentication\GoogleApiKeyRequestAuthentication;
use WordPress\GoogleAiProvider\Provider\GoogleProvider;

/**
 * Class for the Google model metadata directory.
 *
 * @since 1.0.0
 *
 * @phpstan-type ModelsResponseData array{
 *     models: list<array{
 *         baseModelId?: string,
 *         name: string,
 *         supportedGenerationMethods?: list<string>,
 *         displayName?: string
 *     }>
 * }
 */
class GoogleModelMetadataDirectory extends AbstractOpenAiCompatibleModelMetadataDirectory
{
    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     */
    public function getRequestAuthentication(): RequestAuthenticationInterface
    {
        /*
         * Since we're calling the Google API here, we need to use the Google specific
         * API key authentication class.
         */
        $requestAuthentication = parent::getRequestAuthentication();
        if (!$requestAuthentication instanceof ApiKeyRequestAuthentication) {
            return $requestAuthentication;
        }
        return new GoogleApiKeyRequestAuthentication($requestAuthentication->getApiKey());
    }

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     */
    protected function createRequest(HttpMethodEnum $method, string $path, array $headers = [], $data = null): Request
    {
        /*
         * We don't call Google's OpenAI compatible models endpoint here because it provides fewer details about the
         * models than the primary models endpoint.
         * For Google's models endpoint, set pageSize=1000 which is the maximum page size.
         * This allows us to retrieve all models in one go.
         */
        if ($path === 'models' && $data === null) {
            $data = ['pageSize' => 1000];
        }
        return new Request(
            $method,
            GoogleProvider::url($path),
            $headers,
            $data
        );
    }

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     */
    protected function parseResponseToModelMetadataList(Response $response): array
    {
        /** @var ModelsResponseData $responseData */
        $responseData = $response->getData();
        if (!isset($responseData['models']) || !$responseData['models']) {
            throw ResponseException::fromMissingData('Google', 'models');
        }

        $allModalityCombinationsWithText = [
            [ModalityEnum::text()],
            [ModalityEnum::text(), ModalityEnum::image()],
            [ModalityEnum::text(), ModalityEnum::audio()],
            [ModalityEnum::text(), ModalityEnum::document()],
            [ModalityEnum::text(), ModalityEnum::image(), ModalityEnum::audio()],
            [ModalityEnum::text(), ModalityEnum::image(), ModalityEnum::document()],
            [ModalityEnum::text(), ModalityEnum::audio(), ModalityEnum::document()],
            [ModalityEnum::text(), ModalityEnum::image(), ModalityEnum::audio(), ModalityEnum::document()],
        ];

        $geminiImageAspectRatios = ['1:1', '16:9', '4:3', '3:2', '5:4', '9:16', '3:4', '2:3', '4:5', '21:9'];
        $gemini31ImageAspectRatios = array_merge(
            $geminiImageAspectRatios,
            ['4:1', '8:1', '1:4', '1:8']
        );

        $geminiCapabilities = [
            CapabilityEnum::textGeneration(),
            CapabilityEnum::chatHistory(),
        ];
        $geminiMultimodalImageOutputCapabilities = [
            CapabilityEnum::textGeneration(),
            CapabilityEnum::imageGeneration(),
            CapabilityEnum::chatHistory(),
        ];
        $geminiBaseOptions = [
            new SupportedOption(OptionEnum::systemInstruction()),
            new SupportedOption(OptionEnum::candidateCount()),
            new SupportedOption(OptionEnum::maxTokens()),
            new SupportedOption(OptionEnum::temperature()),
            new SupportedOption(OptionEnum::topP()),
            new SupportedOption(OptionEnum::topK()),
            new SupportedOption(OptionEnum::stopSequences()),
            new SupportedOption(OptionEnum::presencePenalty()),
            new SupportedOption(OptionEnum::frequencyPenalty()),
            new SupportedOption(OptionEnum::logprobs()),
            new SupportedOption(OptionEnum::topLogprobs()),
            new SupportedOption(OptionEnum::outputMimeType(), ['text/plain', 'application/json']),
            new SupportedOption(OptionEnum::outputSchema()),
            new SupportedOption(OptionEnum::functionDeclarations()),
            new SupportedOption(OptionEnum::customOptions()),
        ];
        $geminiOptions = array_merge($geminiBaseOptions, [
            new SupportedOption(
                OptionEnum::inputModalities(),
                $allModalityCombinationsWithText
            ),
            new SupportedOption(OptionEnum::outputModalities(), [[ModalityEnum::text()]]),
            new SupportedOption(OptionEnum::webSearch()),
        ]);
        $geminiMultimodalImageOutputOptions = array_merge($geminiBaseOptions, [
            new SupportedOption(OptionEnum::outputFileType(), [FileTypeEnum::inline()]),
            new SupportedOption(OptionEnum::outputMediaOrientation(), [
                MediaOrientationEnum::square(),
                MediaOrientationEnum::landscape(),
                MediaOrientationEnum::portrait(),
            ]),
            new SupportedOption(OptionEnum::outputMediaAspectRatio(), $geminiImageAspectRatios),
            new SupportedOption(
                OptionEnum::inputModalities(),
                $allModalityCombinationsWithText
            ),
            new SupportedOption(
                OptionEnum::outputModalities(),
                [
                    [ModalityEnum::text()],
                    [ModalityEnum::image()],
                    [ModalityEnum::text(), ModalityEnum::image()],
                ]
            ),
        ]);
        $imagenCapabilities = [
            CapabilityEnum::imageGeneration(),
        ];
        $imagenOptions = [
            new SupportedOption(OptionEnum::inputModalities(), [[ModalityEnum::text()]]),
            new SupportedOption(OptionEnum::outputModalities(), [[ModalityEnum::image()]]),
            new SupportedOption(OptionEnum::candidateCount()),
            new SupportedOption(OptionEnum::outputMimeType(), ['image/png', 'image/jpeg', 'image/webp']),
            new SupportedOption(OptionEnum::outputFileType(), [FileTypeEnum::inline()]),
            new SupportedOption(OptionEnum::outputMediaOrientation(), [
                MediaOrientationEnum::square(),
                MediaOrientationEnum::landscape(),
                MediaOrientationEnum::portrait(),
            ]),
            new SupportedOption(OptionEnum::outputMediaAspectRatio(), $geminiImageAspectRatios),
            new SupportedOption(OptionEnum::customOptions()),
        ];

        $modelsData = (array) $responseData['models'];

        $models = array_values(
            array_map(
                static function (array $modelData) use (
                    $geminiCapabilities,
                    $geminiMultimodalImageOutputCapabilities,
                    $geminiOptions,
                    $geminiMultimodalImageOutputOptions,
                    $imagenCapabilities,
                    $imagenOptions,
                    $gemini31ImageAspectRatios
                ): ModelMetadata {
                    $modelId = $modelData['baseModelId'] ?? $modelData['name'];
                    if (str_starts_with($modelId, 'models/')) {
                        $modelId = substr($modelId, 7);
                    }
                    if (
                        isset($modelData['supportedGenerationMethods']) &&
                        is_array($modelData['supportedGenerationMethods']) &&
                        in_array('generateContent', $modelData['supportedGenerationMethods'], true)
                    ) {
                        $modelCaps = $geminiCapabilities;
                        if (
                            // Multimodal output models for image generation.
                            str_ends_with($modelId, '-image') ||
                            str_ends_with($modelId, '-image-preview') ||
                            str_ends_with($modelId, '-image-generation') ||
                            str_starts_with($modelId, 'gemini-2.0-flash-exp')
                        ) {
                            $modelCaps = $geminiMultimodalImageOutputCapabilities;
                            $modelOptions = $geminiMultimodalImageOutputOptions;
                            if (str_starts_with($modelId, 'gemini-3.1')) {
                                $modelOptions = array_map(
                                    static function (SupportedOption $option) use ($gemini31ImageAspectRatios) {
                                        if ($option->getName()->isOutputMediaAspectRatio()) {
                                            return new SupportedOption(
                                                $option->getName(),
                                                $gemini31ImageAspectRatios
                                            );
                                        }
                                        return $option;
                                    },
                                    $modelOptions
                                );
                            }
                        } else {
                            $modelOptions = $geminiOptions;
                        }
                    } elseif (
                        isset($modelData['supportedGenerationMethods']) &&
                        is_array($modelData['supportedGenerationMethods']) &&
                        in_array('predict', $modelData['supportedGenerationMethods'], true)
                    ) {
                        $modelCaps = $imagenCapabilities;
                        $modelOptions = $imagenOptions;
                    } else {
                        $modelCaps = [];
                        $modelOptions = [];
                    }

                    $modelName = $modelData['displayName'] ?? $modelId;

                    return new ModelMetadata(
                        $modelId,
                        $modelName,
                        $modelCaps,
                        $modelOptions
                    );
                },
                $modelsData
            )
        );

        usort($models, [$this, 'modelSortCallback']);

        return $models;
    }

    /**
     * Callback function for sorting models by ID, to be used with `usort()`.
     *
     * This method expresses preferences for certain models or model families within the provider by putting them
     * earlier in the sorted list. The objective is not to be opinionated about which models are better, but to ensure
     * that more commonly used, more recent, or flagship models are presented first to users.
     *
     * @since 1.0.0
     *
     * @param ModelMetadata $a First model.
     * @param ModelMetadata $b Second model.
     * @return int Comparison result.
     */
    protected function modelSortCallback(ModelMetadata $a, ModelMetadata $b): int
    {
        $aId = $a->getId();
        $bId = $b->getId();

        // Prefer non-experimental models over experimental models.
        if (str_contains($aId, '-exp') && !str_contains($bId, '-exp')) {
            return 1;
        }
        if (str_contains($bId, '-exp') && !str_contains($aId, '-exp')) {
            return -1;
        }

        // Prefer non-preview models over preview models.
        if (str_contains($aId, '-preview') && !str_contains($bId, '-preview')) {
            return 1;
        }
        if (str_contains($bId, '-preview') && !str_contains($aId, '-preview')) {
            return -1;
        }

        // Prefer Gemini models over non-Gemini models.
        if (str_starts_with($aId, 'gemini-') && !str_starts_with($bId, 'gemini-')) {
            return -1;
        }
        if (str_starts_with($bId, 'gemini-') && !str_starts_with($aId, 'gemini-')) {
            return 1;
        }

        // Prefer Gemini models with version numbers (e.g. 'gemini-2.5', 'gemini-2.0') over those without.
        $aMatch = preg_match('/^gemini-([0-9.]+)(-[a-z0-9-]+)$/', $aId, $aMatches);
        $bMatch = preg_match('/^gemini-([0-9.]+)(-[a-z0-9-]+)$/', $bId, $bMatches);
        if ($aMatch && !$bMatch) {
            return -1;
        }
        if ($bMatch && !$aMatch) {
            return 1;
        }
        if ($aMatch && $bMatch) {
            // Prefer later model versions.
            $aVersion = $aMatches[1];
            $bVersion = $bMatches[1];
            if (version_compare($aVersion, $bVersion, '>')) {
                return -1;
            }
            if (version_compare($bVersion, $aVersion, '>')) {
                return 1;
            }

            // Prefer '-pro' models over other suffixes.
            if ($aMatches[2] === '-pro' && $bMatches[2] !== '-pro') {
                return -1;
            }
            if ($bMatches[2] === '-pro' && $aMatches[2] !== '-pro') {
                return 1;
            }

            // Prefer '-flash' models over other suffixes.
            if ($aMatches[2] === '-flash' && $bMatches[2] !== '-flash') {
                return -1;
            }
            if ($bMatches[2] === '-flash' && $aMatches[2] !== '-flash') {
                return 1;
            }
        }

        // Fallback: Sort alphabetically.
        return strcmp($a->getId(), $b->getId());
    }
}
