<?php
/**
 * AI 回复服务提供商
 * 支持 OpenAI 兼容格式的 API
 *
 * @author 小铁
 * @version 1.0.0
 */

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class AICover_Reply_Provider
{
    /**
     * 最近一次错误信息
     */
    private static $lastError = '';

    /**
     * 生成回复
     *
     * @param string $context 上下文内容（文章+评论）
     * @param object $cfg 插件配置
     * @return string|false 生成的回复文本
     */
    public static function generateReply($context, $cfg)
    {
        self::$lastError = '';

        $endpoint = $cfg->replyEndpoint ?? '';
        $apiKey = $cfg->replyApiKey ?? '';
        $model = $cfg->replyModel ?? '';

        if (empty($endpoint)) {
            self::$lastError = 'AI 回复 API 端点未配置';
            return false;
        }

        if (empty($apiKey)) {
            self::$lastError = 'AI 回复 API Key 未配置';
            return false;
        }

        if (empty($model)) {
            self::$lastError = 'AI 回复模型未配置';
            return false;
        }

        $timeout = (int)($cfg->replyRequestTimeout ?? 15);
        if ($timeout <= 0) {
            $timeout = 15;
        }
        if ($timeout > 60) {
            $timeout = 60;
        }

        // 兼容 base URL 或完整 chat/completions URL 两种配置
        $endpoint = self::normalizeReplyEndpoint($endpoint);

        // 获取系统提示词
        $systemPrompt = $cfg->replySystemPrompt ?? "你是一位友善的博客评论回复助手。请根据文章内容和评论内容，给出恰当、有建设性的回复。回复应该：\n1. 简洁明了，不超过 200 字\n2. 友善有礼，体现对读者的尊重\n3. 针对评论内容给出实质性回应\n4. 必要时可以提出问题引导进一步讨论\n5. 使用中文回复";

        return self::callOpenAICompatible($endpoint, $apiKey, $model, $systemPrompt, $context, $timeout);
    }

    /**
     * 获取最后一次错误信息
     */
    public static function getLastError()
    {
        return self::$lastError;
    }

    /**
     * 调用 OpenAI 兼容格式的 API
     */
    private static function callOpenAICompatible($endpoint, $apiKey, $model, $systemPrompt, $userContent, $timeout = 15)
    {
        $body = json_encode([
            'model' => $model,
            'max_tokens' => 500,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userContent],
            ],
        ], JSON_UNESCAPED_UNICODE);

        $response = self::httpPost($endpoint, $body, [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ], (int)$timeout);

        if ($response === false) {
            return false;
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            self::$lastError = 'API 返回非 JSON 格式';
            return false;
        }

        if (isset($data['error'])) {
            self::$lastError = is_array($data['error'])
                ? ($data['error']['message'] ?? json_encode($data['error'], JSON_UNESCAPED_UNICODE))
                : (string)$data['error'];
            return false;
        }

        // 提取回复内容
        if (isset($data['choices'][0]['message']['content'])) {
            $content = $data['choices'][0]['message']['content'];
            if (is_array($content)) {
                // 处理 content 为数组的情况
                $texts = [];
                foreach ($content as $part) {
                    if (is_array($part) && isset($part['text'])) {
                        $texts[] = (string)$part['text'];
                    } elseif (is_string($part)) {
                        $texts[] = $part;
                    }
                }
                $content = trim(implode("\n", $texts));
            }
            return trim((string)$content);
        }

        if (isset($data['choices'][0]['text'])) {
            return trim((string)$data['choices'][0]['text']);
        }

        self::$lastError = 'API 返回结构不支持';
        return false;
    }

    /**
     * HTTP POST 请求
     */
    private static function httpPost($url, $body, $headers = [], $timeout = 60)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // 带 Bearer 鉴权时禁止自动跟随重定向，避免泄漏凭据
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $result = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno) {
            self::$lastError = 'cURL 错误: ' . $error;
            return false;
        }

        if ($httpCode >= 400) {
            $snippet = trim((string)$result);
            $snippet = mb_substr($snippet, 0, 300);
            self::$lastError = 'HTTP ' . $httpCode . ($snippet !== '' ? ' - ' . $snippet : '');
            return false;
        }

        return $result;
    }

    /**
     * 规范化回复端点，避免重复拼接 /chat/completions
     */
    private static function normalizeReplyEndpoint($endpoint)
    {
        $endpoint = trim((string)$endpoint);
        if ($endpoint === '') {
            return '';
        }

        $endpoint = rtrim($endpoint, '/');
        if (stripos($endpoint, 'chat/completions') !== false) {
            return $endpoint;
        }

        if (preg_match('#/v\d+$#i', $endpoint)) {
            return $endpoint . '/chat/completions';
        }

        return $endpoint . '/v1/chat/completions';
    }
}
