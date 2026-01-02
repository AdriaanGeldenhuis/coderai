<?php
/**
 * CoderAI Anthropic Provider
 * Handles Anthropic Claude API calls
 */

if (!defined('CODERAI')) {
    die('Direct access not allowed');
}

class AnthropicProvider
{
    private $apiKey;
    private $baseUrl = 'https://api.anthropic.com/v1';

    public function __construct($apiKey)
    {
        $this->apiKey = $apiKey;
    }

    /**
     * Send chat completion request
     */
    public function chat($messages, $options = [])
    {
        $model = $options['model'] ?? 'claude-3-5-sonnet-20241022';
        $temperature = $options['temperature'] ?? 0.7;
        $maxTokens = $options['max_tokens'] ?? 4096;

        // Extract system message if present
        $systemMessage = '';
        $chatMessages = [];

        foreach ($messages as $msg) {
            if ($msg['role'] === 'system') {
                $systemMessage .= $msg['content'] . "\n";
            } else {
                $chatMessages[] = [
                    'role' => $msg['role'],
                    'content' => $msg['content']
                ];
            }
        }

        $payload = [
            'model' => $model,
            'messages' => $chatMessages,
            'max_tokens' => $maxTokens,
            'temperature' => $temperature
        ];

        if (!empty($systemMessage)) {
            $payload['system'] = trim($systemMessage);
        }

        $response = $this->request('/messages', $payload);

        if (isset($response['error'])) {
            throw new Exception($response['error']['message'] ?? 'Anthropic API error');
        }

        $content = '';
        if (isset($response['content']) && is_array($response['content'])) {
            foreach ($response['content'] as $block) {
                if ($block['type'] === 'text') {
                    $content .= $block['text'];
                }
            }
        }

        return [
            'content' => $content,
            'model' => $response['model'] ?? $model,
            'provider' => 'anthropic',
            'usage' => [
                'input_tokens' => $response['usage']['input_tokens'] ?? 0,
                'output_tokens' => $response['usage']['output_tokens'] ?? 0,
                'total_tokens' => ($response['usage']['input_tokens'] ?? 0) + ($response['usage']['output_tokens'] ?? 0)
            ],
            'finish_reason' => $response['stop_reason'] ?? null
        ];
    }

    /**
     * Check if model supports vision
     */
    public function supportsVision($model)
    {
        return in_array($model, [
            'claude-3-5-sonnet-20241022',
            'claude-3-opus-20240229',
            'claude-3-sonnet-20240229',
            'claude-3-haiku-20240307'
        ]);
    }

    /**
     * Check if model supports tools
     */
    public function supportsTools($model)
    {
        return in_array($model, [
            'claude-3-5-sonnet-20241022',
            'claude-3-opus-20240229'
        ]);
    }

    /**
     * Make API request
     */
    private function request($endpoint, $data)
    {
        $ch = curl_init($this->baseUrl . $endpoint);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: 2023-06-01'
            ],
            CURLOPT_TIMEOUT => 120
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception('cURL error: ' . $error);
        }

        if ($httpCode !== 200) {
            error_log("Anthropic API error (HTTP {$httpCode}): {$response}");
        }

        return json_decode($response, true);
    }
}