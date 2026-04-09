<?php
/**
 * AI 回复生成器
 * 协调回复生成的完整流程
 *
 * @author 小铁
 * @version 1.0.0
 */

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class AICover_Reply_ReplyGenerator
{
    /**
     * 处理新评论，生成 AI 回复
     *
     * @param array $comment 评论数据
     * @param object $cfg 插件配置（可选）
     * @return array ['success' => bool, 'message' => string, 'data' => array]
     */
    public static function processComment($comment, $cfg = null)
    {
        AICover_Plugin::ensureReplyInfrastructure();

        if ($cfg === null) {
            $cfg = Typecho_Widget::widget('Widget_Options')->plugin('AICover');
        }

        $cid = (int)($comment['cid'] ?? 0);
        $coid = (int)($comment['coid'] ?? 0);
        $replyMode = $cfg->replyMode ?? 'off';
        if ($replyMode === 'off') {
            AICover_Plugin::log('AI 回复跳过: mode=off', $cid);
            return ['success' => false, 'message' => 'AI 回复功能已关闭', 'data' => null];
        }

        // 不回复文章作者自己的评论
        if (self::isPostAuthorComment($comment)) {
            AICover_Plugin::log('AI 回复跳过: 文章作者本人评论 coid=' . $coid, $cid);
            return ['success' => false, 'message' => '文章作者评论无需 AI 回复', 'data' => null];
        }

        // auto 模式仅处理已审核通过评论
        if ($replyMode === 'auto') {
            $status = strtolower(trim((string)($comment['status'] ?? '')));
            if ($status === '') {
                $status = self::getCommentStatusByCoid((int)($comment['coid'] ?? 0));
            }
            if ($status === '' || $status !== 'approved') {
                AICover_Plugin::log('AI 回复跳过: status=' . $status . ' coid=' . $coid, $cid);
                return ['success' => false, 'message' => '评论状态未知或未审核通过，自动回复跳过', 'data' => null];
            }
        }

        // 检查是否应该回复
        $filterResult = AICover_Reply_Filter::shouldReply($comment, $cfg);
        if (!$filterResult['shouldReply']) {
            AICover_Plugin::log('AI 回复跳过: filter=' . ($filterResult['reason'] ?? 'unknown') . ' coid=' . $coid, $cid);
            return ['success' => false, 'message' => $filterResult['reason'], 'data' => null];
        }

        // 检查频率限制
        $limitResult = AICover_Reply_RateLimiter::checkLimit($cfg);
        if (!$limitResult['allowed']) {
            AICover_Plugin::log('AI 回复跳过: rate_limit=' . ($limitResult['reason'] ?? 'denied') . ' coid=' . $coid, $cid);
            return ['success' => false, 'message' => $limitResult['reason'], 'data' => null];
        }

        // 构建上下文
        $parent = (int)($comment['parent'] ?? 0);
        $context = AICover_Reply_ContextBuilder::build($cid, $parent, $cfg, $comment);

        // 根据模式处理
        switch ($replyMode) {
            case 'auto':
                return self::autoReply($comment, $context, $cfg);
            case 'manual':
                return self::queueForReview($comment, $context, $cfg);
            case 'suggest':
                return self::createSuggestion($comment, $context, $cfg);
            default:
                return ['success' => false, 'message' => '未知的工作模式', 'data' => null];
        }
    }

    /**
     * 自动回复模式：生成并立即发布回复
     */
    private static function autoReply($comment, $context, $cfg)
    {
        // 前台评论提交流程（/action/feedback?type=comment）中避免同步延迟，
        // 否则容易因请求耗时过长被主题前端判定“评论失败”。
        if (!self::isFeedbackCommentRequest()) {
            self::applyDelay($cfg);
        }

        // 生成回复
        $reply = AICover_Reply_Provider::generateReply($context, $cfg);

        if ($reply === false) {
            $error = AICover_Reply_Provider::getLastError();
            AICover_Plugin::log('AI 回复生成失败: ' . $error, (int)($comment['cid'] ?? 0));
            return ['success' => false, 'message' => '生成失败: ' . $error, 'data' => null];
        }

        // 清理回复内容
        $reply = AICover_Reply_Filter::sanitizeReply($reply);

        // auto 也计入“生成次数”限流口径
        AICover_Reply_RateLimiter::logReply(
            (int)($comment['coid'] ?? 0),
            (int)($comment['cid'] ?? 0),
            'generation'
        );

        // 添加签名
        $signature = trim($cfg->replySignature ?? '');
        if (!empty($signature)) {
            $reply .= "\n" . $signature;
        }

        // 发布回复
        $result = self::postReply($comment, $reply, $cfg);

        if ($result['success']) {
            // 记录日志
            AICover_Reply_RateLimiter::logReply(
                (int)($comment['coid'] ?? 0),
                (int)($comment['cid'] ?? 0),
                'publish'
            );
        }

        return $result;
    }

    /**
     * 人工审核模式：将回复加入审核队列
     */
    private static function queueForReview($comment, $context, $cfg)
    {
        // 生成回复
        $reply = AICover_Reply_Provider::generateReply($context, $cfg);

        if ($reply === false) {
            $error = AICover_Reply_Provider::getLastError();
            AICover_Plugin::log('AI 回复生成失败(审核模式): ' . $error, (int)($comment['cid'] ?? 0));
            return ['success' => false, 'message' => '生成失败: ' . $error, 'data' => null];
        }

        // 清理回复内容
        $reply = AICover_Reply_Filter::sanitizeReply($reply);

        // 保存到审核队列
        try {
            $db = Typecho_Db::get();
            $coid = (int)($comment['coid'] ?? 0);
            $cid  = (int)($comment['cid'] ?? 0);

            // 幂等：同一评论仅保留一条待审核记录，重复触发改为覆盖更新
            $existing = $db->fetchRow(
                $db->select('id')->from('table.aicover_reply_queue')
                    ->where('coid = ?', $coid)
                    ->where('cid = ?', $cid)
                    ->where('status = ?', 'pending')
                    ->limit(1)
            );

            if ($existing) {
                $db->query(
                    $db->update('table.aicover_reply_queue')
                        ->rows([
                            'parent' => (int)($comment['parent'] ?? 0),
                            'author' => strip_tags($comment['author'] ?? '访客'),
                            'text' => strip_tags($comment['text'] ?? ''),
                            'ai_reply' => $reply,
                        ])
                        ->where('id = ?', (int)$existing['id'])
                );
                AICover_Reply_RateLimiter::logReply($coid, $cid, 'generation');
                return [
                    'success' => true,
                    'message' => '已更新审核队列',
                    'data' => ['reply' => $reply, 'status' => 'pending', 'id' => (int)$existing['id']]
                ];
            }

            $db->query(
                $db->insert('table.aicover_reply_queue')
                    ->rows([
                        'coid' => $coid,
                        'cid' => $cid,
                        'parent' => (int)($comment['parent'] ?? 0),
                        'author' => strip_tags($comment['author'] ?? '访客'),
                        'text' => strip_tags($comment['text'] ?? ''),
                        'ai_reply' => $reply,
                        'status' => 'pending',
                        'created_at' => date('Y-m-d H:i:s'),
                    ])
            );
            AICover_Reply_RateLimiter::logReply($coid, $cid, 'generation');

            return [
                'success' => true,
                'message' => '已加入审核队列',
                'data' => ['reply' => $reply, 'status' => 'pending']
            ];

        } catch (Exception $e) {
            AICover_Plugin::log('保存到审核队列失败: ' . $e->getMessage());
            return ['success' => false, 'message' => '保存失败', 'data' => null];
        }
    }

    /**
     * 建议模式：仅保存建议，不发布
     */
    private static function createSuggestion($comment, $context, $cfg)
    {
        // 生成回复
        $reply = AICover_Reply_Provider::generateReply($context, $cfg);

        if ($reply === false) {
            $error = AICover_Reply_Provider::getLastError();
            AICover_Plugin::log('AI 回复生成失败(建议模式): ' . $error, (int)($comment['cid'] ?? 0));
            return ['success' => false, 'message' => '生成失败: ' . $error, 'data' => null];
        }

        // 清理回复内容
        $reply = AICover_Reply_Filter::sanitizeReply($reply);

        // 保存到建议表
        try {
            $db = Typecho_Db::get();
            $coid = (int)($comment['coid'] ?? 0);
            $cid  = (int)($comment['cid'] ?? 0);

            // 幂等：同一评论仅保留一条未使用建议，重复触发改为覆盖更新
            $existing = $db->fetchRow(
                $db->select('id')->from('table.aicover_reply_suggestions')
                    ->where('coid = ?', $coid)
                    ->where('cid = ?', $cid)
                    ->where('is_used = ?', 0)
                    ->limit(1)
            );

            if ($existing) {
                $db->query(
                    $db->update('table.aicover_reply_suggestions')
                        ->rows([
                            'suggestion_text' => $reply,
                            'created_at' => date('Y-m-d H:i:s'),
                        ])
                        ->where('id = ?', (int)$existing['id'])
                );
                AICover_Reply_RateLimiter::logReply($coid, $cid, 'generation');
                return [
                    'success' => true,
                    'message' => '建议已更新',
                    'data' => ['reply' => $reply, 'status' => 'suggestion', 'id' => (int)$existing['id']]
                ];
            }

            $db->query(
                $db->insert('table.aicover_reply_suggestions')
                    ->rows([
                        'coid' => $coid,
                        'cid' => $cid,
                        'suggestion_text' => $reply,
                        'is_used' => 0,
                        'created_at' => date('Y-m-d H:i:s'),
                    ])
            );
            AICover_Reply_RateLimiter::logReply($coid, $cid, 'generation');

            return [
                'success' => true,
                'message' => '建议已生成',
                'data' => ['reply' => $reply, 'status' => 'suggestion']
            ];

        } catch (Exception $e) {
            AICover_Plugin::log('保存建议失败: ' . $e->getMessage());
            return ['success' => false, 'message' => '保存失败', 'data' => null];
        }
    }

    /**
     * 发布回复评论
     */

    /**
     * Hash IP address for privacy
     */
    private static function hashIpAddress($ip)
    {
        // Get plugin secret or use fallback salt
        $cfg = Typecho_Widget::widget('Widget_Options')->plugin('AICover');
        $secret = AICover_Plugin::getReplySecret($cfg);
        return hash('sha256', $ip . $secret);
    }

    /**
     * 是否为文章作者自己的评论
     */
    private static function isPostAuthorComment($comment)
    {
        $authorId = (int)($comment['authorId'] ?? 0);
        $ownerId  = (int)($comment['ownerId'] ?? 0);

        // 首选：直接用评论行上的 authorId/ownerId 判断
        if ($authorId > 0 && $ownerId > 0) {
            return $authorId === $ownerId;
        }

        // 兜底：通过 coid 查询真实评论行，避免钩子参数不完整
        $coid = (int)($comment['coid'] ?? 0);
        if ($coid <= 0) {
            return false;
        }

        try {
            $db = Typecho_Db::get();
            $row = $db->fetchRow(
                $db->select('authorId', 'ownerId')->from('table.comments')
                    ->where('coid = ?', $coid)
                    ->limit(1)
            );
            if (!$row) {
                return false;
            }
            $authorId = (int)($row['authorId'] ?? 0);
            $ownerId  = (int)($row['ownerId'] ?? 0);
            return $authorId > 0 && $ownerId > 0 && $authorId === $ownerId;
        } catch (Exception $e) {
            AICover_Plugin::log('判断作者评论失败: ' . $e->getMessage(), (int)($comment['cid'] ?? 0));
            return false;
        }
    }

    /**
     * 通过 coid 查询评论状态（auto 模式兜底）
     */
    private static function getCommentStatusByCoid($coid)
    {
        if ($coid <= 0) {
            return '';
        }

        try {
            $db = Typecho_Db::get();
            $row = $db->fetchRow(
                $db->select('status')->from('table.comments')
                    ->where('coid = ?', $coid)
                    ->limit(1)
            );
            return strtolower(trim((string)($row['status'] ?? '')));
        } catch (Exception $e) {
            AICover_Plugin::log('查询评论状态失败: ' . $e->getMessage());
            return '';
        }
    }

    public static function postReply($parentComment, $replyText, $cfg)
    {
        $cid = (int)($parentComment['cid'] ?? 0);
        $parentCoid = (int)($parentComment['coid'] ?? 0);

        if ($cid <= 0) {
            return ['success' => false, 'message' => '无效的文章ID', 'data' => null];
        }

        // 获取 AI 身份设置
        $aiName = $cfg->replyAiName ?? 'AI助手';

        try {
            $db = Typecho_Db::get();

            // 获取文章信息
            $content = $db->fetchRow(
                $db->select()->from('table.contents')->where('cid = ?', $cid)
            );

            if (!$content) {
                return ['success' => false, 'message' => '文章不存在', 'data' => null];
            }

            // 使用博主（文章作者）邮箱和 URL 作为 AI 评论资料
            $ownerMail = '';
            $ownerUrl = '';
            $ownerId = (int)($content['authorId'] ?? 0);
            if ($ownerId > 0) {
                $owner = $db->fetchRow(
                    $db->select('mail', 'url')
                        ->from('table.users')
                        ->where('uid = ?', $ownerId)
                        ->limit(1)
                );
                if ($owner) {
                    $ownerMail = trim((string)($owner['mail'] ?? ''));
                    $ownerUrl = trim((string)($owner['url'] ?? ''));
                }
            }

            // 准备评论数据
            $commentData = [
                'cid' => $cid,
                'created' => time(),
                'author' => $aiName,
                'authorId' => 0,
                'ownerId' => $content['authorId'] ?? 0,
                'mail' => $ownerMail,
                'url' => $ownerUrl,
                'ip' => self::hashIpAddress($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'),
                'agent' => 'AICover-Bot/1.0',
                'text' => $replyText,
                'type' => 'comment',
                'status' => 'approved',
                'parent' => $parentCoid,
            ];

            // 插入评论（Typecho 的 query(insert) 会返回新主键）
            $insertResult = $db->query(
                $db->insert('table.comments')->rows($commentData)
            );
            $newCoid = is_numeric($insertResult) ? (int)$insertResult : 0;
            if ($newCoid <= 0 && method_exists($db, 'lastInsertId')) {
                $newCoid = (int)$db->lastInsertId();
            }

            if ($newCoid > 0) {

                // 更新文章评论数
                $db->query(
                    $db->update('table.contents')
                        ->expression('commentsNum', 'commentsNum + 1')
                        ->where('cid = ?', $cid)
                );

                // 标记为 AI 生成的评论（写入 fields 表）
                AICover_Plugin::saveMeta($newCoid, 'aicover_ai_generated', '1');

                return [
                    'success' => true,
                    'message' => '回复已发布',
                    'data' => ['coid' => $newCoid, 'text' => $replyText]
                ];
            }

            return ['success' => false, 'message' => '插入评论失败', 'data' => null];

        } catch (Exception $e) {
            AICover_Plugin::log('发布回复失败: ' . $e->getMessage());
            return ['success' => false, 'message' => '发布失败', 'data' => null];
        }
    }

    /**
     * 应用延迟（模拟人工回复）
     */
    private static function applyDelay($cfg)
    {
        $minDelay = (int)($cfg->replyDelayMin ?? 0);
        $maxDelay = (int)($cfg->replyDelayMax ?? 0);

        if ($minDelay <= 0 && $maxDelay <= 0) {
            return;
        }

        // 确保最大值 >= 最小值
        if ($maxDelay < $minDelay) {
            $maxDelay = $minDelay;
        }

        // 随机延迟
        $delay = mt_rand($minDelay, $maxDelay);

        if ($delay > 0) {
            sleep($delay);
        }
    }

    /**
     * 是否为前台评论提交请求
     */
    private static function isFeedbackCommentRequest()
    {
        $type = strtolower(trim((string)($_REQUEST['type'] ?? '')));
        if ($type === 'comment') {
            return true;
        }

        $uri = strtolower(trim((string)($_SERVER['REQUEST_URI'] ?? '')));
        return $uri !== '' && strpos($uri, '/action/feedback') !== false;
    }

    /**
     * 审核通过并发布回复（用于人工审核模式）
     *
     * @param int $queueId 队列ID
     * @return array
     */
    public static function approveReply($queueId)
    {
        try {
            $db = Typecho_Db::get();

            // 获取队列中的回复
            $queue = $db->fetchRow(
                $db->select()->from('table.aicover_reply_queue')
                    ->where('id = ?', $queueId)
                    ->where('status = ?', 'pending')
            );

            if (!$queue) {
                return ['success' => false, 'message' => '记录不存在或已处理'];
            }

            // 原子抢占处理权，避免并发重复发布
            $claimed = $db->query(
                $db->update('table.aicover_reply_queue')
                    ->rows(['status' => 'processing'])
                    ->where('id = ?', $queueId)
                    ->where('status = ?', 'pending')
            );
            if ((int)$claimed <= 0) {
                return ['success' => false, 'message' => '记录正在处理中或已处理'];
            }

            // 构建父评论数据
            // AI 回复应该作为触发评论的子评论嵌套在触发评论下方
            // $queue['coid'] = 触发AI回复的评论ID（被回复的评论）
            // $queue['parent'] = 触发AI回复的评论的父评论ID
            $parentComment = [
                'coid' => (int)$queue['coid'],      // 被回复的评论ID
                'cid' => (int)$queue['cid'],
                'parent' => (int)$queue['parent'], // 被回复评论的父评论（用于上下文）
            ];

            $cfg = Typecho_Widget::widget('Widget_Options')->plugin('AICover');

            // 发布回复
            $result = self::postReply($parentComment, $queue['ai_reply'], $cfg);

            if ($result['success']) {
                // 更新队列状态
                $updated = $db->query(
                    $db->update('table.aicover_reply_queue')
                        ->rows([
                            'status' => 'approved',
                            'processed_at' => date('Y-m-d H:i:s'),
                        ])
                        ->where('id = ?', $queueId)
                        ->where('status = ?', 'processing')
                );
                if ((int)$updated <= 0) {
                    return ['success' => false, 'message' => '状态更新失败，请刷新后重试'];
                }

                // 记录日志
                AICover_Reply_RateLimiter::logReply((int)$queue['coid'], (int)$queue['cid'], 'publish');
            } else {
                // 发布失败，回滚到 pending 便于后续重试
                $db->query(
                    $db->update('table.aicover_reply_queue')
                        ->rows(['status' => 'pending'])
                        ->where('id = ?', $queueId)
                        ->where('status = ?', 'processing')
                );
            }

            return $result;

        } catch (Exception $e) {
            AICover_Plugin::log('审核通过失败: ' . $e->getMessage());
            return ['success' => false, 'message' => '处理失败: ' . $e->getMessage()];
        }
    }

    /**
     * 拒绝回复（用于人工审核模式）
     *
     * @param int $queueId 队列ID
     * @return array
     */
    public static function rejectReply($queueId)
    {
        try {
            $db = Typecho_Db::get();

            $updated = $db->query(
                $db->update('table.aicover_reply_queue')
                    ->rows([
                        'status' => 'rejected',
                        'processed_at' => date('Y-m-d H:i:s'),
                    ])
                    ->where('id = ?', $queueId)
                    ->where('status = ?', 'pending')
            );

            if ((int)$updated <= 0) {
                return ['success' => false, 'message' => '仅待审核记录可拒绝'];
            }

            return ['success' => true, 'message' => '已拒绝'];

        } catch (Exception $e) {
            return ['success' => false, 'message' => '操作失败'];
        }
    }

    /**
     * 重新生成回复（用于审核队列）
     *
     * @param int $queueId 队列ID
     * @return array
     */
    public static function regenerateReply($queueId)
    {
        try {
            $db = Typecho_Db::get();

            // 获取队列中的记录
            $queue = $db->fetchRow(
                $db->select()->from('table.aicover_reply_queue')
                    ->where('id = ?', $queueId)
                    ->where('status = ?', 'pending')
            );

            if (!$queue) {
                return ['success' => false, 'message' => '记录不存在或非待审核状态'];
            }

            $cfg = Typecho_Widget::widget('Widget_Options')->plugin('AICover');

            // 构建评论数据
            $comment = [
                'cid' => (int)$queue['cid'],
                'parent' => (int)$queue['parent'],
                'author' => $queue['author'],
                'text' => $queue['text'],
            ];

            // 构建上下文
            $context = AICover_Reply_ContextBuilder::build(
                $comment['cid'],
                $comment['parent'],
                $cfg,
                $comment
            );

            // 生成新回复
            $newReply = AICover_Reply_Provider::generateReply($context, $cfg);

            if ($newReply === false) {
                return ['success' => false, 'message' => '重新生成失败'];
            }

            $newReply = AICover_Reply_Filter::sanitizeReply($newReply);

            // 更新队列
            $db->query(
                $db->update('table.aicover_reply_queue')
                    ->rows(['ai_reply' => $newReply])
                    ->where('id = ?', $queueId)
            );

            return ['success' => true, 'message' => '已重新生成', 'data' => ['reply' => $newReply]];

        } catch (Exception $e) {
            return ['success' => false, 'message' => '操作失败'];
        }
    }
}
