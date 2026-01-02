<?php
/**
 * CoderAI OpenAI Provider
 * Handles OpenAI API calls
 */

if (!defined('CODERAI')) {
    die('Direct access not allowed');
}

class OpenAIProvider
{
    private $apiKey;
    private $baseUrl = 'https://api.openai.com/v1';

    public function __construct($apiKey)
    {
        $this->apiKey = $apiKey;
    }

    /**
     * Send chat completion request
     */
    public function chat($messages, $options = [])
    {
        $model = $options['model'] ?? 'gpt-4o-mini';
        $temperature = $options['temperature'] ?? 0.7;
        $maxTokens = $options['max_tokens'] ?? 4096;
        $responseFormat = $options['response_format'] ?? null;

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => $temperature,
            'max_tokens' => $maxTokens
        ];

        // Add JSON mode if requested
        if ($responseFormat === 'json') {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $response = $this->request('/chat/completions', $payload);

        if (isset($response['error'])) {
            throw new Exception($response['error']['message'] ?? 'OpenAI API error');
        }

        return [
            'content' => $response['choices'][0]['message']['content'] ?? '',
            'model' => $response['model'] ?? $model,
            'provider' => 'openai',
            'usage' => [
                'input_tokens' => $response['usage']['prompt_tokens'] ?? 0,
                'output_tokens' => $response['usage']['completion_tokens'] ?? 0,
                'total_tokens' => $response['usage']['total_tokens'] ?? 0
            ],
            'finish_reason' => $response['choices'][0]['finish_reason'] ?? null
        ];
    }

    /**
     * Check if model supports JSON mode
     */
    public function supportsJsonMode($model)
    {
        return in_array($model, [
            'gpt-4o',
            'gpt-4o-mini',
            'gpt-4-turbo',
            'gpt-3.5-turbo'
        ]);
    }

    /**
     * Check if model supports tools/functions
     */
    public function supportsTools($model)
    {
        return in_array($model, [
            'gpt-4o',
            'gpt-4o-mini',
            'gpt-4-turbo',
            'gpt-4',
            'gpt-3.5-turbo'
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
                'Authorization: Bearer ' . $this->apiKey
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
            error_log("OpenAI API error (HTTP {$httpCode}): {$response}");
        }

        return json_decode($response, true);
    }
}