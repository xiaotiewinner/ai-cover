<?php
/**
 * 评论内容过滤器
 * 敏感词检测和内容安全检查
 *
 * @author 小铁
 * @version 1.0.0
 */

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class AICover_Reply_Filter
{
    /**
     * 检查评论是否包含敏感词
     *
     * @param string $content 评论内容
     * @param object $cfg 插件配置
     * @return bool 如果包含敏感词返回 true
     */
    public static function containsBlockedWords($content, $cfg = null)
    {
        if ($cfg === null) {
            $cfg = Typecho_Widget::widget('Widget_Options')->plugin('AICover');
        }

        $blockedWords = trim($cfg->replyBlockedWords ?? '');
        if (empty($blockedWords)) {
            return false;
        }

        $words = self::parseWordList($blockedWords);
        if (empty($words)) {
            return false;
        }

        $content = mb_strtolower($content);

        foreach ($words as $word) {
            if (mb_stripos($content, $word) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * 检查评论是否包含触发关键词
     *
     * @param string $content 评论内容
     * @param object $cfg 插件配置
     * @return bool 如果包含触发词返回 true
     */
    public static function containsTriggerKeywords($content, $cfg = null)
    {
        if ($cfg === null) {
            $cfg = Typecho_Widget::widget('Widget_Options')->plugin('AICover');
        }

        $keywords = trim($cfg->replyKeywords ?? '');

        // 如果没有设置关键词，默认只回复包含问号的评论
        if (empty($keywords)) {
            return mb_strpos($content, '？') !== false || mb_strpos($content, '?') !== false;
        }

        $words = self::parseWordList($keywords);
        if (empty($words)) {
            return true; // 关键词列表为空时，回复所有评论
        }

        $content = mb_strtolower($content);

        foreach ($words as $word) {
            if (mb_stripos($content, $word) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * 检查是否应该回复此评论
     *
     * @param array $comment 评论数据
     * @param object $cfg 插件配置
     * @return array ['shouldReply' => bool, 'reason' => string]
     */
    public static function shouldReply($comment, $cfg = null)
    {
        if ($cfg === null) {
            $cfg = Typecho_Widget::widget('Widget_Options')->plugin('AICover');
        }

        // 检查功能是否开启
        $replyMode = $cfg->replyMode ?? 'off';
        if ($replyMode === 'off') {
            return ['shouldReply' => false, 'reason' => 'AI 回复功能已关闭'];
        }

        $content = $comment['text'] ?? '';
        $author = $comment['author'] ?? '';
        $parent = (int)($comment['parent'] ?? 0);

        // 检查评论者是否是 AI 自己（避免循环回复）
        $aiName = $cfg->replyAiName ?? 'AI助手';
        if (strcasecmp($author, $aiName) === 0) {
            return ['shouldReply' => false, 'reason' => 'AI 不回复自己的评论'];
        }

        // 检查敏感词
        if (self::containsBlockedWords($content, $cfg)) {
            return ['shouldReply' => false, 'reason' => '评论包含敏感词'];
        }

        // 检查排除的作者
        $excludeAuthors = trim($cfg->replyExcludeAuthors ?? '');
        if (!empty($excludeAuthors)) {
            $excludes = array_map('trim', explode(',', $excludeAuthors));
            foreach ($excludes as $excluded) {
                if (strcasecmp($author, $excluded) === 0) {
                    return ['shouldReply' => false, 'reason' => '作者被排除回复'];
                }
            }
        }

        // 检查是否只回复一级评论
        if ($cfg->replyFirstLevelOnly && $parent > 0) {
            return ['shouldReply' => false, 'reason' => '只回复一级评论'];
        }

        // 检查关键词触发
        $replyToAll = $cfg->replyToAll ?? '1';
        if ($replyToAll !== '1') {
            if (!self::containsTriggerKeywords($content, $cfg)) {
                return ['shouldReply' => false, 'reason' => '未触发关键词'];
            }
        }

        // 评论内容过短不回复
        $cleanContent = preg_replace('/\s+/', '', $content);
        if (mb_strlen($cleanContent) < 2) {
            return ['shouldReply' => false, 'reason' => '评论内容过短'];
        }

        return ['shouldReply' => true, 'reason' => '满足回复条件'];
    }

    /**
     * 解析词列表（按行分割）
     */
    private static function parseWordList($text)
    {
        $lines = explode("\n", $text);
        $words = [];

        foreach ($lines as $line) {
            $word = trim($line);
            if (!empty($word)) {
                $words[] = mb_strtolower($word);
            }
        }

        return $words;
    }

    /**
     * 清理 AI 生成的回复内容
     *
     * @param string $reply 原始回复
     * @return string 清理后的回复
     */
    public static function sanitizeReply($reply)
    {
        // 移除 HTML 标签
        $reply = strip_tags($reply);

        // 移除常见的 AI 前缀
        $prefixes = [
            '/^回复：/u',
            '/^回复:/u',
            '/^AI回复：/u',
            '/^AI回复:/u',
            '/^答：/u',
            '/^答:/u',
        ];

        foreach ($prefixes as $pattern) {
            $reply = preg_replace($pattern, '', $reply);
        }

        // 截断过长的内容
        if (mb_strlen($reply) > 500) {
            $reply = mb_substr($reply, 0, 500) . '...';
        }

        return trim($reply);
    }
}
