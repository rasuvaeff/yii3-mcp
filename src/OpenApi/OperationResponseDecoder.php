<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Mcp\OpenApi;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Rasuvaeff\Yii3Mcp\OpenApi\Exception\OperationFailedException;
use Rasuvaeff\Yii3Mcp\Utf8;

/**
 * Decodes an upstream PSR-7 response into a tool result: status-to-error
 * mapping with the error-body excerpt, the incremental bounded body read and
 * the JSON/plain-text fallback. Shared by both executors so the HTTP and
 * in-process transports behave identically apart from the send itself.
 *
 * @internal
 */
final readonly class OperationResponseDecoder
{
    private const int MAX_ERROR_BODY_LENGTH = 2_000;

    /**
     * Materialized upstream response cap, mirrors the SDK transport's own
     * request-body default.
     */
    public const int DEFAULT_MAX_RESPONSE_BYTES = 4 * 1024 * 1024;

    /**
     * Nesting cap for decoding the upstream JSON body — far above any sane
     * API payload, far below the depth where recursive decoding hurts.
     */
    private const int JSON_MAX_DEPTH = 128;

    /**
     * @param int $maxResponseBytes upper bound on the upstream response body this decoder will buffer
     * @param bool $opaqueErrors suppress the upstream error-body excerpt in failures — for
     *                           service-token deployments where the upstream's error
     *                           details are not the MCP caller's to see
     */
    public function __construct(
        private int $maxResponseBytes = self::DEFAULT_MAX_RESPONSE_BYTES,
        private bool $opaqueErrors = false,
    ) {
        if ($maxResponseBytes < 1) {
            throw new InvalidArgumentException(sprintf('Max response bytes must be at least 1, %d given', $maxResponseBytes));
        }
    }

    /**
     * @param string $served the tool name the client actually called — every
     *                       message the caller reads names it
     */
    public function decode(ResponseInterface $response, string $served): mixed
    {
        if ($response->getStatusCode() >= 300) {
            if ($this->opaqueErrors) {
                throw new OperationFailedException(sprintf(
                    'Tool "%s" failed with HTTP %d',
                    $served,
                    $response->getStatusCode(),
                ));
            }

            // the error path only ever needs the excerpt, so its read is
            // capped at excerpt size — an unbounded error page cannot
            // buffer. One byte over the excerpt length, so Utf8::cut sees an
            // over-limit body and trims a split trailing character instead
            // of passing it through untouched
            [$body, $truncated] = $this->readUpTo($response->getBody(), self::MAX_ERROR_BODY_LENGTH + 1, keepPrefix: true);

            throw new OperationFailedException(sprintf(
                'Tool "%s" failed with HTTP %d: %s',
                $served,
                $response->getStatusCode(),
                $this->errorExcerpt($body, $truncated),
            ));
        }

        [$body, $truncated] = $this->readUpTo($response->getBody(), $this->maxResponseBytes, keepPrefix: false);

        if ($truncated) {
            throw new OperationFailedException(sprintf(
                'Tool "%s" response exceeds the %d-byte limit; refusing to buffer it',
                $served,
                $this->maxResponseBytes,
            ));
        }

        try {
            return json_decode($body, associative: true, depth: self::JSON_MAX_DEPTH, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $body;
        }
    }

    /**
     * Incremental bounded read: an advertised size over the cap is rejected
     * before a single byte is read; an unadvertised (chunked) body is read in
     * chunks and abandoned the moment it exceeds the cap. The boolean tells
     * the caller whether the body was cut short. With $keepPrefix the caller
     * wants the first $cap bytes of an oversized body (the error excerpt), so
     * the advertised size is not an early-out.
     *
     * @return array{string, bool}
     */
    private function readUpTo(StreamInterface $stream, int $cap, bool $keepPrefix): array
    {
        // unlike a (string) cast, read() starts at the CURRENT position — a
        // seekable body that was already consumed (or created at EOF) must be
        // rewound or it reads as empty
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        if (!$keepPrefix) {
            $size = $stream->getSize();

            if ($size !== null && $size > $cap) {
                return ['', true];
            }
        }

        $contents = '';

        while (!$stream->eof()) {
            $chunk = $stream->read(8192);

            if ($chunk === '') {
                break;
            }

            $contents .= $chunk;

            if (strlen($contents) > $cap) {
                return [substr($contents, 0, $cap), true];
            }
        }

        return [$contents, false];
    }

    /**
     * The excerpt travels to the client inside a tool-error envelope the SDK
     * encodes with JSON_THROW_ON_ERROR, so it must be valid UTF-8: cutting
     * mid-character (Utf8::cut prevents that) or an upstream body that was
     * never UTF-8 in the first place (an HTML error page in a legacy
     * encoding, a binary payload) would make the whole response unencodable —
     * silently dropped on the Streamable HTTP transport.
     *
     * @param bool $bodyTruncated whether the bounded read already cut the body short
     */
    private function errorExcerpt(string $body, bool $bodyTruncated): string
    {
        $excerpt = Utf8::cut($body, self::MAX_ERROR_BODY_LENGTH);

        if (preg_match('//u', $excerpt) !== 1) {
            return sprintf('<non-UTF-8 response body, %s%d bytes>', $bodyTruncated ? 'over ' : '', strlen($body));
        }

        return $bodyTruncated || strlen($body) > strlen($excerpt) ? $excerpt . '…' : $excerpt;
    }
}
