<?php
/**
 * 评论回复上下文构建器
 * 构建包含文章内容和评论线程的上下文
 *
 * @author 小铁
 * @version 1.0.0
 */

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class AICover_Reply_ContextBuilder
{
    /** 评论线程最大深度 */
    private const MAX_THREAD_DEPTH = 5;
    /**
     * 构建回复上下文
     *
     * @param int $cid 文章ID
     * @param int $parentCoid 父评论ID（如果是回复评论）
     * @param object $cfg 插件配置
     * @param array $currentComment 当前评论数据
     * @return string 格式化后的上下文
     */
    public static function build($cid, $parentCoid = 0, $cfg = null, $currentComment = null)
    {
        if ($cfg === null) {
            $cfg = Typecho_Widget::widget('Widget_Options')->plugin('AICover');
        }

        $parts = [];
        $maxLength = (int)($cfg->replyMaxContextLength ?? 2000);

        // 1. 添加文章内容（如果启用）
        if ($cfg->replyIncludeArticle && $cid > 0) {
            $article = self::getArticleContent($cid);
            if ($article) {
                $articleText = self::formatArticle($article, $maxLength);
                if (!empty($articleText)) {
                    $parts[] = $articleText;
                }
            }
        }

        // 2. 添加父评论线程（如果启用且有父评论）
        if ($cfg->replyIncludeParent && $parentCoid > 0) {
            $thread = self::getParentThread($parentCoid);
            if (!empty($thread)) {
                $threadText = self::formatThread($thread, (int)($maxLength * 0.4));
                if (!empty($threadText)) {
                    $parts[] = $threadText;
                }
            }
        }

        // 3. 添加当前评论
        if ($currentComment) {
            $parts[] = self::formatCurrentComment($currentComment);
        }

        $context = implode("\n\n", $parts);

        // 截断到最大长度
        if (mb_strlen($context) > $maxLength) {
            $context = mb_substr($context, 0, $maxLength) . "...";
        }

        return $context;
    }

    /**
     * 获取文章内容
     */
    private static function getArticleContent($cid)
    {
        try {
            $db = Typecho_Db::get();
            $row = $db->fetchRow(
                $db->select('title', 'text')
                    ->from('table.contents')
                    ->where('cid = ?', $cid)
                    ->limit(1)
            );
            return $row ?: null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * 获取父评论线程
     */
    private static function getParentThread($parentCoid)
    {
        $thread = [];
        $currentCoid = $parentCoid;
        $maxDepth = self::MAX_THREAD_DEPTH;

        try {
            $db = Typecho_Db::get();

            while ($currentCoid > 0 && $maxDepth-- > 0) {
                $row = $db->fetchRow(
                    $db->select('coid', 'author', 'text', 'parent', 'created')
                        ->from('table.comments')
                        ->where('coid = ?', $currentCoid)
                        ->limit(1)
                );

                if (!$row) {
                    break;
                }

                $thread[] = $row;
                $currentCoid = (int)$row['parent'];
            }

            // 反转数组，使最早的评论在前
            return array_reverse($thread);

        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * 格式化文章内容
     */
    private static function formatArticle($article, $maxLength)
    {
        $title = strip_tags($article['title'] ?? '');
        $text = strip_tags($article['text'] ?? '');

        // 截取文章内容
        $textLimit = (int)($maxLength * 0.5); // 文章占用50%的上下文
        if (mb_strlen($text) > $textLimit) {
            $text = mb_substr($text, 0, $textLimit) . "...";
        }

        $result = "【博客文章】\n";
        $result .= "标题：{$title}\n";
        if (!empty($text)) {
            $result .= "内容摘要：{$text}";
        }

        return $result;
    }

    /**
     * 格式化评论线程
     */
    private static function formatThread($thread, $maxLength)
    {
        $lines = ["【评论对话】"];

        foreach ($thread as $i => $comment) {
            $author = strip_tags($comment['author'] ?? '访客');
            $text = strip_tags($comment['text'] ?? '');
            $text = preg_replace('/\s+/', ' ', $text);
            $text = mb_substr($text, 0, 200); // 单条评论最多200字符

            $lines[] = ($i + 1) . ". {$author}：{$text}";
        }

        $result = implode("\n", $lines);

        if (mb_strlen($result) > $maxLength) {
            $result = mb_substr($result, 0, $maxLength) . "...";
        }

        return $result;
    }

    /**
     * 格式化当前评论
     */
    private static function formatCurrentComment($comment)
    {
        $author = strip_tags($comment['author'] ?? '访客');
        $text = strip_tags($comment['text'] ?? '');
        $text = preg_replace('/\s+/', ' ', $text);

        return "【需要回复的评论】\n{$author}：{$text}\n\n请针对以上评论给出恰当的回复。";
    }
}
