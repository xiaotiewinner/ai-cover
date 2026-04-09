<?php
/**
 * Typecho 钩子处理器
 * 处理评论相关钩子，触发 AI 回复
 *
 * @author 小铁
 * @version 1.1.2
 */

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class AICover_Reply_HookHandler
{
    /** @var array|null 待处理的评论数据 */
    private static $pendingComment = null;

    /** @var object|null 待处理的 Widget 实例 */
    private static $pendingWidget = null;

    /** @var array<int, bool> 当前请求内已处理的评论ID，防止重复触发 */
    private static $processedCoids = array();
    /** @var array<string, bool> coid 缺失时的请求内指纹去重 */
    private static $processedFingerprints = array();

    /**
     * 评论提交后的钩子处理（filter 类型）
     * 在评论数据被处理时触发，此时评论可能还未写入数据库
     *
     * @param mixed $comment 评论数据（可能是数组或 Widget 对象）
     * @param mixed $archive Archive 对象
     * @return mixed 处理后的评论数据
     */
    public static function onComment($comment, $archive = null)
    {
        try {
            // 提取评论数组
            $commentData = null;

            if (is_array($comment) && isset($comment['cid'])) {
                // $comment 是正确的评论数组
                $commentData = $comment;
            } elseif (is_object($comment)) {
                // $comment 是对象，尝试提取评论数据
                if (method_exists($comment, 'toArray')) {
                    $arr = $comment->toArray();
                    if (is_array($arr) && isset($arr['cid'])) {
                        $commentData = $arr;
                    }
                }
                if (empty($commentData)) {
                    $cid = (int)self::readObjectField($comment, 'cid', 0);
                    if ($cid <= 0) {
                        return $comment;
                    }
                    $commentData = [
                        'cid' => $cid,
                        'coid' => (int)self::readObjectField($comment, 'coid', 0),
                        'author' => (string)self::readObjectField($comment, 'author', ''),
                        'text' => (string)self::readObjectField($comment, 'text', ''),
                        'parent' => (int)self::readObjectField($comment, 'parent', 0),
                        'mail' => (string)self::readObjectField($comment, 'mail', ''),
                        'url' => (string)self::readObjectField($comment, 'url', ''),
                    ];
                }
            }

            // 如果无法提取有效的评论数据，跳过处理
            if (empty($commentData) || !isset($commentData['cid'])) {
                return $comment;
            }

            // 检查是否是 AI 生成的评论（避免循环回复）
            if (!empty($commentData['coid']) && self::isAIGeneratedCommentByCoid((int)$commentData['coid'])) {
                return $comment;
            }

            // 将评论数据存储到静态变量，供 finishComment 使用
            self::$pendingComment = $commentData;
            self::$pendingWidget = $archive;

            return $comment;

        } catch (Exception $e) {
            AICover_Plugin::log('onComment 异常: ' . $e->getMessage());
            return $comment;
        }
    }

    /**
     * 评论提交完成后的钩子处理（call 类型）
     * 在评论成功写入数据库后触发，此时评论已有 coid
     * 这是触发 AI 回复的最佳时机
     *
     * @param mixed $feedback Widget\Feedback 对象或其他参数
     */
    public static function onFinishComment($feedback)
    {
        try {
            // 提取评论数据
            $commentData = null;
            $feedbackData = null;

            // 如果有 pending 的评论数据，优先使用它
            if (!empty(self::$pendingComment)) {
                $commentData = self::$pendingComment;
            }

            // 尝试从 $feedback 提取数据（按官方 Widget 机制优先读取 toArray）
            if (is_object($feedback)) {
                $arr = null;
                if (method_exists($feedback, 'toArray')) {
                    $tmp = $feedback->toArray();
                    if (is_array($tmp) && !empty($tmp)) {
                        $arr = $tmp;
                    }
                }

                if (is_array($arr) && isset($arr['cid'])) {
                    $feedbackData = [
                        'cid' => (int)($arr['cid'] ?? 0),
                        'coid' => (int)($arr['coid'] ?? 0),
                        'author' => (string)($arr['author'] ?? ''),
                        'text' => (string)($arr['text'] ?? ''),
                        'parent' => (int)($arr['parent'] ?? 0),
                        'mail' => (string)($arr['mail'] ?? ''),
                        'url' => (string)($arr['url'] ?? ''),
                        'status' => (string)($arr['status'] ?? ''),
                    ];
                } else {
                    // finishComment 触发时官方流程已 insert + push，通常可直接读取最新评论字段
                    $cid = (int)self::readObjectField($feedback, 'cid', 0);
                    if ($cid > 0) {
                        $feedbackData = [
                            'cid' => $cid,
                            'coid' => (int)self::readObjectField($feedback, 'coid', 0),
                            'author' => (string)self::readObjectField($feedback, 'author', ''),
                            'text' => (string)self::readObjectField($feedback, 'text', ''),
                            'parent' => (int)self::readObjectField($feedback, 'parent', 0),
                            'mail' => (string)self::readObjectField($feedback, 'mail', ''),
                            'url' => (string)self::readObjectField($feedback, 'url', ''),
                            'status' => (string)self::readObjectField($feedback, 'status', ''),
                        ];
                    }
                }
            } elseif (is_array($feedback) && isset($feedback['cid'])) {
                $feedbackData = [
                    'cid' => (int)$feedback['cid'],
                    'coid' => (int)($feedback['coid'] ?? 0),
                    'author' => (string)($feedback['author'] ?? ''),
                    'text' => (string)($feedback['text'] ?? ''),
                    'parent' => (int)($feedback['parent'] ?? 0),
                    'mail' => (string)($feedback['mail'] ?? ''),
                    'url' => (string)($feedback['url'] ?? ''),
                    'status' => (string)($feedback['status'] ?? ''),
                ];
            }

            // 合并 pending 与 feedback，优先使用包含 coid 的数据
            if (!empty($commentData) && !empty($feedbackData)) {
                if (empty($commentData['coid']) && !empty($feedbackData['coid'])) {
                    $commentData['coid'] = $feedbackData['coid'];
                }
                if (empty($commentData['parent']) && isset($feedbackData['parent'])) {
                    $commentData['parent'] = $feedbackData['parent'];
                }
                if (empty($commentData['author']) && !empty($feedbackData['author'])) {
                    $commentData['author'] = $feedbackData['author'];
                }
                if (empty($commentData['text']) && !empty($feedbackData['text'])) {
                    $commentData['text'] = $feedbackData['text'];
                }
            } elseif (empty($commentData) && !empty($feedbackData)) {
                $commentData = $feedbackData;
            }

            // 清理静态变量
            self::$pendingComment = null;
            self::$pendingWidget = null;

            if (empty($commentData) || empty($commentData['cid'])) {
                return;
            }

            // 同一请求内可能被多个兼容钩子重复触发，按 coid 去重
            $coid = (int)($commentData['coid'] ?? 0);
            if ($coid > 0) {
                if (isset(self::$processedCoids[$coid])) {
                    AICover_Plugin::log('AI 回复跳过: 重复触发 coid=' . $coid, (int)($commentData['cid'] ?? 0));
                    return;
                }
                self::$processedCoids[$coid] = true;
            } else {
                $fingerprint = md5(
                    (string)($commentData['cid'] ?? 0) . '|' .
                    trim((string)($commentData['author'] ?? '')) . '|' .
                    trim((string)($commentData['text'] ?? ''))
                );
                if (isset(self::$processedFingerprints[$fingerprint])) {
                    AICover_Plugin::log('AI 回复跳过: 重复触发 fingerprint=' . $fingerprint, (int)($commentData['cid'] ?? 0));
                    return;
                }
                self::$processedFingerprints[$fingerprint] = true;
            }

            // 跳过 AI 生成的评论
            if ($coid > 0 && self::isAIGeneratedCommentByCoid($coid)) {
                return;
            }

            $cfg = Typecho_Widget::widget('Widget_Options')->plugin('AICover');
            // 运行时兜底建表，避免升级后未重启插件导致回复链路不可用
            AICover_Plugin::ensureReplyInfrastructure();

            if (($cfg->replyMode ?? 'off') === 'off') {
                return;
            }

            // auto 模式仅对已审核通过评论自动发布，避免“待审评论已被 AI 公开回复”
            if (($cfg->replyMode ?? 'off') === 'auto' && $coid > 0) {
                $commentStatus = trim((string)($commentData['status'] ?? ''));
                if ($commentStatus === '') {
                    $commentStatus = self::getCommentStatusByCoid($coid);
                    if ($commentStatus !== '') {
                        $commentData['status'] = $commentStatus;
                    }
                }
                if ($commentStatus === '' || strtolower($commentStatus) !== 'approved') {
                    AICover_Plugin::log('AI 回复跳过: 评论未审核通过 status=' . $commentStatus, (int)($commentData['cid'] ?? 0));
                    return;
                }
            }

            // 先入队（持久化保底）
            self::enqueueAsyncJob($commentData);
            AICover_Plugin::log('AI 回复入队完成', (int)($commentData['cid'] ?? 0));

            // 立即用 fire-and-forget 触发后台消费，无需任何页面刷新
            self::triggerBackgroundConsume((int)($commentData['cid'] ?? 0));

        } catch (Exception $e) {
            AICover_Plugin::log('AI 回复处理异常: ' . $e->getMessage());
        }
    }

    /**
     * 评论状态变更钩子（后台审核通过时触发）
     * 依据 Typecho 官方链路：Widget_Comments_Edit::mark -> pluginHandle()->call('mark', $comment, $this, $status)
     *
     * @param array|object $comment 原评论（变更前）
     * @param mixed $widget
     * @param string $status 目标状态
     */
    public static function onCommentMark($comment, $widget, $status)
    {
        try {
            // 仅在“标记为 approved”时触发自动回复链路
            if (strtolower(trim((string)$status)) !== 'approved') {
                return;
            }

            $commentData = null;
            if (is_array($comment) && isset($comment['cid'])) {
                $commentData = $comment;
            } elseif (is_object($comment)) {
                if (method_exists($comment, 'toArray')) {
                    $arr = $comment->toArray();
                    if (is_array($arr) && isset($arr['cid'])) {
                        $commentData = $arr;
                    }
                }
                if ($commentData === null) {
                    $cid = (int)self::readObjectField($comment, 'cid', 0);
                    if ($cid <= 0) {
                        return;
                    }
                    $commentData = [
                        'cid' => $cid,
                        'coid' => (int)self::readObjectField($comment, 'coid', 0),
                        'author' => (string)self::readObjectField($comment, 'author', ''),
                        'text' => (string)self::readObjectField($comment, 'text', ''),
                        'parent' => (int)self::readObjectField($comment, 'parent', 0),
                        'mail' => (string)self::readObjectField($comment, 'mail', ''),
                        'url' => (string)self::readObjectField($comment, 'url', ''),
                    ];
                }
            }

            if (empty($commentData) || empty($commentData['cid'])) {
                return;
            }

            // mark 钩子是“状态变更前”触发，这里显式覆盖为目标状态，供 auto 模式通过审核检查
            $commentData['status'] = 'approved';

            $coid = (int)($commentData['coid'] ?? 0);
            if ($coid > 0) {
                if (isset(self::$processedCoids[$coid])) {
                    return;
                }
                self::$processedCoids[$coid] = true;
                if (self::isAIGeneratedCommentByCoid($coid)) {
                    return;
                }
            }

            $cfg = Typecho_Widget::widget('Widget_Options')->plugin('AICover');
            if (($cfg->replyMode ?? 'off') === 'off') {
                return;
            }

            AICover_Plugin::ensureReplyInfrastructure();
            $result = AICover_Reply_ReplyGenerator::processComment($commentData, $cfg);
            if ($result['success']) {
                AICover_Plugin::log('AI 回复成功(mark): ' . $result['message'], (int)($commentData['cid'] ?? 0));
            } else {
                AICover_Plugin::log('AI 回复跳过(mark): ' . $result['message'], (int)($commentData['cid'] ?? 0));
            }
        } catch (Exception $e) {
            AICover_Plugin::log('AI 回复 mark 钩子异常: ' . $e->getMessage());
        }
    }

    /**
     * 评论输出钩子 - 添加 AI 标识
     * 在 Widget_Comments_Archive 输出评论时触发
     *
     * @param string $content 评论内容
     * @param object $widget 当前 Widget 实例
     * @return string 处理后的内容
     */
    private static $consumerJsInjected = false;

    public static function renderCommentBadge($content, $widget)
    {
        // 后台页面不注入 JS 也不加标识，避免污染后台 HTML/JSON 输出
        if (defined('__TYPECHO_ADMIN__') && __TYPECHO_ADMIN__) {
            return $content;
        }

        try {
            if (!self::$consumerJsInjected) {
                self::$consumerJsInjected = true;
                self::injectConsumerJs();
            }

            $cfg = Typecho_Widget::widget('Widget_Options')->plugin('AICover');

            // 检查是否开启 AI 标识显示
            if (!($cfg->replyShowBadge ?? '1')) {
                return $content;
            }

            // 获取当前评论的 coid
            $coid = self::getWidgetCoid($widget);
            if (!$coid) {
                return $content;
            }

            // 检查是否是 AI 生成的评论
            if (self::isAIGeneratedCommentByCoid($coid)) {
                $aiName = $cfg->replyAiName ?? 'AI助手';
                $badge = '<span class="aicover-ai-badge" title="此回复由 ' . htmlspecialchars($aiName) . ' 自动生成">🤖 ' . htmlspecialchars($aiName) . '</span>';
                $content = $badge . $content;
            }
        } catch (Exception $e) {
            AICover_Plugin::log('renderCommentBadge 异常: ' . $e->getMessage());
        }

        return $content;
    }

    /**
     * 注入前端 JS：立即调用 reply_consume，完成后若有新回复则自动刷新页面
     * 在评论区域被渲染时调用一次（PJAX 安全）
     */
    private static function injectConsumerJs()
    {
        // 后台不输出任何前端脚本
        if (defined('__TYPECHO_ADMIN__') && __TYPECHO_ADMIN__) {
            return;
        }

        try {
            $cfg = Typecho_Widget::widget('Widget_Options')->plugin('AICover');
            if (empty($cfg->replyMode) || $cfg->replyMode === 'off') {
                return;
            }
        } catch (Exception $e) {
            return;
        }

        // 获取当前文章 CID，让消费端点只处理本文的任务
        $cid = 0;
        try {
            $archive = Typecho_Widget::widget('Widget_Archive');
            if (is_object($archive) && method_exists($archive, 'is') && $archive->is('single')) {
                $cid = (int)$archive->cid;
            }
        } catch (Exception $e) {
            $cid = 0;
        }

        // CID 未知时不注入，避免误刷新其他页面
        if ($cid <= 0) {
            return;
        }

        $actionUrl = Typecho_Common::url('/action/aicover', Helper::options()->index);
        $flag = 'window.__aicover_cq_' . $cid;
        // 仅作非 PHP-FPM 环境的兜底静默消费，不刷新页面
        echo '<script>(function(){'
            . 'if(' . $flag . ')return;' . $flag . '=1;'
            . 'fetch("' . $actionUrl . '?do=reply_consume&cid=' . $cid . '",{credentials:"same-origin"})'
            . '.catch(function(){});'
            . '})()</script>';
    }

    /**
     * 通过 coid 检查是否是 AI 生成的评论
     *
     * @param int $coid 评论 ID
     * @return bool
     */
    private static function isAIGeneratedCommentByCoid($coid)
    {
        if ($coid <= 0) {
            return false;
        }

        try {
            $db = Typecho_Db::get();
            $row = $db->fetchRow(
                $db->select('str_value')
                    ->from('table.fields')
                    ->where('cid = ?', $coid)
                    ->where('name = ?', 'aicover_ai_generated')
                    ->limit(1)
            );

            return $row && $row['str_value'] === '1';

        } catch (Exception $e) {
            AICover_Plugin::log('检查 AI 生成评论异常: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 从 Widget 获取 coid
     *
     * @param object $widget
     * @return int
     */
    private static function getWidgetCoid($widget)
    {
        if (is_object($widget)) {
            $coid = (int)self::readObjectField($widget, 'coid', 0);
            if ($coid > 0) {
                return $coid;
            }
            if (method_exists($widget, 'coid')) {
                return (int)$widget->coid();
            }
        }
        return 0;
    }

    /**
     * 安全读取对象字段（兼容 Typecho Widget 魔术属性）
     */
    private static function readObjectField($object, $field, $default = null)
    {
        if (!is_object($object)) {
            return $default;
        }

        try {
            if (method_exists($object, 'toArray')) {
                $arr = $object->toArray();
                if (is_array($arr) && array_key_exists($field, $arr)) {
                    return $arr[$field];
                }
            }
        } catch (Exception $e) {
            // ignore
        }

        try {
            $value = $object->$field;
            return $value === null ? $default : $value;
        } catch (Exception $e) {
            return $default;
        }
    }

    /**
     * 向自身发起 fire-and-forget HTTP 请求，触发后台消费
     * 使用纯 PHP 核心函数（stream_socket_client），无需任何扩展
     * 服务端 reply_consume 持有 ignore_user_abort(true)，连接关闭后仍继续处理
     */
    private static function triggerBackgroundConsume($cid)
    {
        if ($cid <= 0) {
            return;
        }
        try {
            $options = Typecho_Widget::widget('Widget_Options');
            $siteUrl = rtrim((string)($options->siteUrl ?? ''), '/');
            if ($siteUrl === '') {
                return;
            }

            $p = parse_url($siteUrl . '/action/aicover?do=reply_consume&cid=' . $cid);
            $host = $p['host'] ?? 'localhost';
            $path = ($p['path'] ?? '/') . '?do=reply_consume&cid=' . $cid;
            $isSSL = ($p['scheme'] ?? 'http') === 'https';
            $port = isset($p['port']) ? (int)$p['port'] : ($isSSL ? 443 : 80);

            // 禁用 SSL 证书验证（自请求，证书主机名可能不匹配）
            $ctx = stream_context_create([
                'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
            ]);
            $fp = @stream_socket_client(
                ($isSSL ? 'ssl://' : 'tcp://') . $host . ':' . $port,
                $errno, $errstr, 1, STREAM_CLIENT_CONNECT, $ctx
            );
            if ($fp) {
                @fwrite($fp, "GET {$path} HTTP/1.0\r\nHost: {$host}\r\nConnection: close\r\n\r\n");
                @fclose($fp); // 立即关闭；服务端因 ignore_user_abort 继续跑
            }
        } catch (Exception $e) {
            AICover_Plugin::log('触发后台消费失败: ' . $e->getMessage());
        }
    }

    private static function enqueueAsyncJob($commentData)
    {
        AICover_Plugin::ensureReplyInfrastructure();

        $cid = (int)($commentData['cid'] ?? 0);
        $coid = (int)($commentData['coid'] ?? 0);
        try {
            $db = Typecho_Db::get();

            // 幂等：同一 coid/cid 若已有待处理任务，则不重复入队
            $existing = $db->fetchRow(
                $db->select('id')->from('table.aicover_reply_jobs')
                    ->where('coid = ?', $coid)
                    ->where('cid = ?', $cid)
                    ->where('status = ?', 'pending')
                    ->limit(1)
            );
            if ($existing) {
                return;
            }

            $db->query(
                $db->insert('table.aicover_reply_jobs')
                    ->rows([
                        'coid' => $coid,
                        'cid' => $cid,
                        'status' => 'pending',
                        'attempts' => 0,
                        'payload' => json_encode($commentData, JSON_UNESCAPED_UNICODE),
                        'created_at' => date('Y-m-d H:i:s'),
                    ])
            );
        } catch (Exception $e) {
            AICover_Plugin::log('AI 回复异步入队失败: ' . $e->getMessage(), $cid);
        }
    }

    /**
     * 消费队列任务，返回实际处理的任务数
     *
     * @param int $limit 最多处理几条
     * @param int $cid   仅处理指定文章的任务（0 = 不限制）
     * @return int 实际处理的任务数
     */
    public static function consumeQueuedJobs($limit = 1, $cid = 0)
    {
        $processed = 0;
        try {
            AICover_Plugin::ensureReplyInfrastructure();
            $db = Typecho_Db::get();
            $cfg = Typecho_Widget::widget('Widget_Options')->plugin('AICover');
            $now = date('Y-m-d H:i:s');

            for ($i = 0; $i < (int)$limit; $i++) {
                $query = $db->select()->from('table.aicover_reply_jobs')
                    ->where('status = ?', 'pending')
                    ->order('id', Typecho_Db::SORT_ASC)
                    ->limit(1);
                if ($cid > 0) {
                    $query = $query->where('cid = ?', $cid);
                }
                $job = $db->fetchRow($query);
                if (!$job) {
                    break;
                }

                $jobId = (int)($job['id'] ?? 0);
                if ($jobId <= 0) {
                    continue;
                }

                $claimed = $db->query(
                    $db->update('table.aicover_reply_jobs')
                        ->rows([
                            'status' => 'processing',
                            'attempts' => ((int)($job['attempts'] ?? 0)) + 1,
                            'started_at' => $now,
                        ])
                        ->where('id = ?', $jobId)
                        ->where('status = ?', 'pending')
                );
                if ((int)$claimed <= 0) {
                    continue;
                }

                $payload = json_decode((string)($job['payload'] ?? ''), true);
                if (!is_array($payload) || empty($payload['cid'])) {
                    $db->query(
                        $db->update('table.aicover_reply_jobs')
                            ->rows([
                                'status' => 'failed',
                                'processed_at' => date('Y-m-d H:i:s'),
                                'last_error' => 'invalid payload',
                            ])
                            ->where('id = ?', $jobId)
                    );
                    continue;
                }

                $timeout = (int)($cfg->replyRequestTimeout ?? 15);
                $cfg->replyRequestTimeout = max(5, min($timeout > 0 ? $timeout : 15, 15));

                $result = AICover_Reply_ReplyGenerator::processComment($payload, $cfg);
                $db->query(
                    $db->update('table.aicover_reply_jobs')
                        ->rows([
                            'status' => !empty($result['success']) ? 'done' : 'failed',
                            'processed_at' => date('Y-m-d H:i:s'),
                            'last_error' => !empty($result['success']) ? '' : (string)($result['message'] ?? 'failed'),
                        ])
                        ->where('id = ?', $jobId)
                );
                $processed++;
            }
        } catch (Exception $e) {
            AICover_Plugin::log('消费 AI 回复队列失败: ' . $e->getMessage());
        }
        return $processed;
    }

    /**
     * 通过 coid 查询评论状态
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
            return trim((string)($row['status'] ?? ''));
        } catch (Exception $e) {
            AICover_Plugin::log('查询评论状态失败: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * 激活插件时注册钩子
     */
    public static function registerHooks()
    {
        // 按官方 Hook 名称直接注册（新旧命名并挂，避免激活期 class_exists 判定导致未注册）
        Typecho_Plugin::factory('Widget_Feedback')->comment
            = array('AICover_Reply_HookHandler', 'onComment');
        Typecho_Plugin::factory('Widget_Feedback')->finishComment
            = array('AICover_Reply_HookHandler', 'onFinishComment');
        Typecho_Plugin::factory('Widget\\Feedback')->comment
            = array('AICover_Reply_HookHandler', 'onComment');
        Typecho_Plugin::factory('Widget\\Feedback')->finishComment
            = array('AICover_Reply_HookHandler', 'onFinishComment');

        Typecho_Plugin::factory('Widget_Comments_Edit')->finishComment
            = array('AICover_Reply_HookHandler', 'onFinishComment');
        Typecho_Plugin::factory('Widget_Comments_Edit')->mark
            = array('AICover_Reply_HookHandler', 'onCommentMark');
        Typecho_Plugin::factory('Widget\\Comments\\Edit')->finishComment
            = array('AICover_Reply_HookHandler', 'onFinishComment');
        Typecho_Plugin::factory('Widget\\Comments\\Edit')->mark
            = array('AICover_Reply_HookHandler', 'onCommentMark');

        Typecho_Plugin::factory('Widget_Abstract_Comments')->contentEx
            = array('AICover_Reply_HookHandler', 'renderCommentBadge');
        Typecho_Plugin::factory('Widget\\Abstract\\Comments')->contentEx
            = array('AICover_Reply_HookHandler', 'renderCommentBadge');
    }
}
