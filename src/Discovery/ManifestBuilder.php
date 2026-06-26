<?php

declare(strict_types=1);

namespace Qcodr\Restate\Sdk\Discovery;

use Qcodr\Restate\Sdk\Protocol\ServiceProtocolVersion;
use Qcodr\Restate\Sdk\Service\HandlerDefinition;
use Qcodr\Restate\Sdk\Service\HandlerOptions;
use Qcodr\Restate\Sdk\Service\ServiceDefinition;
use Qcodr\Restate\Sdk\Service\ServiceOptions;

/**
 * Builds the endpoint discovery manifest returned from `GET /discovery`.
 *
 * The manifest advertises the supported protocol range, the request/response
 * transport mode, and every registered service with its handlers and JSON
 * input/output payload descriptors. When a handler's PHP input/output type yields
 * a meaningful schema, a draft 2020-12 `jsonSchema` is attached to the payload
 * descriptor via {@see JsonSchemaGenerator}; otherwise the key is omitted.
 *
 * Service- and handler-level configuration ({@see ServiceOptions} / {@see HandlerOptions})
 * is emitted only when the negotiated manifest version supports the field:
 *   - `documentation` / `metadata` require v2+;
 *   - timeouts, retention windows, `enableLazyState` and `ingressPrivate` require v3+;
 *   - the retry-policy fields require v4+.
 *
 * Unknown/older versions simply omit the unsupported keys, so a v1 runtime keeps
 * receiving the original name/ty/handlers manifest.
 */
final class ManifestBuilder
{
    private const CONTENT_TYPE_JSON = 'application/json';

    private const VERSION_METADATA = 2;
    private const VERSION_TIMEOUTS = 3;
    private const VERSION_RETRY_POLICY = 4;

    public function __construct(
        private readonly JsonSchemaGenerator $schemaGenerator = new JsonSchemaGenerator(),
    ) {
    }

    /**
     * @param list<ServiceDefinition> $services
     * @param array<string, ServiceOptions> $options per-service options keyed by service name
     *
     * @return array<string, mixed>
     */
    public function build(array $services, int $manifestVersion = 1, array $options = []): array
    {
        $built = [];
        foreach ($services as $service) {
            $built[] = $this->buildService($service, $manifestVersion, $options[$service->name] ?? null);
        }

        return [
            'minProtocolVersion' => ServiceProtocolVersion::min()->value,
            'maxProtocolVersion' => ServiceProtocolVersion::max()->value,
            'protocolMode' => 'REQUEST_RESPONSE',
            'services' => $built,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildService(ServiceDefinition $service, int $version, ?ServiceOptions $options): array
    {
        $handlers = [];
        foreach (\array_values($service->handlers) as $handler) {
            $handlers[] = $this->buildHandler($handler, $version, $options?->handlerOptions($handler->name));
        }

        $entry = [
            'name' => $service->name,
            'ty' => $service->type->value,
            'handlers' => $handlers,
        ];

        if ($options !== null) {
            $entry = $this->applyCommonOptions($entry, $options, $version);
        }

        return $entry;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildHandler(HandlerDefinition $handler, int $version, ?HandlerOptions $options): array
    {
        $entry = ['name' => $handler->name];

        if ($handler->type !== null) {
            $entry['ty'] = $handler->type->value;
        }

        if ($handler->hasInput) {
            $input = [
                'required' => true,
                'contentType' => self::CONTENT_TYPE_JSON,
            ];
            $inputSchema = $this->schemaGenerator->forType($handler->inputType);
            if ($inputSchema !== null) {
                $input['jsonSchema'] = $inputSchema;
            }
            $entry['input'] = $input;
        }

        if ($handler->hasOutput) {
            $output = [
                'contentType' => self::CONTENT_TYPE_JSON,
                'setContentTypeIfEmpty' => false,
            ];
            $outputSchema = $this->schemaGenerator->forType($handler->outputType);
            if ($outputSchema !== null) {
                $output['jsonSchema'] = $outputSchema;
            }
            $entry['output'] = $output;
        }

        if ($options !== null) {
            $entry = $this->applyCommonOptions($entry, $options, $version);
            if ($version >= self::VERSION_TIMEOUTS && $options->workflowCompletionRetentionMillis !== null) {
                $entry['workflowCompletionRetention'] = $options->workflowCompletionRetentionMillis;
            }
        }

        return $entry;
    }

    /**
     * Appends the version-gated option keys shared by services and handlers.
     *
     * @param array<string, mixed> $entry
     *
     * @return array<string, mixed>
     */
    private function applyCommonOptions(array $entry, ServiceOptions|HandlerOptions $options, int $version): array
    {
        if ($version >= self::VERSION_METADATA) {
            if ($options->documentation !== null) {
                $entry['documentation'] = $options->documentation;
            }
            if ($options->metadata !== null) {
                $entry['metadata'] = $options->metadata;
            }
        }

        if ($version >= self::VERSION_TIMEOUTS) {
            $entry = self::addInt($entry, 'inactivityTimeout', $options->inactivityTimeoutMillis);
            $entry = self::addInt($entry, 'abortTimeout', $options->abortTimeoutMillis);
            $entry = self::addInt($entry, 'journalRetention', $options->journalRetentionMillis);
            $entry = self::addInt($entry, 'idempotencyRetention', $options->idempotencyRetentionMillis);
            if ($options->enableLazyState !== null) {
                $entry['enableLazyState'] = $options->enableLazyState;
            }
            if ($options->ingressPrivate !== null) {
                $entry['ingressPrivate'] = $options->ingressPrivate;
            }
        }

        if ($version >= self::VERSION_RETRY_POLICY) {
            $entry = self::addInt($entry, 'retryPolicyInitialInterval', $options->retryPolicyInitialIntervalMillis);
            $entry = self::addInt($entry, 'retryPolicyMaxInterval', $options->retryPolicyMaxIntervalMillis);
            $entry = self::addInt($entry, 'retryPolicyMaxAttempts', $options->retryPolicyMaxAttempts);
            if ($options->retryPolicyExponentiationFactor !== null) {
                $entry['retryPolicyExponentiationFactor'] = $options->retryPolicyExponentiationFactor;
            }
            if ($options->retryPolicyOnMaxAttempts !== null) {
                $entry['retryPolicyOnMaxAttempts'] = $options->retryPolicyOnMaxAttempts->value;
            }
        }

        return $entry;
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return array<string, mixed>
     */
    private static function addInt(array $entry, string $key, ?int $value): array
    {
        if ($value !== null) {
            $entry[$key] = $value;
        }

        return $entry;
    }
}
