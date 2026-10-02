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
 * Maps the conversations, the MCP tools and their results to the Anthropic Messages API format, and reads its answers.
 *
 * The Messages API takes the system prompt as a top-level field, user and assistant messages that start with a user
 * message, and tool calls as tool_use content blocks answered by tool_result blocks in the next user message.
 */
final class AnthropicFormat
{
    public const MAX_TOOL_RESULT_CHARS = 40000;

    public const STOP_TOOL_USE = 'tool_use';
    public const STOP_REFUSAL = 'refusal';
    public const STOP_MAX_TOKENS = 'max_tokens';

    /**
     * Request body of the Messages API for a conversation in the chat format of the plugin: system messages become the
     * top-level system field, the user and assistant messages alternate and start with a user message.
     *
     * @param list<array{role: string, content: string}> $conversation
     * @param string $firstUserMessage sent when the conversation has no user message to start with, such as the
     *                                 insights panel that opens without a question
     * @return array<string, mixed>
     */
    public static function buildRequest(
        array $conversation,
        string $model,
        int $maxTokens,
        bool $stream = false,
        string $firstUserMessage = ''
    ): array {
        $system = [];
        $messages = [];
        foreach ($conversation as $message) {
            $role = (string) ($message['role'] ?? '');
            $content = trim((string) ($message['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            if ($role === 'system') {
                $system[] = $content;
            } elseif ($role === 'user' || $role === 'assistant') {
                $messages[] = ['role' => $role, 'content' => $content];
            }
        }

        $request = [
            'model' => $model,
            'max_tokens' => $maxTokens,
        ];
        if ($system !== []) {
            $request['system'] = implode("\n\n", $system);
        }
        $request['messages'] = self::toMessages($messages, $firstUserMessage);
        if ($stream) {
            $request['stream'] = true;
        }

        return $request;
    }

    /**
     * User and assistant messages as the API accepts them: consecutive messages of the same role are merged, the
     * conversation starts with a user message and ends with one, as the current models refuse an assistant prefill.
     *
     * @param list<array{role: string, content: string}> $messages
     * @return list<array{role: string, content: string}>
     */
    public static function toMessages(array $messages, string $firstUserMessage = ''): array
    {
        $result = [];
        foreach ($messages as $message) {
            $role = (string) ($message['role'] ?? '');
            $content = trim((string) ($message['content'] ?? ''));
            if (!in_array($role, ['user', 'assistant'], true) || $content === '') {
                continue;
            }
            if ($result === [] && $role !== 'user') {
                if ($firstUserMessage === '') {
                    continue;
                }
                $result[] = ['role' => 'user', 'content' => $firstUserMessage];
            }

            $last = count($result) - 1;
            if ($last >= 0 && $result[$last]['role'] === $role) {
                $result[$last]['content'] .= "\n\n" . $content;
            } else {
                $result[] = ['role' => $role, 'content' => $content];
            }
        }

        if ($result === [] && $firstUserMessage !== '') {
            $result[] = ['role' => 'user', 'content' => $firstUserMessage];
        }

        while ($result !== [] && $result[count($result) - 1]['role'] === 'assistant') {
            array_pop($result);
        }

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $catalog tools of McpServer: name, description, inputSchema
     * @return list<array{name: string, description: string, input_schema: array<string, mixed>}>
     */
    public static function toTools(array $catalog): array
    {
        $tools = [];
        foreach ($catalog as $tool) {
            if (!is_array($tool) || !is_string($tool['name'] ?? null) || $tool['name'] === '') {
                continue;
            }
            $tools[] = [
                'name' => $tool['name'],
                'description' => is_string($tool['description'] ?? null) ? $tool['description'] : '',
                'input_schema' => self::toInputSchema(is_array($tool['inputSchema'] ?? null) ? $tool['inputSchema'] : []),
            ];
        }

        return $tools;
    }

    /**
     * The API rejects combinators at the top level of an input schema. They are only validation hints: McpServer
     * validates the arguments again when the tool is called.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public static function toInputSchema(array $schema): array
    {
        unset($schema['oneOf'], $schema['anyOf'], $schema['allOf'], $schema['not'], $schema['enum'], $schema['const']);
        $schema['type'] = 'object';
        if (!isset($schema['properties']) || !is_array($schema['properties']) || $schema['properties'] === []) {
            // an empty PHP array would be encoded as a JSON list, not an object
            $schema['properties'] = new \stdClass();
        }

        return $schema;
    }

    /**
     * Text of the content blocks of an answer: thinking and tool_use blocks are left out
     *
     * @param mixed $content
     */
    public static function getText($content): string
    {
        if (is_string($content)) {
            return $content;
        }
        if (!is_array($content)) {
            return '';
        }

        $parts = [];
        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'text' && is_string($block['text'] ?? null)) {
                $parts[] = $block['text'];
            }
        }

        return implode('', $parts);
    }

    /**
     * The tool_use blocks of an answer. The input is null when it is not a JSON object.
     *
     * @param mixed $content
     * @return list<array{id: string, name: string, input: array<string, mixed>|null}>
     */
    public static function getToolUses($content): array
    {
        $toolUses = [];
        foreach (is_array($content) ? $content : [] as $block) {
            if (!is_array($block) || ($block['type'] ?? '') !== 'tool_use') {
                continue;
            }
            $id = $block['id'] ?? null;
            $name = $block['name'] ?? null;
            if (!is_string($id) || $id === '' || !is_string($name) || $name === '') {
                continue;
            }
            $toolUses[] = ['id' => $id, 'name' => $name, 'input' => self::decodeInput($block['input'] ?? [])];
        }

        return $toolUses;
    }

    /**
     * @param mixed $input
     * @return array<string, mixed>|null null when the input is not a JSON object
     */
    public static function decodeInput($input): ?array
    {
        if ($input instanceof \stdClass) {
            $input = json_decode((string) json_encode($input), true);
        }
        if ($input === null || $input === []) {
            return [];
        }
        if (!is_array($input)) {
            return null;
        }
        foreach (array_keys($input) as $key) {
            if (!is_string($key)) {
                return null;
            }
        }

        return $input;
    }

    /**
     * Content of an answer replayed in the next request of the agent. The blocks are sent back unchanged, thinking
     * blocks included: the API refuses a modified thinking block. The response decoded as objects keeps the empty JSON
     * objects that an associative decoding turns into lists.
     *
     * @param array<string, mixed> $response decoded response, with the "rawContent" of AnthropicClient when available
     * @return array<int, mixed>
     */
    public static function replayContent(array $response): array
    {
        if (isset($response[AnthropicClient::RAW_CONTENT]) && is_array($response[AnthropicClient::RAW_CONTENT])) {
            return $response[AnthropicClient::RAW_CONTENT];
        }

        $content = [];
        foreach (is_array($response['content'] ?? null) ? $response['content'] : [] as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'tool_use' && ($block['input'] ?? []) === []) {
                $block['input'] = new \stdClass();
            }
            $content[] = $block;
        }

        return $content;
    }

    /**
     * @param array{content: list<array<string, mixed>>, structuredContent: array<string, mixed>|null, isError: bool} $result
     * @return array{type: string, tool_use_id: string, content: string, is_error?: bool}
     */
    public static function toolResultBlock(string $toolUseId, array $result): array
    {
        $block = [
            'type' => 'tool_result',
            'tool_use_id' => $toolUseId,
            'content' => self::toolResultText($result),
        ];
        if (!empty($result['isError'])) {
            $block['is_error'] = true;
        }

        return $block;
    }

    /**
     * The structured result when there is one, the text of the MCP content otherwise. Truncated so a large report
     * does not exceed the context of the model or the tokens per minute of the plan.
     *
     * @param array{content: list<array<string, mixed>>, structuredContent: array<string, mixed>|null, isError: bool} $result
     */
    public static function toolResultText(array $result): string
    {
        if (is_array($result['structuredContent'] ?? null)) {
            $text = (string) json_encode($result['structuredContent'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            $parts = [];
            foreach (is_array($result['content'] ?? null) ? $result['content'] : [] as $block) {
                if (is_array($block) && ($block['type'] ?? '') === 'text' && is_string($block['text'] ?? null)) {
                    $parts[] = $block['text'];
                } else {
                    $parts[] = (string) json_encode($block, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
            }
            $text = implode("\n", $parts);
        }

        if ($text === '') {
            $text = !empty($result['isError']) ? 'The tool failed.' : 'The tool returned no data.';
        }

        if (mb_strlen($text) > self::MAX_TOOL_RESULT_CHARS) {
            $text = mb_substr($text, 0, self::MAX_TOOL_RESULT_CHARS) . "\n[truncated: ask for fewer rows or a narrower report]";
        }

        return $text;
    }

    /**
     * An answer of the Messages API in the response format of the chat clients of the plugin
     *
     * @param array<string, mixed> $response
     * @return array{choices: list<array{message: array{role: string, content: string}, finish_reason: string}>}
     */
    public static function toChatResponse(array $response): array
    {
        return [
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => trim(self::getText($response['content'] ?? []))],
                'finish_reason' => is_string($response['stop_reason'] ?? null) ? $response['stop_reason'] : '',
            ]],
        ];
    }
}
