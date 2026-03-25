<?php
/**
 * AICover AJAX 动作处理器
 * 处理后台所有 AJAX 请求：封面生成、摘要生成、标题建议、Prompt 预览、封面切换
 * 
 * @author 小铁
 * @version 1.0.0
 * @link https://www.xiaotiewinner.com/ai-cover
 */

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class AICover_Action extends Typecho_Widget implements Widget_Interface_Do
{
    public function action()
    {
        $do = trim((string)$this->request->get('do', ''));

        switch ($do) {
            case 'cover':
                $this->generateCover();
                return;
            case 'summary':
                $this->generateSummary();
                return;
            case 'title':
                $this->generateTitles();
                return;
            case 'prompt':
                $this->previewPrompt();
                return;
            case 'og':
                $this->generateOG();
                return;
            case 'use':
                $this->useCover();
                return;
            case 'delete':
                $this->deleteCover();
                return;
            case 'compress':
                $this->compressCover();
                return;
            case 'test':
                $this->testApiConnection();
                return;
            default:
                $this->jsonError('未知操作: ' . $do, 400);
        }
    }

    private function json($data, $status = 200)
    {
        if (method_exists($this->response, 'setStatus')) {
            $this->response->setStatus($status);
        }
        if (method_exists($this->response, 'setContentType')) {
            $this->response->setContentType('application/json');
        } else {
            header('Content-Type: application/json; charset=UTF-8', true, $status);
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    private function jsonError($message, $status = 400)
    {
        $this->json(['success' => false, 'error' => $message], $status);
    }

    // ─── 权限验证 ─────────────────────────────────────────────────────

    private function checkAuth($cid = 0)
    {
        $user = Typecho_Widget::widget('Widget_User');
        // 需登录
        if (!$user || !$user->hasLogin()) {
            $this->jsonError('请先登录', 401);
        }
        // 需编辑权限
        if (!$user->pass('editor', true)) {
            $this->jsonError('权限不足', 403);
        }
        // CSRF 防护
        $nonce = trim((string)$this->request->get('nonce', ''));
        if (!AICover_Plugin::verifyNonce($nonce, (int)$cid)) {
            $this->jsonError('请求已过期或非法，请刷新页面后重试', 403);
        }
    }

    private function getCid()
    {
        $cid = (int)$this->request->get('cid', 0);
        if (!$cid) {
            $this->jsonError('缺少 cid 参数', 400);
        }
        return $cid;
    }

    private function getOptions()
    {
        return Typecho_Widget::widget('Widget_Options')->plugin('AICover');
    }

    private function getPostData($cid)
    {
        $db  = Typecho_Db::get();
        $row = $db->fetchRow(
            $db->select()->from('table.contents')->where('cid = ?', $cid)
        );
        if (!$row) {
            $this->jsonError('文章不存在', 404);
        }
        return $row;
    }

    // ─── 生成封面 ─────────────────────────────────────────────────────

    public function generateCover()
    {
        $cid    = $this->getCid();
        $this->checkAuth($cid);
        $cfg    = $this->getOptions();
        $post   = $this->getPostData($cid);

        $customPrompt = trim($this->request->get('prompt', ''));

        try {
            $title = strip_tags($post['title'] ?? '');
            $body  = strip_tags($post['text']  ?? '');
            $body  = mb_substr($body, 0, 800);

            // 构建 Prompt
            if (!empty($customPrompt)) {
                $suffix = trim($cfg->defaultPrompt ?? '');
                $prompt = $customPrompt . ($suffix ? ", $suffix" : '');
            } else {
                $prompt = AICover_Plugin::buildImagePrompt($title, $body, $cfg);
            }

            // 调用图像 API
            $imageData = AICover_Plugin::callImageAPI($prompt, $cfg);
            if (!$imageData) {
                $reason = AICover_Plugin::getLastError();
                $this->jsonError('图像 API 调用失败: ' . ($reason !== '' ? $reason : '请检查 API Key 和网络'));
            }

            // 保存图片
            $coverPath = AICover_Plugin::saveImageFile($imageData, $cid, $cfg);
            if (!$coverPath) {
                $reason = AICover_Plugin::getLastError();
                $this->jsonError('图像保存失败: ' . ($reason !== '' ? $reason : '未知错误'));
            }

            // 更新文章封面
            AICover_Plugin::saveCoverToPost($cid, $coverPath);

            // 手动模式：封面按钮默认不自动生成 OG
            $ogPath = '';
            $withOg = (int)$this->request->get('withOg', 0);
            if ($withOg === 1) {
                $ogPath = AICover_Plugin::generateOGImage($coverPath, $title, $cid, $cfg);
            }

            // 记录历史
            AICover_Plugin::logHistory($cid, $prompt, $coverPath);

            $options  = Typecho_Widget::widget('Widget_Options');
            $siteUrl  = rtrim($options->siteUrl, '/');

            $coverUrl = $siteUrl . '/' . ltrim($coverPath, '/');
            $ogUrl    = $ogPath  ? $siteUrl . '/' . ltrim($ogPath, '/') : '';

            $this->json([
                'success'    => true,
                'coverPath'  => $coverPath,
                'coverUrl'   => $coverUrl,
                'ogUrl'      => $ogUrl,
                'prompt'     => $prompt,
                'message'    => '封面生成成功！',
            ]);

        } catch (Exception $e) {
            AICover_Plugin::log('generateCover 异常: ' . $e->getMessage(), $cid);
            $this->jsonError('生成失败: ' . $e->getMessage(), 500);
        }
    }

    // ─── 生成摘要 ─────────────────────────────────────────────────────

    public function generateSummary()
    {
        $cid  = $this->getCid();
        $this->checkAuth($cid);
        $cfg  = $this->getOptions();
        $post = $this->getPostData($cid);

        if (empty($cfg->textApiKey)) {
            $this->jsonError('请先配置文本 AI API Key');
        }

        $title = strip_tags($post['title'] ?? '');
        $body  = strip_tags($post['text']  ?? '');
        $body  = mb_substr($body, 0, 1500);

        $sysMsg  = '你是一位专业博客编辑，请为以下文章生成一段简洁的中文摘要，100字以内，不要包含"本文"、"作者"等冗余词，直接陈述核心内容。只返回摘要文本，不要其他内容。';
        $userMsg = "标题: {$title}\n\n正文节选:\n{$body}";

        $summary = AICover_Plugin::callTextAPI($sysMsg, $userMsg, $cfg, 200);
        if (!$summary) {
            $this->jsonError('文本 API 调用失败');
        }

        $summary = trim($summary);

        // 保存到自定义字段
        AICover_Plugin::saveMeta($cid, 'customSummary', $summary);

        $this->json([
            'success' => true,
            'summary' => $summary,
            'message' => '摘要生成成功',
        ]);
    }

    // ─── 标题建议 ─────────────────────────────────────────────────────

    public function generateTitles()
    {
        $cid  = $this->getCid();
        $this->checkAuth($cid);
        $cfg  = $this->getOptions();
        $post = $this->getPostData($cid);

        if (empty($cfg->textApiKey)) {
            $this->jsonError('请先配置文本 AI API Key');
        }

        $body = strip_tags($post['text'] ?? '');
        $body = mb_substr($body, 0, 800);

        $sysMsg  = '请为以下博客文章正文生成5个吸引人的中文标题建议，风格简洁有力、有吸引力，每行一个，不要编号、序号或符号，只返回标题文本，每个标题单独一行。';
        $userMsg = "文章正文节选:\n{$body}";

        $result = AICover_Plugin::callTextAPI($sysMsg, $userMsg, $cfg, 300);
        if (!$result) {
            $this->jsonError('文本 API 调用失败');
        }

        $titles = array_values(array_filter(
            array_map('trim', explode("\n", $result))
        ));

        $this->json([
            'success' => true,
            'titles'  => $titles,
        ]);
    }

    // ─── 预览 Prompt ──────────────────────────────────────────────────

    public function previewPrompt()
    {
        $cid  = $this->getCid();
        $this->checkAuth($cid);
        $cfg  = $this->getOptions();
        $post = $this->getPostData($cid);

        $customPrompt = trim($this->request->get('prompt', ''));

        $title = strip_tags($post['title'] ?? '');
        $body  = strip_tags($post['text']  ?? '');
        $body  = mb_substr($body, 0, 800);

        if (!empty($customPrompt)) {
            $suffix = trim($cfg->defaultPrompt ?? '');
            $prompt = $customPrompt . ($suffix ? ", $suffix" : '');
        } else {
            $prompt = AICover_Plugin::buildImagePrompt($title, $body, $cfg);
        }

        $this->json([
            'success' => true,
            'prompt'  => $prompt,
        ]);
    }

    // ─── 生成 OG 分享图 ────────────────────────────────────────────────

    public function generateOG()
    {
        $cid  = $this->getCid();
        $this->checkAuth($cid);
        $cfg  = $this->getOptions();
        $post = $this->getPostData($cid);

        $coverPath = AICover_Plugin::getMeta($cid, 'thumb');
        if (empty($coverPath)) {
            $this->jsonError('请先生成或设置头图后再生成 OG 图');
        }

        $title = trim(strip_tags($post['title'] ?? ''));
        if ($title === '') {
            $this->jsonError('文章标题为空，无法生成 OG 图');
        }

        $ogPath = AICover_Plugin::generateOGImage($coverPath, $title, $cid, $cfg);
        if (!$ogPath) {
            $reason = AICover_Plugin::getLastError();
            $this->jsonError('OG 生成失败' . ($reason ? ': ' . $reason : ''));
        }

        $options = Typecho_Widget::widget('Widget_Options');
        $siteUrl = rtrim($options->siteUrl, '/');
        $ogUrl   = $siteUrl . '/' . ltrim($ogPath, '/');

        $this->json([
            'success' => true,
            'ogPath'  => $ogPath,
            'ogUrl'   => $ogUrl,
            'message' => 'OG 分享图生成成功',
        ]);
    }

    // ─── 使用历史封面 ─────────────────────────────────────────────────

    public function useCover()
    {
        $cid  = $this->getCid();
        $this->checkAuth($cid);
        $cfg  = $this->getOptions();
        $path = trim($this->request->get('path', ''));

        if (empty($path)) {
            $this->jsonError('缺少 path 参数');
        }
        $path = AICover_Plugin::normalizeRelativePath($path);
        if (!AICover_Plugin::isAllowedCoverPath($path, $cfg)) {
            $this->jsonError('封面路径不合法');
        }

        AICover_Plugin::saveCoverToPost($cid, $path);

        $options  = Typecho_Widget::widget('Widget_Options');
        $siteUrl  = rtrim($options->siteUrl, '/');
        $coverUrl = $siteUrl . '/' . ltrim($path, '/');

        $this->json([
            'success'   => true,
            'coverUrl'  => $coverUrl,
            'message'   => '封面已切换',
        ]);
    }

    // ─── 删除历史封面 ─────────────────────────────────────────────────

    public function deleteCover()
    {
        $this->checkAuth(0);
        $id = (int)$this->request->get('id', 0);

        if (!$id) {
            $this->jsonError('缺少 id 参数');
        }

        $db  = Typecho_Db::get();
        $row = $db->fetchRow(
            $db->select()->from('table.aicover_history')->where('id = ?', $id)
        );

        if (!$row) {
            $this->jsonError('记录不存在', 404);
        }

        $cfg = $this->getOptions();
        if (!AICover_Plugin::isAllowedCoverPath($row['cover_path'], $cfg)) {
            $this->jsonError('记录路径不合法，已拒绝删除', 403);
        }

        // 删除文件
        $fullPath = AICover_Plugin::toAbsolutePath($row['cover_path']);
        if (file_exists($fullPath)) {
            @unlink($fullPath);
        }

        // 删除 OG 图
        $dir  = dirname($fullPath);
        $base = pathinfo($fullPath, PATHINFO_FILENAME);
        foreach (['webp', 'jpg'] as $ext) {
            $ogPath = $dir . '/' . $base . '_og.' . $ext;
            if (file_exists($ogPath)) {
                @unlink($ogPath);
            }
        }

        $db->query($db->delete('table.aicover_history')->where('id = ?', $id));

        $this->json(['success' => true, 'message' => '已删除']);
    }

    public function compressCover()
    {
        $this->checkAuth(0);
        $cfg  = $this->getOptions();
        $path = trim((string)$this->request->get('path', ''));

        if ($path === '') {
            $this->jsonError('缺少 path 参数');
        }
        $path = AICover_Plugin::normalizeRelativePath($path);
        if (!AICover_Plugin::isAllowedCoverPath($path, $cfg)) {
            $this->jsonError('封面路径不合法');
        }

        $result = AICover_Plugin::compressCoverByPath($path, $cfg, true, 0, false);
        if ($result === false) {
            $reason = AICover_Plugin::getLastError();
            $this->jsonError('压缩失败' . ($reason ? ': ' . $reason : ''));
        }

        $before = (int)($result['before'] ?? 0);
        $after  = (int)($result['after'] ?? $before);
        $saved  = max(0, $before - $after);
        $this->json([
            'success'    => true,
            'compressed' => !empty($result['compressed']),
            'before'     => $before,
            'after'      => $after,
            'saved'      => $saved,
            'message'    => !empty($result['compressed'])
                ? '压缩成功'
                : '未压缩（可能已是最优大小）',
        ]);
    }

    // ─── 测试 API 连接 ────────────────────────────────────────────────

    public function testApiConnection()
    {
        $this->checkAuth(0);
        $cfg  = $this->getOptions();
        $type = $this->request->get('type', 'text'); // text | image

        if ($type === 'text') {
            if (empty($cfg->textApiKey)) {
                $this->jsonError('文本 API Key 未配置');
            }
            $result = AICover_Plugin::callTextAPI(
                'You are a test assistant.',
                'Reply with just the word: OK',
                $cfg, 10
            );
            if ($result !== false && trim((string)$result) !== '') {
                $this->json(['success' => true, 'message' => '文本 API 连接正常 ✓']);
            } else {
                $reason = AICover_Plugin::getLastError();
                $this->jsonError('文本 API 返回异常: ' . ($reason !== '' ? $reason : '空响应'));
            }
        } else {
            if (empty($cfg->apiKey)) {
                $this->jsonError('图像 API Key 未配置');
            }
            $provider = AICover_Plugin::resolveImageProvider($cfg);
            $probeName = $provider;
            $headers  = ['Authorization: Bearer ' . $cfg->apiKey];

            if ($provider === 'wanx') {
                $endpoint = 'https://dashscope.aliyuncs.com/compatible-mode/v1/models?limit=1';
            } elseif ($provider === 'zhipu') {
                $endpoint = 'https://open.bigmodel.cn/api/paas/v4/models';
            } elseif ($provider === 'stability') {
                $endpoint = 'https://api.stability.ai/v1/user/balance';
                $headers[] = 'Accept: application/json';
            } else {
                $endpoint = AICover_Plugin::buildModelsProbeEndpoint(
                    AICover_Plugin::normalizeImageEndpointByProvider($cfg->customEndpoint ?? '', 'openai_compat')
                );
            }

            $response = AICover_Plugin::httpGet($endpoint, 30, $headers);
            $json = $response ? json_decode($response, true) : null;
            if (is_array($json) && empty($json['error'])) {
                $this->json(['success' => true, 'message' => '图像 API 连接正常 ✓']);
            }
            $detail = AICover_Plugin::getLastError();
            $err = is_array($json) && isset($json['error'])
                ? (is_array($json['error']) ? ($json['error']['message'] ?? '未知错误') : (string)$json['error'])
                : '连接失败或响应异常';
            $msg = '图像 API 连接失败(' . $probeName . '): ' . $err;
            if ($detail !== '') {
                $msg .= ' | ' . $detail;
            }
            $this->jsonError($msg);
        }
    }
}
