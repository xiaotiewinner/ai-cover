<?php
/**
 * AICover AI 回复管理后台
 *
 * @author 小铁
 * @version 1.1.0
 * @link https://www.xiaotiewinner.com/ai-cover
 */

if (!defined('__TYPECHO_ROOT_DIR__')) exit;

// 初始化
$user = Typecho_Widget::widget('Widget_User');
if (!$user->pass('administrator', true)) {
    Typecho_Widget::widget('Widget_Notice')->set(_t('您没有权限访问此页面'), 'error');
    Typecho_Response::redirect(Typecho_Widget::widget('Widget_Options')->siteUrl);
    exit;
}

$db = Typecho_Db::get();
$options = Typecho_Widget::widget('Widget_Options');
$cfg = $options->plugin('AICover');
AICover_Plugin::ensureReplyInfrastructure();
$siteUrl = rtrim($options->siteUrl, '/');
$actionUrl = Typecho_Common::url('/action/aicover', $options->index);
$pluginDir = Typecho_Common::url('/usr/plugins/AICover', $siteUrl);
$nonce = AICover_Plugin::createNonce(0);

// 获取当前标签页
$tab = $_GET['tab'] ?? 'queue';
$validTabs = ['queue', 'suggestions', 'stats'];
if (!in_array($tab, $validTabs)) {
    $tab = 'queue';
}

// 分页
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// 获取统计数据
$stats = AICover_Reply_RateLimiter::getStats();
$maxPerHour = (int)($cfg->replyMaxPerHour ?? 10);
$maxPerDay = (int)($cfg->replyMaxPerDay ?? 50);

// 获取队列数据
$queueCount = 0;
$queue = [];
if ($tab === 'queue') {
    $queueCountRow = $db->fetchRow(
        $db->select('COUNT(1) as cnt')->from('table.aicover_reply_queue')
    );
    $queueCount = (int)($queueCountRow['cnt'] ?? 0);

    $queue = $db->fetchAll(
        $db->select(
                'q.id', 'q.coid', 'q.cid', 'q.parent', 'q.author', 'q.text',
                'q.ai_reply', 'q.status', 'q.created_at',
                'c.title as post_title'
            )
            ->from('table.aicover_reply_queue q')
            ->join('table.contents c', 'q.cid = c.cid', Typecho_Db::LEFT_JOIN)
            ->order('q.id', Typecho_Db::SORT_DESC)
            ->offset($offset)
            ->limit($perPage)
    );
}

// 获取建议数据
$suggestionsCount = 0;
$suggestions = [];
if ($tab === 'suggestions') {
    $suggestionsCountRow = $db->fetchRow(
        $db->select('COUNT(1) as cnt')->from('table.aicover_reply_suggestions')
            ->where('is_used = ?', 0)
    );
    $suggestionsCount = (int)($suggestionsCountRow['cnt'] ?? 0);

    $suggestions = $db->fetchAll(
        $db->select(
                's.id', 's.coid', 's.cid', 's.suggestion_text', 's.created_at',
                'c.title as post_title', 'cm.author as comment_author', 'cm.text as comment_text'
            )
            ->from('table.aicover_reply_suggestions s')
            ->join('table.contents c', 's.cid = c.cid', Typecho_Db::LEFT_JOIN)
            ->join('table.comments cm', 's.coid = cm.coid', Typecho_Db::LEFT_JOIN)
            ->where('s.is_used = ?', 0)
            ->order('s.id', Typecho_Db::SORT_DESC)
            ->offset($offset)
            ->limit($perPage)
    );
}

// 获取历史统计
// 配置检查
$configErrors = [];
$configWarnings = [];

// 从 endpoint URL 自动识别提供商
function detectReplyProvider($endpoint) {
    $endpoint = strtolower($endpoint);
    if (strpos($endpoint, 'deepseek') !== false) return 'DeepSeek';
    if (strpos($endpoint, 'openai') !== false || strpos($endpoint, 'api.openai') !== false) return 'OpenAI';
    if (strpos($endpoint, 'qwen') !== false || strpos($endpoint, 'dashscope') !== false || strpos($endpoint, 'aliyun') !== false) return '通义千问';
    if (strpos($endpoint, 'kimi') !== false || strpos($endpoint, 'moonshot') !== false) return 'Kimi';
    if (strpos($endpoint, 'zhipu') !== false || strpos($endpoint, 'bigmodel') !== false) return '智谱AI';
    if (strpos($endpoint, 'anthropic') !== false) return 'Claude';
    if (strpos($endpoint, 'google') !== false || strpos($endpoint, 'gemini') !== false) return 'Gemini';
    return '自定义';
}

$detectedProvider = detectReplyProvider($cfg->replyEndpoint ?? '');

if ($cfg->replyMode !== 'off') {
    // 检查 API Key
    if (empty($cfg->replyApiKey)) {
        $configErrors[] = $detectedProvider . ' API Key 未配置';
    }

    // 提醒回复模型配置，不再误报 textApiKey（上下文来自本地数据库）
    if (empty($cfg->replyModel)) {
        $configWarnings[] = 'AI 回复模型未配置，自动回复将无法生成内容';
    }

    if (AICover_Plugin::isWeakReplySecret($cfg)) {
        $configWarnings[] = '隐私密钥仍为默认弱值（或为空），建议到插件设置中自定义 replySecret';
    }
}

$totalCount = 0;
$approvedCount = 0;
$rejectedCount = 0;
if ($tab === 'stats') {
    $totalRow = $db->fetchRow($db->select('COUNT(1) as cnt')->from('table.aicover_reply_queue'));
    $totalCount = (int)($totalRow['cnt'] ?? 0);

    $approvedRow = $db->fetchRow(
        $db->select('COUNT(1) as cnt')->from('table.aicover_reply_queue')
            ->where('status = ?', 'approved')
    );
    $approvedCount = (int)($approvedRow['cnt'] ?? 0);

    $rejectedRow = $db->fetchRow(
        $db->select('COUNT(1) as cnt')->from('table.aicover_reply_queue')
            ->where('status = ?', 'rejected')
    );
    $rejectedCount = (int)($rejectedRow['cnt'] ?? 0);
}

include __TYPECHO_ROOT_DIR__ . '/admin/header.php';
include __TYPECHO_ROOT_DIR__ . '/admin/menu.php';
?>

<link rel="stylesheet" href="<?php echo $pluginDir; ?>/static/reply-admin.css?v=1.1">

<div class="main-header-container">
    <div class="main-header">
        <h2 class="main-header-title">AI 评论回复管理</h2>
    </div>
</div>

<div class="main-container">
    <div class="main-content typecho-page-main" role="main">

        <!-- 统计卡片 -->
        <div class="aicover-reply-stats">
            <div class="aicover-reply-stat-card">
                <div class="aicover-reply-stat-num"><?php echo $stats['hour']; ?>/<?php echo $maxPerHour ?: '∞'; ?></div>
                <div class="aicover-reply-stat-label">近1小时生成数</div>
            </div>
            <div class="aicover-reply-stat-card">
                <div class="aicover-reply-stat-num"><?php echo $stats['day']; ?>/<?php echo $maxPerDay ?: '∞'; ?></div>
                <div class="aicover-reply-stat-label">近24小时生成数</div>
            </div>
            <div class="aicover-reply-stat-card">
                <div class="aicover-reply-stat-num"><?php echo $cfg->replyMode === 'off' ? '关闭' : ($cfg->replyMode === 'auto' ? '自动' : ($cfg->replyMode === 'manual' ? '审核' : '建议')); ?></div>
                <div class="aicover-reply-stat-label">工作模式</div>
            </div>
            <div class="aicover-reply-stat-card">
                <div class="aicover-reply-stat-num"><?php echo htmlspecialchars($detectedProvider); ?></div>
                <div class="aicover-reply-stat-label">当前提供商</div>
            </div>
            <div class="aicover-reply-stat-card aicover-reply-stat-card--action">
                <button type="button" class="btn btn-s" id="test-reply-api">测试 API 连接</button>
            </div>
        </div>

        <!-- 配置错误提示 -->
        <?php if (!empty($configErrors)): ?>
        <div class="typecho-notice typecho-notice-error" style="margin-bottom: 16px;">
            <strong>配置错误：</strong>
            <ul style="margin: 8px 0; padding-left: 20px;">
                <?php foreach ($configErrors as $error): ?>
                <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
            <a href="<?php echo $siteUrl; ?>/admin/options-plugin.php?config=AICover" class="btn btn-s">前往配置</a>
        </div>
        <?php endif; ?>

        <!-- 配置警告提示 -->
        <?php if (!empty($configWarnings)): ?>
        <div class="typecho-notice typecho-notice-warning" style="margin-bottom: 16px;">
            <strong>配置警告：</strong>
            <ul style="margin: 8px 0; padding-left: 20px;">
                <?php foreach ($configWarnings as $warning): ?>
                <li><?php echo htmlspecialchars($warning); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <!-- API 测试状态 -->
        <div id="api-test-status" style="margin-bottom:16px;display:none;" class="typecho-notice"></div>

        <!-- 标签页 -->
        <div class="typecho-tabs">
            <a href="?panel=AICover/ReplyAdmin.php&tab=queue"
               class="<?php echo $tab === 'queue' ? 'current' : ''; ?>">审核队列 (<?php echo $queueCount; ?>)</a>
            <a href="?panel=AICover/ReplyAdmin.php&tab=suggestions"
               class="<?php echo $tab === 'suggestions' ? 'current' : ''; ?>">回复建议 (<?php echo $suggestionsCount; ?>)</a>
            <a href="?panel=AICover/ReplyAdmin.php&tab=stats"
               class="<?php echo $tab === 'stats' ? 'current' : ''; ?>">统计</a>
        </div>

        <?php if ($tab === 'queue'): ?>
        <!-- 审核队列 -->
        <div class="typecho-list-table">
            <table class="typecho-list-table">
                <thead>
                    <tr>
                        <th>文章</th>
                        <th>评论作者</th>
                        <th>评论内容</th>
                        <th>AI 回复</th>
                        <th>状态</th>
                        <th>生成时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($queue)): ?>
                    <tr>
                        <td colspan="7" style="text-align:center;color:#999;padding:40px;">
                            暂无待审核的回复
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($queue as $item): ?>
                    <tr data-id="<?php echo htmlspecialchars($item['id'], ENT_QUOTES); ?>">
                        <td>
                            <a href="<?php echo $siteUrl; ?>/admin/write-post.php?cid=<?php echo $item['cid']; ?>" target="_blank">
                                <?php echo htmlspecialchars(mb_substr($item['post_title'] ?? '(未知文章)', 0, 20)); ?>
                            </a>
                        </td>
                        <td><?php echo htmlspecialchars($item['author']); ?></td>
                        <td>
                            <div class="comment-preview"><?php echo htmlspecialchars(mb_substr(strip_tags($item['text']), 0, 50)); ?>...</div>
                        </td>
                        <td>
                            <div class="ai-reply-preview" data-reply="<?php echo htmlspecialchars($item['ai_reply']); ?>">
                                <?php echo htmlspecialchars(mb_substr($item['ai_reply'], 0, 50)); ?>...</div>
                        </td>
                        <td>
                            <span class="status-badge status-<?php echo $item['status']; ?>">
                                <?php
                                $statusLabels = [
                                    'pending' => '待审核',
                                    'approved' => '已通过',
                                    'rejected' => '已拒绝'
                                ];
                                echo $statusLabels[$item['status']] ?? $item['status'];
                                ?>
                            </span>
                        </td>
                        <td><?php echo date('m-d H:i', strtotime($item['created_at'])); ?></td>
                        <td>
                            <?php if ($item['status'] === 'pending'): ?>
                            <button type="button" class="btn btn-s btn-primary approve-btn" data-id="<?php echo htmlspecialchars($item['id'], ENT_QUOTES); ?>">通过</button>
                            <button type="button" class="btn btn-s reject-btn" data-id="<?php echo htmlspecialchars($item['id'], ENT_QUOTES); ?>">拒绝</button>
                            <button type="button" class="btn btn-s regenerate-btn" data-id="<?php echo htmlspecialchars($item['id'], ENT_QUOTES); ?>">重新生成</button>
                            <?php else: ?>
                            <span class="text-muted">已处理</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if ($tab === 'suggestions'): ?>
        <!-- 建议列表 -->
        <div class="typecho-list-table">
            <table class="typecho-list-table">
                <thead>
                    <tr>
                        <th>文章</th>
                        <th>评论者</th>
                        <th>评论内容</th>
                        <th>AI 建议</th>
                        <th>生成时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($suggestions)): ?>
                    <tr>
                        <td colspan="6" style="text-align:center;color:#999;padding:40px;">
                            暂无回复建议
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($suggestions as $item): ?>
                    <tr data-id="<?php echo htmlspecialchars($item['id'], ENT_QUOTES); ?>">
                        <td>
                            <a href="<?php echo $siteUrl; ?>/admin/write-post.php?cid=<?php echo $item['cid']; ?>" target="_blank">
                                <?php echo htmlspecialchars(mb_substr($item['post_title'] ?? '(未知文章)', 0, 20)); ?>
                            </a>
                        </td>
                        <td><?php echo htmlspecialchars($item['comment_author'] ?? '(未知)'); ?></td>
                        <td>
                            <div class="comment-preview"><?php echo htmlspecialchars(mb_substr(strip_tags($item['comment_text'] ?? ''), 0, 50)); ?>...</div>
                        </td>
                        <td>
                            <div class="ai-reply-preview"><?php echo htmlspecialchars(mb_substr($item['suggestion_text'], 0, 50)); ?>...</div>
                        </td>
                        <td><?php echo date('m-d H:i', strtotime($item['created_at'])); ?></td>
                        <td>
                            <button type="button" class="btn btn-s btn-primary use-suggestion-btn"
                                    data-id="<?php echo htmlspecialchars($item['id'], ENT_QUOTES); ?>"
                                    data-coid="<?php echo htmlspecialchars($item['coid'], ENT_QUOTES); ?>"
                                    data-cid="<?php echo htmlspecialchars($item['cid'], ENT_QUOTES); ?>">使用</button>
                            <button type="button" class="btn btn-s delete-suggestion-btn" data-id="<?php echo htmlspecialchars($item['id'], ENT_QUOTES); ?>">删除</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if ($tab === 'stats'): ?>
        <!-- 统计信息 -->
        <div class="typecho-form">
            <div class="stats-grid">
                <div class="stats-card">
                    <div class="stats-number"><?php echo $totalCount; ?></div>
                    <div class="stats-label">总回复数</div>
                </div>
                <div class="stats-card">
                    <div class="stats-number"><?php echo $approvedCount; ?></div>
                    <div class="stats-label">已通过</div>
                </div>
                <div class="stats-card">
                    <div class="stats-number"><?php echo $rejectedCount; ?></div>
                    <div class="stats-label">已拒绝</div>
                </div>
                <div class="stats-card">
                    <div class="stats-number"><?php echo $stats['hour']; ?></div>
                    <div class="stats-label">近1小时</div>
                </div>
            </div>

            <h3>配置信息</h3>
            <div class="config-info">
                <div class="config-row">
                    <span class="config-label">工作模式：</span>
                    <span class="config-value">
                        <?php
                        $modeLabels = [
                            'off' => '关闭',
                            'auto' => '自动回复',
                            'manual' => '人工审核',
                            'suggest' => '仅建议'
                        ];
                        echo $modeLabels[$cfg->replyMode ?? 'off'];
                        ?>
                    </span>
                </div>
                <div class="config-row">
                    <span class="config-label">AI 提供商：</span>
                    <span class="config-value"><?php echo htmlspecialchars($detectedProvider); ?></span>
                </div>
                <div class="config-row">
                    <span class="config-label">每小时限制：</span>
                    <span class="config-value"><?php echo $maxPerHour ?: '无限制'; ?></span>
                </div>
                <div class="config-row">
                    <span class="config-label">每日限制：</span>
                    <span class="config-value"><?php echo $maxPerDay ?: '无限制'; ?></span>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<script>
(function() {
    const actionUrl = '<?php echo $actionUrl; ?>';
    const nonce = '<?php echo $nonce; ?>';

    async function apiPost(action, params = {}) {
        params.nonce = nonce;
        const qs = new URLSearchParams({do: action, ...params});
        const res = await fetch(actionUrl + '?' + qs.toString(), {method: 'POST'});
        const text = await res.text();
        let data = null;
        try {
            data = JSON.parse(text);
        } catch (e) {
            throw new Error('服务端返回非 JSON 响应：' + text.substring(0, 120));
        }
        if (!res.ok) {
            throw new Error(data.error || data.message || ('HTTP ' + res.status));
        }
        return data;
    }

    // 测试 API
    document.getElementById('test-reply-api')?.addEventListener('click', async function() {
        this.disabled = true;
        const statusEl = document.getElementById('api-test-status');
        statusEl.style.display = 'block';
        statusEl.className = 'typecho-notice';
        statusEl.textContent = '测试中...';

        try {
            const data = await apiPost('reply_test');
            statusEl.className = 'typecho-notice typecho-notice-success';
            statusEl.textContent = data.message + (data.reply ? ' (回复: ' + data.reply + ')' : '');
        } catch(e) {
            statusEl.className = 'typecho-notice typecho-notice-error';
            statusEl.textContent = e?.message || '测试失败';
        }
        this.disabled = false;
    });

    // 通过审核
    document.querySelectorAll('.approve-btn').forEach(btn => {
        btn.addEventListener('click', async function() {
            if (!confirm('确认通过此回复？')) return;
            this.disabled = true;
            const id = this.dataset.id;
            const row = this.closest('tr');

            try {
                const data = await apiPost('reply_approve', {id});
                if (data.success) {
                    row.style.opacity = '0.5';
                    row.querySelector('.status-badge').className = 'status-badge status-approved';
                    row.querySelector('.status-badge').textContent = '已通过';
                    row.querySelector('td:last-child').innerHTML = '<span class="text-muted">已处理</span>';
                } else {
                    alert(data.error || '操作失败');
                    this.disabled = false;
                }
            } catch(e) {
                alert(e?.message || '请求失败');
                this.disabled = false;
            }
        });
    });

    // 拒绝审核
    document.querySelectorAll('.reject-btn').forEach(btn => {
        btn.addEventListener('click', async function() {
            if (!confirm('确认拒绝此回复？')) return;
            this.disabled = true;
            const id = this.dataset.id;
            const row = this.closest('tr');

            try {
                const data = await apiPost('reply_reject', {id});
                if (data.success) {
                    row.style.opacity = '0.5';
                    row.querySelector('.status-badge').className = 'status-badge status-rejected';
                    row.querySelector('.status-badge').textContent = '已拒绝';
                    row.querySelector('td:last-child').innerHTML = '<span class="text-muted">已处理</span>';
                } else {
                    alert(data.error || '操作失败');
                    this.disabled = false;
                }
            } catch(e) {
                alert(e?.message || '请求失败');
                this.disabled = false;
            }
        });
    });

    // 重新生成
    document.querySelectorAll('.regenerate-btn').forEach(btn => {
        btn.addEventListener('click', async function() {
            if (!confirm('确认重新生成此回复？')) return;
            this.disabled = true;
            const id = this.dataset.id;

            try {
                const data = await apiPost('reply_regenerate', {id});
                if (data.success) {
                    alert('已重新生成: ' + data.data.reply.substring(0, 50) + '...');
                    location.reload();
                } else {
                    alert(data.error || '操作失败');
                    this.disabled = false;
                }
            } catch(e) {
                alert(e?.message || '请求失败');
                this.disabled = false;
            }
        });
    });

    // 使用建议
    document.querySelectorAll('.use-suggestion-btn').forEach(btn => {
        btn.addEventListener('click', async function() {
            if (!confirm('确认使用此建议作为回复？')) return;
            this.disabled = true;
            const id = this.dataset.id;
            const coid = this.dataset.coid;
            const cid = this.dataset.cid;
            const row = this.closest('tr');

            try {
                const data = await apiPost('reply_use_suggestion', {id, coid, cid});
                if (data.success) {
                    row.remove();
                } else {
                    alert(data.error || '操作失败');
                    this.disabled = false;
                }
            } catch(e) {
                alert(e?.message || '请求失败');
                this.disabled = false;
            }
        });
    });

    // 删除建议
    document.querySelectorAll('.delete-suggestion-btn').forEach(btn => {
        btn.addEventListener('click', async function() {
            if (!confirm('确认删除此建议？')) return;
            this.disabled = true;
            const id = this.dataset.id;
            const row = this.closest('tr');

            try {
                const data = await apiPost('reply_delete_suggestion', {id});
                if (data.success) {
                    row.remove();
                } else {
                    alert(data.error || '操作失败');
                    this.disabled = false;
                }
            } catch(e) {
                alert(e?.message || '请求失败');
                this.disabled = false;
            }
        });
    });
})();
</script>

<?php include __TYPECHO_ROOT_DIR__ . '/admin/footer.php'; ?>
