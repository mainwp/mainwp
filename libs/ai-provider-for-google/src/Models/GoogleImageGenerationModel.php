<?php

declare(strict_types=1);

namespace WordPress\GoogleAiProvider\Models;

use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Http\Util\ResponseUtil;
use WordPress\AiClient\Providers\Models\ImageGeneration\Contracts\ImageGenerationModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;
use WordPress\GoogleAiProvider\Authentication\GoogleApiKeyRequestAuthentication;
use WordPress\GoogleAiProvider\Provider\GoogleProvider;

/**
 * Class for a Google image generation model.
 *
 * This caters for Gemini models that can generate images as part of their multimodal output capabilities as well
 * as for more traditional image generation models such as Imagen.
 *
 * @since 1.0.0
 *
 * @phpstan-type PredictionData array{
 *     bytesBase64Encoded?: string,
 *     url?: string,
 *     mimeType?: string
 * }
 * @phpstan-type ResponseData array{
 *     id?: string,
 *     predictions?: list<PredictionData>
 * }
 * @phpstan-type RequestParams array{
 *     instances: list<array{prompt: string}>,
 *     parameters: array{sampleCount: int, outputOptions?: array{mimeType: string}},
 *     aspectRatio?: string,
 *     ...
 * }
 */
class GoogleImageGenerationModel extends AbstractApiBasedModel implements ImageGenerationModelInterface
{
    use WithAspectRatioTrait;

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
    public function generateImageResult(array $prompt): GenerativeAiResult
    {
        // TODO: Remove this workaround soon - here just for backward compatibility when
        // GoogleTextAndImageGenerationModel did not exist.
        if (str_starts_with($this->metadata()->getId(), 'gemini-')) {
            if (function_exists('_doing_it_wrong')) {
                _doing_it_wrong(
                    __METHOD__,
                    sprintf(
                        'Gemini image models should be used via %s going forward.',
                        GoogleTextAndImageGenerationModel::class
                    ),
                    '1.1.0'
                );
            }
            $multimodalOutputModel = new GoogleTextGenerationModel($this->metadata(), $this->providerMetadata());
            $multimodalOutputModel->setConfig($this->getConfig());
            $multimodalOutputModel->setHttpTransporter($this->getHttpTransporter());
            $multimodalOutputModel->setRequestAuthentication($this->getRequestAuthentication());
            $requestOptions = $this->getRequestOptions();
            if ($requestOptions) {
                $multimodalOutputModel->setRequestOptions($requestOptions);
            }
            return $multimodalOutputModel->generateTextResult($prompt);
        }

        $httpTransporter = $this->getHttpTransporter();

        $params = $this->prepareGenerateImageParams($prompt);

        $request = new Request(
            HttpMethodEnum::POST(),
            GoogleProvider::url("models/{$this->metadata()->getId()}:predict"),
            ['Content-Type' => 'application/json'],
            $params,
            $this->getRequestOptions()
        );

        // Add authentication credentials to the request.
        $request = $this->getRequestAuthentication()->authenticateRequest($request);

        // Send and process the request.
        $response = $httpTransporter->send($request);
        ResponseUtil::throwIfNotSuccessful($response);
        return $this->parseResponseToGenerativeAiResult(
            $response,
            $params['parameters']['outputOptions']['mimeType'] ?? 'image/png'
        );
    }

    /**
     * Prepares the given prompt and the model configuration into parameters for the API request.
     *
     * @since 1.0.0
     *
     * @param list<Message> $prompt The prompt to generate an image for. Either a single message or a list of messages
     *                              from a chat. However as of today, Google image generation endpoints only support a
     *                              single user message.
     * @return RequestParams The parameters for the API request.
     */
    protected function prepareGenerateImageParams(array $prompt): array
    {
        $config = $this->getConfig();

        $params = [
            'instances' => [
                ['prompt' => $this->preparePromptParam($prompt)],
            ],
            'parameters' => ['sampleCount' => 1],
        ];

        $candidateCount = $config->getCandidateCount();
        if ($candidateCount !== null) {
            $params['parameters']['sampleCount'] = $candidateCount;
        }

        if ($config->getOutputFileType() && $config->getOutputFileType()->isRemote()) {
            throw new InvalidArgumentException(
                'Unsupported output file type: Only inline is supported.'
            );
        }

        $outputMimeType = $config->getOutputMimeType();
        if ($outputMimeType !== null) {
            $params['parameters']['outputOptions'] = ['mimeType' => $outputMimeType];
        }

        $outputMediaOrientation = $config->getOutputMediaOrientation();
        $outputMediaAspectRatio = $config->getOutputMediaAspectRatio();
        if ($outputMediaOrientation !== null || $outputMediaAspectRatio !== null) {
            $params['aspectRatio'] = $this->prepareAspectRatioParam($outputMediaOrientation, $outputMediaAspectRatio);
        }

        return $this->applyCustomOptions($params, $config->getCustomOptions());
    }

    /**
     * Prepares the prompt parameter for the API request.
     *
     * @since 1.0.0
     *
     * @param list<Message> $messages The messages to prepare. However as of today, Google image generation endpoints
     *                                only support a single user message.
     * @return string The prepared prompt parameter.
     */
    protected function preparePromptParam(array $messages): string
    {
        if (count($messages) !== 1) {
            throw new InvalidArgumentException(
                'The API requires a single user message as prompt.'
            );
        }
        $message = $messages[0];
        if (!$message->getRole()->isUser()) {
            throw new InvalidArgumentException(
                'The API requires a user message as prompt.'
            );
        }

        $text = null;
        foreach ($message->getParts() as $part) {
            $text = $part->getText();
            if ($text !== null) {
                break;
            }
        }

        if ($text === null) {
            throw new InvalidArgumentException(
                'The API requires a single text message part as prompt.'
            );
        }

        return $text;
    }

    /**
     * Applies custom options to the given parameters array.
     *
     * This allows developers to pass options that may be more niche or not yet supported by the SDK.
     * Custom options with a `parameters.` prefix are added nested within the `parameters` key.
     *
     * @since 1.0.0
     *
     * @template T of array<string, mixed>
     * @param T $params The base parameters.
     * @param array<string, mixed> $customOptions The custom options to apply.
     * @return T The parameters with custom options applied.
     */
    private function applyCustomOptions(array $params, array $customOptions): array
    {
        foreach ($customOptions as $key => $value) {
            // Special case: Support custom values as part of `parameters`.
            if (str_starts_with($key, 'parameters.')) {
                $key = substr($key, strlen('parameters.'));
                if (!isset($params['parameters']) || !is_array($params['parameters'])) {
                    $params['parameters'] = [$key => $value];
                    continue;
                }
                if (isset($params['parameters'][$key])) {
                    throw new InvalidArgumentException(
                        sprintf(
                            'The custom parameters option "%s" conflicts with an existing parameter.',
                            $key
                        )
                    );
                }
                $params['parameters'][$key] = $value;
                continue;
            }

            if (isset($params[$key])) {
                throw new InvalidArgumentException(
                    sprintf(
                        'The custom option "%s" conflicts with an existing parameter.',
                        $key
                    )
                );
            }
            $params[$key] = $value;
        }

        /** @var T */
        return $params;
    }

    /**
     * Parses the response from the API endpoint to a generative AI result.
     *
     * @since 1.0.0
     *
     * @param Response $response The response from the API endpoint.
     * @param string   $expectedMimeType The expected MIME type the response is in.
     * @return GenerativeAiResult The parsed generative AI result.
     */
    protected function parseResponseToGenerativeAiResult(
        Response $response,
        string $expectedMimeType = 'image/png'
    ): GenerativeAiResult {
        /** @var ResponseData $responseData */
        $responseData = $response->getData();
        if (!isset($responseData['predictions']) || !$responseData['predictions']) {
            throw ResponseException::fromMissingData($this->providerMetadata()->getName(), 'predictions');
        }
        if (!is_array($responseData['predictions'])) {
            throw ResponseException::fromInvalidData(
                $this->providerMetadata()->getName(),
                'predictions',
                'The value must be an array.'
            );
        }

        $candidates = [];
        foreach ($responseData['predictions'] as $index => $predictionData) {
            if (!is_array($predictionData) || array_is_list($predictionData)) {
                throw ResponseException::fromInvalidData(
                    $this->providerMetadata()->getName(),
                    "predictions[{$index}]",
                    'The value must be an associative array.'
                );
            }

            $candidates[] = $this->parseResponsePredictionToCandidate($predictionData, $index, $expectedMimeType);
        }

        $id = isset($responseData['id']) && is_string($responseData['id']) ? $responseData['id'] : '';

        // Use any other data from the response as provider-specific response metadata.
        $providerMetadata = $responseData;
        unset($providerMetadata['id'], $providerMetadata['predictions']);

        return new GenerativeAiResult(
            $id,
            $candidates,
            new TokenUsage(0, 0, 0),
            $this->providerMetadata(),
            $this->metadata(),
            $providerMetadata
        );
    }

    /**
     * Parses a single prediction from the API response into a Candidate object.
     *
     * @since 1.0.0
     *
     * @param PredictionData $predictionData The prediction data from the API response.
     * @param int $index The index of the prediction in the predictions array.
     * @param string   $expectedMimeType The expected MIME type the response is in.
     * @return Candidate The parsed candidate.
     * @throws RuntimeException If the prediction data is invalid.
     */
    protected function parseResponsePredictionToCandidate(
        array $predictionData,
        int $index,
        string $expectedMimeType = 'image/png'
    ): Candidate {
        $mimeType = isset($predictionData['mimeType']) ? $predictionData['mimeType'] : $expectedMimeType;

        if (isset($predictionData['url']) && is_string($predictionData['url'])) {
            $imageFile = new File($predictionData['url'], $mimeType);
        } elseif (isset($predictionData['bytesBase64Encoded']) && is_string($predictionData['bytesBase64Encoded'])) {
            $imageFile = new File('data:' . $mimeType . ';base64,' . $predictionData['bytesBase64Encoded'], $mimeType);
        } else {
            throw ResponseException::fromInvalidData(
                $this->providerMetadata()->getName(),
                "predictions[{$index}]",
                'The value must contain either a url or bytesBase64Encoded key with a string value.'
            );
        }

        $parts = [new MessagePart($imageFile)];

        $message = new Message(MessageRoleEnum::model(), $parts);

        return new Candidate($message, FinishReasonEnum::stop());
    }
}
