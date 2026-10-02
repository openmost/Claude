<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\Claude\Agent;

/**
 * Reads the Server-Sent Events of a streamed Messages API answer, chunk by chunk as they arrive.
 *
 * Only what the chat displays is reported: the text deltas, the stop reason and the errors. Thinking, signature and
 * tool input deltas, pings and the block boundaries are skipped.
 */
final class AnthropicStream
{
    public const TEXT = 'text';
    public const STOP = 'stop';
    public const ERROR = 'error';

    /** @var string */
    private $buffer = '';

    /** @var bool */
    private $stopped = false;

    /**
     * @return list<array{type: string, text?: string, reason?: string, errorType?: string, message?: string}>
     */
    public function feed(string $chunk): array
    {
        $this->buffer .= str_replace("\r\n", "\n", $chunk);

        $events = [];
        while (($end = strpos($this->buffer, "\n\n")) !== false) {
            $block = substr($this->buffer, 0, $end);
            $this->buffer = (string) substr($this->buffer, $end + 2);
            foreach ($this->parseBlock($block) as $event) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /**
     * Events of an incomplete last block, once the connection is closed
     *
     * @return list<array{type: string, text?: string, reason?: string, errorType?: string, message?: string}>
     */
    public function finish(): array
    {
        $block = trim($this->buffer);
        $this->buffer = '';

        return $block === '' ? [] : $this->parseBlock($block);
    }

    /**
     * Whether the answer ended with message_stop
     */
    public function isStopped(): bool
    {
        return $this->stopped;
    }

    /**
     * @return list<array{type: string, text?: string, reason?: string, errorType?: string, message?: string}>
     */
    private function parseBlock(string $block): array
    {
        $eventName = '';
        $data = [];
        foreach (explode("\n", $block) as $line) {
            if (strpos($line, 'event:') === 0) {
                $eventName = trim(substr($line, 6));
            } elseif (strpos($line, 'data:') === 0) {
                $data[] = ltrim(substr($line, 5));
            }
        }
        if ($data === []) {
            return [];
        }

        $payload = json_decode(implode("\n", $data), true);
        if (!is_array($payload)) {
            return [];
        }

        $type = is_string($payload['type'] ?? null) ? $payload['type'] : $eventName;
        switch ($type) {
            case 'content_block_delta':
                $delta = is_array($payload['delta'] ?? null) ? $payload['delta'] : [];
                if (($delta['type'] ?? '') === 'text_delta' && is_string($delta['text'] ?? null) && $delta['text'] !== '') {
                    return [['type' => self::TEXT, 'text' => $delta['text']]];
                }
                return [];
            case 'message_delta':
                $reason = $payload['delta']['stop_reason'] ?? null;
                return is_string($reason) && $reason !== '' ? [['type' => self::STOP, 'reason' => $reason]] : [];
            case 'message_stop':
                $this->stopped = true;
                return [];
            case 'error':
                $error = is_array($payload['error'] ?? null) ? $payload['error'] : [];
                return [[
                    'type' => self::ERROR,
                    'errorType' => is_string($error['type'] ?? null) ? $error['type'] : 'api_error',
                    'message' => is_string($error['message'] ?? null) ? $error['message'] : '',
                ]];
            default:
                return [];
        }
    }
}
