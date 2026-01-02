<?php
/**
 * CoderAI Ollama Gateway Provider
 * Handles calls to your self-hosted Ollama gateway
 */

if (!defined('CODERAI')) {
    die('Direct access not allowed');
}

class OllamaGatewayProvider
{
    private $gatewayUrl;
    private $apiKey;
    private $timeout;

    public function __construct()
    {
        $this->gatewayUrl = getenv('AI_GATEWAY_URL') ?: 'https://ai.coderai.co.za/chat';
        $this->apiKey = getenv('AI_GATEWAY_KEY') ?: '';
        $this->timeout = (int) (getenv('AI_TIMEOUT') ?: 120);

        if (empty($this->apiKey)) {
            throw new Exception('AI_GATEWAY_KEY not configured in .env');
        }
    }

    /**
     * Send chat completion request
     */
    public function chat($messages, $options = [])
    {
        $model = $options['model'] ?? getenv('AI_MODEL_FAST');
        $temperature = $options['temperature'] ?? (float) getenv('AI_TEMP_FAST');

        // Build payload exactly as your gateway expects
        $payload = [
            'model' => $model,
            'temperature' => $temperature,
            'messages' => $messages
        ];

        $response = $this->request($payload);

        if (isset($response['error'])) {
            throw new Exception($response['error']['message'] ?? 'Ollama Gateway error');
        }

        // Extract content from response
        $content = '';
        if (isset($response['message']['content'])) {
            $content = $response['message']['content'];
        } elseif (isset($response['choices'][0]['message']['content'])) {
            $content = $response['choices'][0]['message']['content'];
        } elseif (isset($response['content'])) {
            $content = $response['content'];
        }

        // Estimate tokens if not provided (rough: 4 chars = 1 token)
        $inputTokens = isset($response['prompt_eval_count']) 
            ? $response['prompt_eval_count'] 
            : $this->estimateTokens(json_encode($messages));
        
        $outputTokens = isset($response['eval_count']) 
            ? $response['eval_count'] 
            : $this->estimateTokens($content);

        return [
            'content' => $content,
            'model' => $model,
            'provider' => 'ollama_gateway',
            'usage' => [
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'total_tokens' => $inputTokens + $outputTokens
            ],
            'finish_reason' => $response['done'] ?? 'stop'
        ];
    }

    /**
     * Make API request to gateway
     */
    private function request($data)
    {
        // DEBUG: Log what we're sending to the gateway
        error_log("[GATEWAY DEBUG] URL: " . $this->gatewayUrl);
        error_log("[GATEWAY DEBUG] Messages count: " . count($data['messages'] ?? []));
        if (!empty($data['messages'])) {
            foreach ($data['messages'] as $i => $msg) {
                error_log("[GATEWAY DEBUG] Message {$i}: role={$msg['role']}, len=" . strlen($msg['content']));
            }
        }

        $ch = curl_init($this->gatewayUrl);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-ai-key: ' . $this->apiKey
            ],
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            error_log('Ollama Gateway cURL error: ' . $error);
            throw new Exception('AI gateway unavailable: ' . $error);
        }

        if ($httpCode !== 200) {
            error_log("Ollama Gateway error (HTTP {$httpCode}): {$response}");
            
            // Handle specific error codes
            if ($httpCode === 0) {
                throw new Exception('AI gateway offline or unreachable');
            } elseif ($httpCode === 401 || $httpCode === 403) {
                throw new Exception('AI gateway authentication failed');
            } elseif ($httpCode >= 500) {
                throw new Exception('AI gateway server error');
            } else {
                throw new Exception('AI gateway error (HTTP ' . $httpCode . ')');
            }
        }

        $decoded = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('Ollama Gateway invalid JSON: ' . $response);
            throw new Exception('Invalid response from AI gateway');
        }

        return $decoded;
    }

    /**
     * Estimate tokens (rough approximation)
     */
    private function estimateTokens($text)
    {
        // Rough estimate: ~4 chars per token for English
        return (int) ceil(strlen($text) / 4);
    }

    /**
     * Check if model supports vision (none do in this setup)
     */
    public function supportsVision($model)
    {
        return false;
    }

    /**
     * Check if model supports tools (none do in this setup)
     */
    public function supportsTools($model)
    {
        return false;
    }

    /**
     * Stream chat completion - yields chunks as they arrive
     * This method directly outputs to the response stream
     */
    public function chatStream($messages, $options = [], $callback = null)
    {
        $model = $options['model'] ?? getenv('AI_MODEL_FAST');
        $temperature = $options['temperature'] ?? (float) getenv('AI_TEMP_FAST');

        $payload = [
            'model' => $model,
            'temperature' => $temperature,
            'messages' => $messages,
            'stream' => true
        ];

        $fullContent = '';
        $inputTokens = $this->estimateTokens(json_encode($messages));

        $ch = curl_init($this->gatewayUrl);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-ai-key: ' . $this->apiKey
            ],
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_WRITEFUNCTION => function($ch, $data) use (&$fullContent, $callback) {
                // Parse streaming response - Ollama returns JSON lines
                $lines = explode("\n", $data);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (empty($line)) continue;

                    $json = json_decode($line, true);
                    if ($json && isset($json['message']['content'])) {
                        $chunk = $json['message']['content'];
                        $fullContent .= $chunk;

                        // Call callback with chunk
                        if ($callback) {
                            $callback($chunk, $json['done'] ?? false);
                        }
                    } elseif ($json && isset($json['response'])) {
                        // Alternative Ollama format
                        $chunk = $json['response'];
                        $fullContent .= $chunk;

                        if ($callback) {
                            $callback($chunk, $json['done'] ?? false);
                        }
                    }
                }
                return strlen($data);
            }
        ]);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception('AI gateway unavailable: ' . $error);
        }

        if ($httpCode !== 200) {
            throw new Exception('AI gateway error (HTTP ' . $httpCode . ')');
        }

        $outputTokens = $this->estimateTokens($fullContent);

        return [
            'content' => $fullContent,
            'model' => $model,
            'provider' => 'ollama_gateway',
            'usage' => [
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'total_tokens' => $inputTokens + $outputTokens
            ]
        ];
    }
}