<?php
/**
 * AICover 后台封面管理面板
 * 
 * @author 小铁
 * @version 1.0.0
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

$db      = Typecho_Db::get();
$options = Typecho_Widget::widget('Widget_Options');
$cfg     = $options->plugin('AICover');
$siteUrl = rtrim($options->siteUrl, '/');
$actionUrl = Typecho_Common::url('/action/aicover', $options->index);
$pluginDir = Typecho_Common::url('/usr/plugins/AICover', $siteUrl);
$nonce   = AICover_Plugin::createNonce(0);
$compressEnabled = ((int)($cfg->enableCoverCompress ?? 1) === 1);
$compressRatio = (float)($cfg->compressRatio ?? 0.6);
$compressThresholdKB = (int)($cfg->compressThresholdKB ?? 500);

if (!function_exists('aicover_format_size')) {
    function aicover_format_size($bytes) {
        $bytes = (int)$bytes;
        if ($bytes <= 0) return '-';
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1024 * 1024) return round($bytes / 1024, 1) . ' KB';
        return round($bytes / 1024 / 1024, 2) . ' MB';
    }
}

// 分页
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;

// 搜索
$search = trim($_GET['search'] ?? '');

// 获取历史列表（关联文章标题）
try {
    $countQuery = $db->select('COUNT(1) AS cnt')
        ->from('table.aicover_history h')
        ->join('table.contents c', 'h.cid = c.cid', Typecho_Db::LEFT_JOIN);
    if ($search) {
        $countQuery->where('c.title LIKE ?', '%' . $search . '%');
    }
    $countRow = $db->fetchRow($countQuery);
    $total    = (int)($countRow['cnt'] ?? 0);

    $listQuery = $db->select(
            'h.id', 'h.cid', 'h.prompt', 'h.cover_path', 'h.created_at',
            'c.title AS post_title'
        )
        ->from('table.aicover_history h')
        ->join('table.contents c', 'h.cid = c.cid', Typecho_Db::LEFT_JOIN);

    if ($search) {
        $listQuery->where('c.title LIKE ?', '%' . $search . '%');
    }

    $history = $db->fetchAll(
        $listQuery->order('h.id', Typecho_Db::SORT_DESC)->offset($offset)->limit($perPage)
    );

} catch (Exception $e) {
    $total   = 0;
    $history = [];
}

$totalPages = ceil($total / $perPage);

include __TYPECHO_ROOT_DIR__ . '/admin/header.php';
include __TYPECHO_ROOT_DIR__ . '/admin/menu.php';
?>

<link rel="stylesheet" href="<?php echo $pluginDir; ?>/static/admin.css?v=1.0">
<div class="main-header-container">
    <div class="main-header">
        <h2 class="main-header-title">AI 封面管理</h2>
    </div>
</div>

<div class="main-container">
    <div class="main-content typecho-page-main" role="main">

        <!-- 统计卡片 -->
        <div class="aicover-admin-stats">
            <div class="aicover-stat-card">
                <div class="aicover-stat-num"><?php echo $total; ?></div>
                <div class="aicover-stat-label">历史封面</div>
            </div>
            <div class="aicover-stat-card">
                <div class="aicover-stat-num"><?php echo $cfg->imageSize ?? '1536x576'; ?></div>
                <div class="aicover-stat-label">图像尺寸</div>
            </div>
            <div class="aicover-stat-card">
                <div class="aicover-stat-num"><?php echo $compressEnabled ? 'ON' : 'OFF'; ?></div>
                <div class="aicover-stat-label">压缩配置：比例 <?php echo htmlspecialchars((string)$compressRatio); ?> / 阈值 <?php echo (int)$compressThresholdKB; ?>KB</div>
            </div>
            <div class="aicover-stat-card aicover-stat-card--action">
                <button type="button" class="aicover-btn aicover-btn--primary" id="admin-test-text">
                    测试文本 API
                </button>
                <button type="button" class="aicover-btn aicover-btn--secondary" id="admin-test-image">
                    测试图像 API
                </button>
            </div>
        </div>

        <!-- API 测试状态 -->
        <div id="admin-test-status" style="margin-bottom:16px;display:none;" class="typecho-notice typecho-notice-success"></div>

        <!-- 搜索 -->
        <form class="typecho-form" method="get">
            <input type="hidden" name="panel" value="AICover/Panel.php">
            <div class="typecho-form-item" style="display:flex;gap:8px;align-items:center;margin-bottom:16px;">
                <input type="text" name="search" class="text" value="<?php echo htmlspecialchars($search); ?>"
                    placeholder="搜索文章标题…" style="flex:1;max-width:300px;">
                <button type="submit" class="btn btn-s">搜索</button>
                <?php if ($search): ?>
                    <a href="?panel=AICover/Panel.php" class="btn btn-s">清除</a>
                <?php endif; ?>
            </div>
        </form>

        <!-- 封面列表 -->
        <div class="typecho-list-table">
            <table class="typecho-list-table">
                <thead>
                    <tr>
                        <th>封面预览</th>
                        <th>文章</th>
                        <th>Prompt</th>
                        <th>封面大小</th>
                        <th>生成时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($history)): ?>
                    <tr>
                        <td colspan="6" style="text-align:center;color:#aaa;padding:40px 0;">
                            <?php echo $search ? '没有找到匹配的记录' : '暂无生成历史，发布文章后将自动生成'; ?>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($history as $h): ?>
                    <tr>
                        <td style="width:120px;">
                            <?php
                            $imgUrl = $siteUrl . '/' . ltrim($h['cover_path'], '/');
                            ?>
                            <a href="<?php echo htmlspecialchars($imgUrl); ?>" target="_blank">
                                <img src="<?php echo htmlspecialchars($imgUrl); ?>"
                                    style="width:110px;height:62px;object-fit:cover;border-radius:4px;display:block;"
                                    alt="封面">
                            </a>
                        </td>
                        <td>
                            <?php if ($h['post_title']): ?>
                                <a href="<?php echo $siteUrl; ?>/admin/write-post.php?cid=<?php echo $h['cid']; ?>" target="_blank">
                                    <?php echo htmlspecialchars(mb_substr($h['post_title'], 0, 30)); ?>
                                </a>
                                <br><small style="color:#aaa;">CID: <?php echo $h['cid']; ?></small>
                            <?php else: ?>
                                <span style="color:#aaa;">已删除文章 (CID: <?php echo $h['cid']; ?>)</span>
                            <?php endif; ?>
                        </td>
                        <td style="max-width:300px;">
                            <div style="font-size:12px;color:#666;word-break:break-all;line-height:1.5;">
                                <?php echo htmlspecialchars(mb_substr($h['prompt'], 0, 120)); ?>
                                <?php if (mb_strlen($h['prompt']) > 120): ?>…<?php endif; ?>
                            </div>
                        </td>
                        <td style="white-space:nowrap;">
                            <?php
                            $coverFullPath = AICover_Plugin::toAbsolutePath($h['cover_path']);
                            $coverBytes = file_exists($coverFullPath) ? (int)@filesize($coverFullPath) : 0;
                            ?>
                            <span class="admin-cover-size"><?php echo aicover_format_size($coverBytes); ?></span>
                        </td>
                        <td style="white-space:nowrap;font-size:13px;color:#aaa;">
                            <?php echo date('Y-m-d H:i', strtotime($h['created_at'])); ?>
                        </td>
                        <td style="white-space:nowrap;">
                            <button type="button" class="btn btn-s admin-use-cover"
                                data-cid="<?php echo $h['cid']; ?>"
                                data-path="<?php echo htmlspecialchars($h['cover_path']); ?>"
                                data-nonce="<?php echo AICover_Plugin::createNonce((int)$h['cid']); ?>"
                                data-action="<?php echo $actionUrl; ?>">
                                设为封面
                            </button>
                            <button type="button" class="btn btn-s admin-compress-cover"
                                data-path="<?php echo htmlspecialchars($h['cover_path']); ?>"
                                data-action="<?php echo $actionUrl; ?>">
                                压缩
                            </button>
                            <button type="button" class="btn btn-s btn-danger admin-delete-cover"
                                data-id="<?php echo $h['id']; ?>"
                                data-nonce="<?php echo $nonce; ?>"
                                data-action="<?php echo $actionUrl; ?>">
                                删除
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- 分页 -->
        <?php if ($totalPages > 1): ?>
        <div class="typecho-post-pager" style="margin-top:16px;text-align:center;">
            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                <?php if ($p == $page): ?>
                    <strong style="margin:0 4px;"><?php echo $p; ?></strong>
                <?php else: ?>
                    <a href="?panel=AICover/Panel.php&page=<?php echo $p; ?>&search=<?php echo urlencode($search); ?>" style="margin:0 4px;"><?php echo $p; ?></a>
                <?php endif; ?>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
    </div><!-- .main-content -->
</div><!-- .main-container -->

<script>
(function() {
    const actionUrl = '<?php echo $actionUrl; ?>';
    const nonce = '<?php echo $nonce; ?>';

    async function apiPost(params) {
        const qs = new URLSearchParams(Object.assign({ nonce }, params));
        const res = await fetch(actionUrl + '?' + qs.toString(), { method: 'POST' });
        return res.json();
    }

    // 测试 API
    document.getElementById('admin-test-text')?.addEventListener('click', async function() {
        this.disabled = true;
        this.textContent = '测试中…';
        const status = document.getElementById('admin-test-status');
        try {
            const data = await apiPost({ do: 'test', type: 'text' });
            status.style.display = 'block';
            status.className = 'typecho-notice ' + (data.success ? 'typecho-notice-success' : 'typecho-notice-error');
            status.textContent = data.message || data.error;
        } catch(e) { status.style.display='block'; status.textContent='请求失败'; }
        this.disabled = false;
        this.textContent = '测试文本 API';
    });

    document.getElementById('admin-test-image')?.addEventListener('click', async function() {
        this.disabled = true;
        this.textContent = '测试中…';
        const status = document.getElementById('admin-test-status');
        try {
            const data = await apiPost({ do: 'test', type: 'image' });
            status.style.display = 'block';
            status.className = 'typecho-notice ' + (data.success ? 'typecho-notice-success' : 'typecho-notice-error');
            status.textContent = data.message || data.error;
        } catch(e) { status.style.display='block'; status.textContent='请求失败'; }
        this.disabled = false;
        this.textContent = '测试图像 API';
    });

    // 设为封面
    document.querySelectorAll('.admin-use-cover').forEach(btn => {
        btn.addEventListener('click', async function() {
            if (!confirm('确认将此图设为文章封面？')) return;
            this.disabled = true;
            const data = await apiPost({ do: 'use', cid: this.dataset.cid, path: this.dataset.path, nonce: this.dataset.nonce });
            alert(data.message || data.error);
            this.disabled = false;
        });
    });

    // 删除
    document.querySelectorAll('.admin-delete-cover').forEach(btn => {
        btn.addEventListener('click', async function() {
            if (!confirm('确认删除此封面？文件将被删除且不可恢复。')) return;
            const row = this.closest('tr');
            const data = await apiPost({ do: 'delete', id: this.dataset.id });
            if (data.success) {
                row.style.opacity = '0.3';
                row.style.transition = 'opacity .3s';
                setTimeout(() => row.remove(), 300);
            } else {
                alert(data.error);
            }
        });
    });

    // 压缩
    document.querySelectorAll('.admin-compress-cover').forEach(btn => {
        btn.addEventListener('click', async function() {
            this.disabled = true;
            const row = this.closest('tr');
            const data = await apiPost({ do: 'compress', path: this.dataset.path });
            if (data.success) {
                const sizeEl = row ? row.querySelector('.admin-cover-size') : null;
                if (sizeEl && typeof data.after === 'number') {
                    const after = Number(data.after);
                    sizeEl.textContent = after < 1024
                        ? `${after} B`
                        : (after < 1024 * 1024 ? `${(after / 1024).toFixed(1)} KB` : `${(after / 1024 / 1024).toFixed(2)} MB`);
                }
                alert(data.message + `（${data.before} -> ${data.after} bytes）`);
            } else {
                alert(data.error || '压缩失败');
            }
            this.disabled = false;
        });
    });
})();
</script>

<?php include __TYPECHO_ROOT_DIR__ . '/admin/footer.php'; ?>
