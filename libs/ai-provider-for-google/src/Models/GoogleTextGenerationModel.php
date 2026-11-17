<?php

declare(strict_types=1);

namespace WordPress\GoogleAiProvider\Models;

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessagePartChannelEnum;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Http\Util\ResponseUtil;
use WordPress\AiClient\Providers\Models\TextGeneration\Contracts\TextGenerationModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;
use WordPress\GoogleAiProvider\Authentication\GoogleApiKeyRequestAuthentication;
use WordPress\GoogleAiProvider\Provider\GoogleProvider;

/**
 * Class for a Google text generation model.
 *
 * @since 1.0.0
 *
 * @phpstan-type MessageData array{
 *     role?: string,
 *     parts?: list<array<string, mixed>>
 * }
 * @phpstan-type CandidateData array{
 *     content?: MessageData,
 *     finishReason?: string
 * }
 * @phpstan-type UsageData array{
 *     promptTokenCount?: int,
 *     candidatesTokenCount?: int,
 *     thoughtsTokenCount?: int
 * }
 * @phpstan-type ResponseData array{
 *     id?: string,
 *     candidates?: list<CandidateData>,
 *     usageMetadata?: UsageData
 * }
 */
class GoogleTextGenerationModel extends AbstractApiBasedModel implements TextGenerationModelInterface
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
    final public function generateTextResult(array $prompt): GenerativeAiResult
    {
        $httpTransporter = $this->getHttpTransporter();

        $params = $this->prepareGenerateTextParams($prompt);

        $request = new Request(
            HttpMethodEnum::POST(),
            GoogleProvider::url("models/{$this->metadata()->getId()}:generateContent"),
            ['Content-Type' => 'application/json'],
            $params,
            $this->getRequestOptions()
        );

        // Add authentication credentials to the request.
        $request = $this->getRequestAuthentication()->authenticateRequest($request);

        // Send and process the request.
        $response = $httpTransporter->send($request);
        ResponseUtil::throwIfNotSuccessful($response);
        return $this->parseResponseToGenerativeAiResult($response);
    }

    /**
     * Prepares the given prompt and the model configuration into parameters for the API request.
     *
     * @since 1.0.0
     *
     * @param list<Message> $prompt The prompt to generate text for. Either a single message or a list of messages
     *                              from a chat.
     * @return array<string, mixed> The parameters for the API request.
     */
    protected function prepareGenerateTextParams(array $prompt): array
    {
        $config = $this->getConfig();

        $params = [
            'contents' => $this->prepareContentsParam($prompt),
        ];

        $systemInstruction = $config->getSystemInstruction();
        if ($systemInstruction) {
            $params['systemInstruction'] = $this->prepareSystemInstructionParam($systemInstruction);
        }

        $generationConfig = [];

        $outputModalities = $config->getOutputModalities();
        if (is_array($outputModalities)) {
            $generationConfig['responseModalities'] = $this->prepareResponseModalitiesParam($outputModalities);
            if (in_array('Image', $generationConfig['responseModalities'], true)) {
                $outputMediaOrientation = $config->getOutputMediaOrientation();
                $outputMediaAspectRatio = $config->getOutputMediaAspectRatio();
                if ($outputMediaOrientation !== null || $outputMediaAspectRatio !== null) {
                    $generationConfig['imageConfig'] = [
                        'aspectRatio' => $this->prepareAspectRatioParam(
                            $outputMediaOrientation,
                            $outputMediaAspectRatio
                        ),
                    ];
                }
            }
        }

        $candidateCount = $config->getCandidateCount();
        if ($candidateCount !== null) {
            $generationConfig['candidateCount'] = $candidateCount;
        }

        $maxTokens = $config->getMaxTokens();
        if ($maxTokens !== null) {
            $generationConfig['maxOutputTokens'] = $maxTokens;
        }

        $temperature = $config->getTemperature();
        if ($temperature !== null) {
            $generationConfig['temperature'] = $temperature;
        }

        $topP = $config->getTopP();
        if ($topP !== null) {
            $generationConfig['topP'] = $topP;
        }

        $topK = $config->getTopK();
        if ($topK !== null) {
            $generationConfig['topK'] = $topK;
        }

        $stopSequences = $config->getStopSequences();
        if (is_array($stopSequences)) {
            $generationConfig['stopSequences'] = $stopSequences;
        }

        $presencePenalty = $config->getPresencePenalty();
        if ($presencePenalty !== null) {
            $generationConfig['presencePenalty'] = $presencePenalty;
        }

        $frequencyPenalty = $config->getFrequencyPenalty();
        if ($frequencyPenalty !== null) {
            $generationConfig['frequencyPenalty'] = $frequencyPenalty;
        }

        $logprobs = $config->getLogprobs();
        if ($logprobs !== null) {
            $generationConfig['responseLogprobs'] = $logprobs;
        }

        $topLogprobs = $config->getTopLogprobs();
        if ($topLogprobs !== null) {
            $generationConfig['logprobs'] = $topLogprobs;
        }

        $outputMimeType = $config->getOutputMimeType();
        if ($outputMimeType) {
            $generationConfig['responseMimeType'] = $outputMimeType;
            if ($outputMimeType === 'application/json') {
                $outputSchema = $config->getOutputSchema();
                if ($outputSchema) {
                    // The Google AI API does not allow the `additionalProperties` key for response schemas.
                    $generationConfig['responseSchema'] = $this->removeAdditionalPropertiesKey($outputSchema);
                }
            }
        }

        if ($generationConfig) {
            $params['generationConfig'] = $generationConfig;
        }

        $tools = [];

        $functionDeclarations = $config->getFunctionDeclarations();
        if (is_array($functionDeclarations)) {
            $tools[] = [
                'functionDeclarations' => $this->prepareFunctionDeclarationsParam($functionDeclarations),
            ];
        }

        $webSearch = $config->getWebSearch();
        if ($webSearch) {
            // Filtering by allowed or disallowed domains is not supported by the Google AI API.
            $tools[] = ['googleSearch' => new \stdClass()];
        }

        if ($tools) {
            $params['tools'] = $tools;
        }

        /*
         * Any custom options are added to the parameters as well.
         * This allows developers to pass other options that may be more niche or not yet supported by the SDK.
         */
        $customOptions = $config->getCustomOptions();
        foreach ($customOptions as $key => $value) {
            // Special case: Support custom values as part of `generationConfig`.
            if (str_starts_with($key, 'generationConfig.')) {
                $key = substr($key, strlen('generationConfig.'));
                if (!isset($params['generationConfig']) || !is_array($params['generationConfig'])) {
                    $params['generationConfig'] = [$key => $value];
                    continue;
                }
                if (isset($params['generationConfig'][$key])) {
                    throw new InvalidArgumentException(
                        sprintf(
                            'The custom generationConfig option "%s" conflicts with an existing parameter.',
                            $key
                        )
                    );
                }
                $params['generationConfig'][$key] = $value;
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

        return $params;
    }

    /**
     * Prepares the contents parameter for the API request.
     *
     * @since 1.0.0
     *
     * @param list<Message> $messages The messages to prepare.
     * @return list<array<string, mixed>> The prepared contents parameter.
     */
    protected function prepareContentsParam(array $messages): array
    {
        return array_map(
            function (Message $message): array {
                return [
                    'role' => $this->getMessageRoleString($message->getRole()),
                    'parts' => array_values(array_filter(array_map(
                        [$this, 'getMessagePartData'],
                        $message->getParts()
                    ))),
                ];
            },
            $messages
        );
    }

    /**
     * Returns the Google API specific role string for the given message role.
     *
     * @since 1.0.0
     *
     * @param MessageRoleEnum $role The message role.
     * @return string The role for the API request.
     */
    protected function getMessageRoleString(MessageRoleEnum $role): string
    {
        if ($role === MessageRoleEnum::model()) {
            return 'model';
        }
        return 'user';
    }

    /**
     * Returns the Google API specific data for a message part.
     *
     * @since 1.0.0
     *
     * @param MessagePart $part The message part to get the data for.
     * @return ?array<string, mixed> The data for the message part, or null if not applicable.
     * @throws InvalidArgumentException If the message part type or data is unsupported.
     */
    protected function getMessagePartData(MessagePart $part): ?array
    {
        $type = $part->getType();
        if ($type->isText()) {
            if ($part->getChannel()->isThought()) {
                return [
                    'text'    => $part->getText(),
                    'thought' => true,
                ];
            }
            return [
                'text' => $part->getText(),
            ];
        }
        if ($type->isFile()) {
            $file = $part->getFile();
            if (!$file) {
                // This should be impossible due to class internals, but still needs to be checked.
                throw new RuntimeException(
                    'The file typed message part must contain a file.'
                );
            }
            if ($file->isRemote()) {
                $fileUrl = $file->getUrl();
                if (!$fileUrl) {
                    // This should be impossible due to class internals, but still needs to be checked.
                    throw new RuntimeException(
                        'The remote file must contain a URL.'
                    );
                }
                // Special case for YouTube video URLs.
                if (preg_match('/^https?:\/\/(?:www\.)?(?:m\.)?(?:youtu\.be\/|youtube\.com\/)/', $fileUrl)) {
                    return [
                        'fileData' => [
                            'fileUri' => $fileUrl,
                        ],
                    ];
                }
                return [
                    'fileData' => [
                        'mimeType' => $file->getMimeType(),
                        'fileUri' => $fileUrl,
                    ],
                ];
            }
            // Else, it is an inline file.
            $fileBase64Data = $file->getBase64Data();
            if (!$fileBase64Data) {
                // This should be impossible due to class internals, but still needs to be checked.
                throw new RuntimeException(
                    'The inline file must contain base64 data.'
                );
            }
            return [
                'inlineData' => [
                    'mimeType' => $file->getMimeType(),
                    'data' => $fileBase64Data,
                ],
            ];
        }
        if ($type->isFunctionCall()) {
            $functionCall = $part->getFunctionCall();
            if (!$functionCall) {
                // This should be impossible due to class internals, but still needs to be checked.
                throw new RuntimeException(
                    'The function_call typed message part must contain a function call.'
                );
            }
            $functionCallData = [
                'name' => $functionCall->getName(),
            ];
            // Only include args if present; Google's API accepts omitting args for no-argument functions.
            $args = $functionCall->getArgs();
            if ($args !== null) {
                $functionCallData['args'] = $args;
            }
            $partData = [
                'functionCall' => $functionCallData,
            ];
            /*
             * Thinking models attach a thought signature to every function call part, and the
             * Google AI API requires it to be sent back unchanged on all following turns of the
             * same conversation. Without it a multi-turn tool call fails with "Function call is
             * missing a thought_signature in functionCall parts".
             */
            $thoughtSignature = $this->getMessagePartThoughtSignature($part);
            if ($thoughtSignature !== null) {
                $partData['thoughtSignature'] = $thoughtSignature;
            }
            return $partData;
        }
        if ($type->isFunctionResponse()) {
            $functionResponse = $part->getFunctionResponse();
            if (!$functionResponse) {
                // This should be impossible due to class internals, but still needs to be checked.
                throw new RuntimeException(
                    'The function_response typed message part must contain a function response.'
                );
            }
            return [
                'functionResponse' => [
                    'name' => $functionResponse->getName(),

                    /*
                     * The Google AI API requires function responses to be objects.
                     * See also https://ai.google.dev/gemini-api/docs/function-calling#multi-turn-example-1
                     */
                    'response' => [
                        'name' => $functionResponse->getName(),
                        'content' => $functionResponse->getResponse(),
                    ],
                ],
            ];
        }
        throw new InvalidArgumentException(
            sprintf(
                'Unsupported message part type "%s".',
                $type
            )
        );
    }

    /**
     * Prepares the system instruction parameter for the API request.
     *
     * @since 1.0.0
     *
     * @param string $systemInstruction The system instruction to prepare.
     * @return array<string, mixed> The prepared system instruction parameter.
     */
    protected function prepareSystemInstructionParam(string $systemInstruction): array
    {
        return [
            'parts' => [
                [
                    'text' => $systemInstruction,
                ],
            ],
        ];
    }

    /**
     * Prepares the response modalities parameter for the API request.
     *
     * @since 1.0.0
     *
     * @param array<ModalityEnum> $modalities The modalities to prepare.
     * @return list<string> The prepared modalities parameter.
     */
    protected function prepareResponseModalitiesParam(array $modalities): array
    {
        $prepared = [];
        foreach ($modalities as $modality) {
            if ($modality->isText()) {
                $prepared[] = 'Text';
            } elseif ($modality->isImage()) {
                $prepared[] = 'Image';
            } elseif ($modality->isAudio()) {
                $prepared[] = 'Audio';
            } else {
                throw new InvalidArgumentException(
                    sprintf(
                        'Unsupported output modality "%s".',
                        $modality
                    )
                );
            }
        }
        return $prepared;
    }

    /**
     * Prepares the function declarations parameter for the API request.
     *
     * @since 1.0.0
     *
     * @param list<FunctionDeclaration> $functionDeclarations The function declarations.
     * @return list<array<string, mixed>> The prepared tools parameter.
     */
    protected function prepareFunctionDeclarationsParam(array $functionDeclarations): array
    {
        $preparedFunctionDeclarations = [];
        foreach ($functionDeclarations as $functionDeclaration) {
            $data = $functionDeclaration->toArray();
            if (isset($data['parameters'])) {
                // The Google AI API does not allow the `additionalProperties` key for function parameters.
                $data['parameters'] = $this->removeAdditionalPropertiesKey($data['parameters']);
            }
            $preparedFunctionDeclarations[] = $data;
        }

        return $preparedFunctionDeclarations;
    }

    /**
     * Removes the `additionalProperties` key from the schema, including child schemas.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $schema The schema to remove the `additionalProperties` key from.
     * @return array<string, mixed> The schema without the `additionalProperties` key.
     */
    protected function removeAdditionalPropertiesKey(array $schema): array
    {
        if (isset($schema['additionalProperties'])) {
            unset($schema['additionalProperties']);
        }
        if (isset($schema['properties']) && is_array($schema['properties'])) {
            /** @var array<string, mixed> $childSchema */
            foreach ($schema['properties'] as $key => $childSchema) {
                $schema['properties'][$key] = $this->removeAdditionalPropertiesKey($childSchema);
            }
        }
        if (isset($schema['items']) && is_array($schema['items'])) {
            if (array_is_list($schema['items'])) {
                foreach ($schema['items'] as $key => $itemSchema) {
                    if (is_array($itemSchema)) {
                        /** @var array<string, mixed> $itemSchema */
                        $schema['items'][$key] = $this->removeAdditionalPropertiesKey($itemSchema);
                    }
                }
            } else {
                /** @var array<string, mixed> $items */
                $items = $schema['items'];
                $schema['items'] = $this->removeAdditionalPropertiesKey($items);
            }
        }
        return $schema;
    }

    /**
     * Parses the response from the API endpoint to a generative AI result.
     *
     * @since 1.0.0
     *
     * @param Response $response The response from the API endpoint.
     * @return GenerativeAiResult The parsed generative AI result.
     */
    protected function parseResponseToGenerativeAiResult(Response $response): GenerativeAiResult
    {
        /** @var ResponseData $responseData */
        $responseData = $response->getData();
        if (!isset($responseData['candidates']) || !$responseData['candidates']) {
            throw ResponseException::fromMissingData($this->providerMetadata()->getName(), 'candidates');
        }
        if (!is_array($responseData['candidates'])) {
            throw ResponseException::fromInvalidData(
                $this->providerMetadata()->getName(),
                'candidates',
                'The value must be an array.'
            );
        }

        $candidates = [];
        foreach ($responseData['candidates'] as $index => $candidateData) {
            if (!is_array($candidateData) || array_is_list($candidateData)) {
                throw ResponseException::fromInvalidData(
                    $this->providerMetadata()->getName(),
                    "candidates[{$index}]",
                    'The value must be an associative array.'
                );
            }

            $candidates[] = $this->parseResponseCandidateToCandidate($candidateData, $index);
        }

        $id = isset($responseData['id']) && is_string($responseData['id']) ? $responseData['id'] : '';

        if (isset($responseData['usageMetadata']) && is_array($responseData['usageMetadata'])) {
            $usage = $responseData['usageMetadata'];

            $tokenUsage = new TokenUsage(
                $usage['promptTokenCount'] ?? 0,
                $usage['candidatesTokenCount'] ?? 0,
                ($usage['candidatesTokenCount'] ?? 0) + ($usage['thoughtsTokenCount'] ?? 0)
            );
        } else {
            $tokenUsage = new TokenUsage(0, 0, 0);
        }

        // Use any other data from the response as provider-specific response metadata.
        $additionalData = $responseData;
        unset($additionalData['id'], $additionalData['candidates'], $additionalData['usageMetadata']);

        return new GenerativeAiResult(
            $id,
            $candidates,
            $tokenUsage,
            $this->providerMetadata(),
            $this->metadata(),
            $additionalData
        );
    }

    /**
     * Parses a single candidate from the API response into a Candidate object.
     *
     * @since 1.0.0
     *
     * @param CandidateData $candidateData The candidate data from the API response.
     * @param int $index The index of the candidate in the candidates array.
     * @return Candidate The parsed candidate.
     * @throws RuntimeException If the candidate data is invalid.
     */
    protected function parseResponseCandidateToCandidate(array $candidateData, int $index): Candidate
    {
        if (
            !isset($candidateData['content']) ||
            !is_array($candidateData['content']) ||
            array_is_list($candidateData['content'])
        ) {
            throw ResponseException::fromMissingData(
                $this->providerMetadata()->getName(),
                "candidates[{$index}].content"
            );
        }

        if (!isset($candidateData['finishReason']) || !is_string($candidateData['finishReason'])) {
            throw ResponseException::fromMissingData(
                $this->providerMetadata()->getName(),
                "candidates[{$index}].finishReason"
            );
        }

        $messageData = $candidateData['content'];
        $message = $this->parseResponseCandidateMessage($messageData, $index);

        switch ($candidateData['finishReason']) {
            case 'STOP':
                /*
                 * Google API doesn't make a difference between regular stop vs because of tool calls.
                 * So we have to check ourselves.
                 */
                $finishReason = FinishReasonEnum::stop();
                foreach ($message->getParts() as $messagePart) {
                    if ($messagePart->getType()->isFunctionCall()) {
                        $finishReason = FinishReasonEnum::toolCalls();
                        break;
                    }
                }
                break;
            case 'MAX_TOKENS':
                $finishReason = FinishReasonEnum::length();
                break;
            case 'IMAGE_SAFETY':
            case 'RECITATION':
            case 'SAFETY':
            case 'BLOCKLIST':
            case 'PROHIBITED_CONTENT':
            case 'SPII':
                $finishReason = FinishReasonEnum::contentFilter();
                break;
            default:
                throw ResponseException::fromInvalidData(
                    $this->providerMetadata()->getName(),
                    "candidates[{$index}].finishReason",
                    sprintf('Invalid finish reason "%s".', $candidateData['finishReason'])
                );
        }

        return new Candidate($message, $finishReason);
    }

    /**
     * Parses the message from a candidate in the API response.
     *
     * @since 1.0.0
     *
     * @param MessageData $messageData The message data from the API response.
     * @param int $index The index of the candidate in the candidates array.
     * @return Message The parsed message.
     */
    protected function parseResponseCandidateMessage(array $messageData, int $index): Message
    {
        $role = isset($messageData['role']) && 'user' === $messageData['role']
            ? MessageRoleEnum::user()
            : MessageRoleEnum::model();

        if (!isset($messageData['parts'])) {
            throw ResponseException::fromMissingData(
                $this->providerMetadata()->getName(),
                "candidates[{$index}].content.parts"
            );
        }
        if (!is_array($messageData['parts']) || !array_is_list($messageData['parts'])) {
            throw ResponseException::fromInvalidData(
                $this->providerMetadata()->getName(),
                "candidates[{$index}].content.parts",
                'The value must be an indexed array.'
            );
        }

        $parts = [];
        foreach ($messageData['parts'] as $partIndex => $messagePartData) {
            try {
                $parts[] = $this->parseResponseCandidateMessagePart($messagePartData);
            } catch (InvalidArgumentException $e) {
                throw ResponseException::fromInvalidData(
                    $this->providerMetadata()->getName(),
                    "candidates[{$index}].content.parts[{$partIndex}]",
                    $e->getMessage()
                );
            }
        }

        return new Message($role, $parts);
    }

    /**
     * Parses a message part from a candidate in the API response.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $partData The message part data from the API response.
     * @return MessagePart The parsed message part.
     */
    protected function parseResponseCandidateMessagePart(array $partData): MessagePart
    {
        if (isset($partData['text'])) {
            if (!is_string($partData['text'])) {
                throw new InvalidArgumentException('Part has an invalid text shape.');
            }
            if (isset($partData['thought']) && $partData['thought']) {
                return new MessagePart($partData['text'], MessagePartChannelEnum::thought());
            }
            return new MessagePart($partData['text']);
        }
        if (isset($partData['inlineData'])) {
            if (
                !is_array($partData['inlineData']) ||
                !isset($partData['inlineData']['data']) ||
                !is_string($partData['inlineData']['data'])
            ) {
                throw new InvalidArgumentException('Part has an invalid inlineData shape.');
            }
            return new MessagePart(
                new File(
                    $partData['inlineData']['data'],
                    isset($partData['inlineData']['mimeType']) && is_string($partData['inlineData']['mimeType']) ?
                        $partData['inlineData']['mimeType'] :
                        null
                )
            );
        }
        if (isset($partData['fileData'])) {
            if (
                !is_array($partData['fileData']) ||
                !isset($partData['fileData']['fileUri']) ||
                !is_string($partData['fileData']['fileUri'])
            ) {
                throw new InvalidArgumentException('Part has an invalid fileData shape.');
            }
            return new MessagePart(
                new File(
                    $partData['fileData']['fileUri'],
                    isset($partData['fileData']['mimeType']) && is_string($partData['fileData']['mimeType']) ?
                        $partData['fileData']['mimeType'] :
                        null
                )
            );
        }
        if (isset($partData['functionCall'])) {
            if (
                !is_array($partData['functionCall']) ||
                !isset($partData['functionCall']['name']) ||
                !is_string($partData['functionCall']['name'])
            ) {
                throw new InvalidArgumentException('Part has an invalid functionCall shape.');
            }
            /*
             * Google may omit `args` for no-argument functions, or return `args: {}`.
             * Normalize both cases to null.
             */
            $args = $partData['functionCall']['args'] ?? null;
            if (is_array($args) && count($args) === 0) {
                $args = null;
            }
            $functionCall = new FunctionCall(
                null,
                $partData['functionCall']['name'],
                $args
            );
            /*
             * The thought signature of a function call must be preserved so that it can be sent
             * back with the conversation history on subsequent turns. See getMessagePartData().
             */
            $thoughtSignature = isset($partData['thoughtSignature']) && is_string($partData['thoughtSignature'])
                ? $partData['thoughtSignature']
                : null;
            if ($thoughtSignature !== null) {
                return new MessagePart($functionCall, null, $thoughtSignature);
            }
            return new MessagePart($functionCall);
        }
        throw new InvalidArgumentException('Part has an unexpected type.');
    }

    /**
     * Returns the thought signature of a message part, if it carries one.
     *
     * @since n.e.x.t
     *
     * @param MessagePart $part The message part to get the thought signature for.
     * @return string|null The thought signature, or null if there is none.
     */
    protected function getMessagePartThoughtSignature(MessagePart $part): ?string
    {
        $thoughtSignature = $part->getThoughtSignature();

        return $thoughtSignature !== null && $thoughtSignature !== '' ? $thoughtSignature : null;
    }
}
