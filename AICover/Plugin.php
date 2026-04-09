<?php
/**
 * <strong style="color: #00bba7;">Typecho智能化插件</strong><br>
 * <ul style="color: #00bba7;">
 *  <li>AI 图像生成封面</li>
 *  <li>AI 文章摘要生成</li>
 *  <li>AI 标题优化建议</li>
 *  <li>AI 评论自动回复（支持自动/人工审核/建议三种模式）</li>
 *  <li>自动从文章内容生成绘图 Prompt</li>
 *  <li>后台封面重新生成</li>
 *  <li>OG 分享图自动合成（封面 + 标题文字水印）</li>
 *  <li>封面批量管理页</li>
 *  <li>生成历史记录</li>
 * </ul>
 *
 * @package   AICover
 * @author    小铁
 * @version   1.1.5
 * @link      https://www.xiaotiewinner.com
 */

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

// 预加载 AI 回复相关类，确保在钩子触发时可用
require_once __DIR__ . '/Reply/HookHandler.php';
require_once __DIR__ . '/Reply/ReplyGenerator.php';
require_once __DIR__ . '/Reply/Provider.php';
require_once __DIR__ . '/Reply/ContextBuilder.php';
require_once __DIR__ . '/Reply/Filter.php';
require_once __DIR__ . '/Reply/RateLimiter.php';

class AICover_Plugin implements Typecho_Plugin_Interface
{
    /**
     * 最近一次 HTTP/解析错误
     */
    private static $lastError = '';
    private static $historyTableInitialized = false;
    private static $replyInfrastructureChecked = false;

    /**
     * 规范化站内相对路径，确保以 / 开头
     */
    public static function normalizeRelativePath($path)
    {
        $path = trim((string)$path);
        if ($path === '') {
            return '';
        }
        $path = str_replace('\\', '/', $path);
        if ($path[0] !== '/') {
            $path = '/' . $path;
        }
        return preg_replace('#/+#', '/', $path);
    }

    /**
     * 将站内相对路径转换为绝对路径
     */
    public static function toAbsolutePath($relativePath)
    {
        $normalized = self::normalizeRelativePath($relativePath);
        if ($normalized === '') {
            return rtrim(__TYPECHO_ROOT_DIR__, '/\\');
        }
        return rtrim(__TYPECHO_ROOT_DIR__, '/\\') . $normalized;
    }

    /**
     * 生成请求 nonce（1 小时有效，允许前一小时）
     */
    public static function createNonce($cid = 0)
    {
        return self::buildNonce($cid, (int)floor(time() / 3600));
    }

    /**
     * 校验请求 nonce
     */
    public static function verifyNonce($nonce, $cid = 0)
    {
        if (empty($nonce)) {
            return false;
        }
        $slot = (int)floor(time() / 3600);
        $expectedNow = self::buildNonce($cid, $slot);
        $expectedPrev = self::buildNonce($cid, $slot - 1);
        return hash_equals($expectedNow, $nonce) || hash_equals($expectedPrev, $nonce);
    }

    /**
     * 校验封面路径是否在允许目录内
     */
    public static function isAllowedCoverPath($path, $cfg = null)
    {
        $path = self::normalizeRelativePath($path);
        if ($path === '' || strpos($path, '..') !== false) {
            return false;
        }
        if ($cfg === null) {
            $cfg = Typecho_Widget::widget('Widget_Options')->plugin('AICover');
        }
        $baseDir = self::normalizeRelativePath($cfg->saveDir ?? '/usr/uploads/ai-covers');
        if ($baseDir === '') {
            return false;
        }
        $baseDir = rtrim($baseDir, '/');
        return $path === $baseDir || strpos($path, $baseDir . '/') === 0;
    }

    /**
     * 计算 nonce 的内部方法
     */
    private static function buildNonce($cid, $timeSlot)
    {
        $options = Typecho_Widget::widget('Widget_Options');
        $uid     = (string)Typecho_Cookie::get('__typecho_uid');
        $auth    = (string)Typecho_Cookie::get('__typecho_authCode');
        $secret  = $auth !== '' ? $auth : ($options->siteUrl ?? 'aicover');
        $seed    = $uid . '|' . (int)$cid . '|' . (int)$timeSlot . '|' . $secret;
        return hash('sha256', $seed);
    }

    /**
     * 规范化文本接口地址，兼容只填写了 base URL 的情况
     */
    public static function normalizeTextEndpoint($endpoint)
    {
        $endpoint = trim((string)$endpoint);
        if ($endpoint === '') {
            return 'https://api.openai.com/v1/chat/completions';
        }
        $endpoint = rtrim($endpoint, '/');
        if (stripos($endpoint, 'chat/completions') !== false) {
            return $endpoint;
        }
        if (preg_match('#/v\d+$#i', $endpoint)) {
            return $endpoint . '/chat/completions';
        }
        return $endpoint . '/chat/completions';
    }

    /**
     * 规范化图像接口地址，兼容只填写了 base URL 的情况
     */
    public static function normalizeImageEndpoint($endpoint)
    {
        $endpoint = trim((string)$endpoint);
        if ($endpoint === '') {
            return 'https://api.openai.com/v1/images/generations';
        }
        $endpoint = rtrim($endpoint, '/');
        if (stripos($endpoint, '/images/generations') !== false) {
            return $endpoint;
        }
        if (preg_match('#/v\d+$#i', $endpoint)) {
            return $endpoint . '/images/generations';
        }
        return $endpoint . '/v1/images/generations';
    }

    /**
     * 根据配置推断图像提供商
     */
    public static function resolveImageProvider($cfg)
    {
        $endpoint = strtolower(trim((string)($cfg->customEndpoint ?? '')));
        $model    = strtolower(trim((string)($cfg->imageModel ?? '')));

        // DashScope 兼容模式优先按 OpenAI 兼容处理
        if (strpos($endpoint, '/compatible-mode/') !== false) {
            return 'openai_compat';
        }
        if (strpos($endpoint, 'dashscope') !== false || strpos($model, 'wanx') !== false) {
            return 'wanx';
        }
        if (strpos($endpoint, 'bigmodel') !== false || strpos($model, 'cogview') !== false) {
            return 'zhipu';
        }
        if (strpos($endpoint, 'stability') !== false || strpos($model, 'stable-diffusion') !== false || strpos($model, 'sdxl') !== false) {
            return 'stability';
        }
        return 'openai_compat';
    }

    /**
     * 按提供商规范化图像端点
     */
    public static function normalizeImageEndpointByProvider($endpoint, $provider)
    {
        $endpoint = trim((string)$endpoint);
        $provider = trim((string)$provider);

        if ($provider === 'wanx') {
            if ($endpoint === '') {
                return 'https://dashscope.aliyuncs.com/api/v1/services/aigc/text2image/image-synthesis';
            }
            return rtrim($endpoint, '/');
        }

        if ($provider === 'zhipu') {
            if ($endpoint === '') {
                return 'https://open.bigmodel.cn/api/paas/v4/images/generations';
            }
            $endpoint = rtrim($endpoint, '/');
            if (stripos($endpoint, '/images/generations') !== false) {
                return $endpoint;
            }
            if (preg_match('#/v\d+$#i', $endpoint)) {
                return $endpoint . '/images/generations';
            }
            return $endpoint . '/v4/images/generations';
        }

        if ($provider === 'stability') {
            if ($endpoint === '') {
                return 'https://api.stability.ai/v2beta/stable-image/generate/core';
            }
            return rtrim($endpoint, '/');
        }

        // openai_compat / openai
        return self::normalizeImageEndpoint($endpoint);
    }

    /**
     * 从 OpenAI 兼容接口推导 models 探测地址
     */
    public static function buildModelsProbeEndpoint($endpoint)
    {
        $endpoint = trim((string)$endpoint);
        if ($endpoint === '') {
            return 'https://api.openai.com/v1/models?limit=1';
        }
        $endpoint = rtrim($endpoint, '/');
        // 常见情况: .../v1/chat/completions 或 .../v1/images/generations
        $endpoint = preg_replace('#/(chat/completions|images/generations)$#i', '', $endpoint);
        if (!preg_match('#/v\d+$#i', $endpoint)) {
            $endpoint .= '/v1';
        }
        return $endpoint . '/models?limit=1';
    }

    /**
     * 激活插件
     */
    public static function activate()
    {
        // 预加载 AI 回复相关类
        $replyDir = __DIR__ . '/Reply';
        if (is_dir($replyDir)) {
            foreach (['Provider.php', 'ContextBuilder.php', 'Filter.php', 'RateLimiter.php', 'ReplyGenerator.php', 'HookHandler.php'] as $file) {
                $path = $replyDir . '/' . $file;
                if (file_exists($path)) {
                    require_once $path;
                }
            }
        }

        // 在文章/页面编辑器底部注入 UI（兼容不同 Typecho 版本 Hook 点）
        Typecho_Plugin::factory('Widget_Contents_Post_Edit')->bottom
            = array('AICover_Plugin', 'renderEditorPanel');
        Typecho_Plugin::factory('Widget_Contents_Page_Edit')->bottom
            = array('AICover_Plugin', 'renderEditorPanel');
        Typecho_Plugin::factory('Widget\\Contents\\Post\\Edit')->bottom
            = array('AICover_Plugin', 'renderEditorPanel');
        Typecho_Plugin::factory('Widget\\Contents\\Page\\Edit')->bottom
            = array('AICover_Plugin', 'renderEditorPanel');
        Typecho_Plugin::factory('admin/write-post.php')->bottom
            = array('AICover_Plugin', 'renderEditorPanel');
        Typecho_Plugin::factory('admin/write-page.php')->bottom
            = array('AICover_Plugin', 'renderEditorPanel');

        // OG meta 标签注入
        Typecho_Plugin::factory('Widget_Archive')->header
            = array('AICover_Plugin', 'renderOGMeta');

        // AI 回复队列消费触发器（页面底部注入 JS，延迟后台 fetch）
        Typecho_Plugin::factory('Widget_Archive')->footer
            = array('AICover_Plugin', 'renderReplyQueueConsumer');

        // 注册后台动作路由
        Helper::addAction('aicover', 'AICover_Action');

        // 添加后台菜单
        Helper::addPanel(1, 'AICover/Panel.php', '封面管理', '查看与管理 AI 生成的封面', 'administrator');

        // 添加 AI 回复管理菜单
        Helper::addPanel(1, 'AICover/ReplyAdmin.php', 'AI 回复管理', '管理 AI 自动回复的审核队列', 'administrator');

        // 注册 AI 评论回复钩子（兼容不同 Typecho 版本）
        AICover_Reply_HookHandler::registerHooks();

        // 建表
        self::initDatabase();

        return _t('AICover 插件已激活');
    }

    /**
     * 禁用插件
     */
    public static function deactivate()
    {
        Helper::removeAction('aicover');
        Helper::removePanel(1, 'AICover/Panel.php');
        Helper::removePanel(1, 'AICover/ReplyAdmin.php');
        return _t('AICover 插件已禁用');
    }

    /**
     * 插件全局设置
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        $cloudAd = new Typecho_Widget_Helper_Form_Element_Text(
            '__cloudServerAd',
            null,
            '祝你开心愉快！',
            _t('<a style="font-weight:bold;color:red;" href="https://www.xiaotiewinner.com/2025/vps-tuijian.html" target="_blank" rel="noopener noreferrer">云服务器推荐</a>')
        );
        $cloudAd->input->setAttribute('readonly', 'readonly');
        $form->addInput($cloudAd);

        // 文本生成 API 端点
        $textEndpoint = new Typecho_Widget_Helper_Form_Element_Text(
            'textEndpoint', null, '',
            _t('文本生成 API 端点'),
            _t('例如 DeepSeek 的文本生成API端点: https://api.deepseek.com，不用带v1及后缀。配置此项用于摘要/标题/Prompt生成')
        );
        $form->addInput($textEndpoint);

        // 文本生成 API Key（用于摘要/标题/Prompt生成）
        $textApiKey = new Typecho_Widget_Helper_Form_Element_Text(
            'textApiKey', null, '',
            _t('文本生成 API Key'),
            _t('例如sk-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx')
        );
        $textApiKey->input->setAttribute('type', 'password');
        $textApiKey->input->setAttribute('autocomplete', 'off');
        $form->addInput($textApiKey);

        $textModel = new Typecho_Widget_Helper_Form_Element_Text(
            'textModel', null, '',
            _t('文本生成模型'),
            _t('用于生成摘要/标题/Prompt 的模型，例如 deepseek-chat、GLM-4.7-Flash 等')
        );
        $form->addInput($textModel);

        // 图像生成 API 端点
        $customEndpoint = new Typecho_Widget_Helper_Form_Element_Text(
            'customEndpoint', null, '',
            _t('图像生成 API 端点'),
            _t('按接口地址自动识别提供商并适配请求/响应；留空将使用 OpenAI 兼容默认端点。万相示例: https://dashscope.aliyuncs.com/api/v1/services/aigc/text2image/image-synthesis')
        );
        $form->addInput($customEndpoint);

        // 图像生成 API Key
        $apiKey = new Typecho_Widget_Helper_Form_Element_Text(
            'apiKey', null, '',
            _t('图像生成 API Key'),
            _t('图像生成接口的 API Key')
        );
        $apiKey->input->setAttribute('type', 'password');
        $apiKey->input->setAttribute('autocomplete', 'off');
        $form->addInput($apiKey);

        // ─── 图像生成参数 ──────────────────────────────────────────────
        $imageModel = new Typecho_Widget_Helper_Form_Element_Text(
            'imageModel', null, '',
            _t('图像生成模型'),
            _t('例如智谱免费的 Cogview-3-Flash 等')
        );
        $form->addInput($imageModel);

        $imageSize = new Typecho_Widget_Helper_Form_Element_Text(
            'imageSize', null, '1536x576',
            _t('图像尺寸'),
            _t('请按宽×高格式填写。大头图推荐 8:3（如 1536x576）；宽和高都会自动校正为16的整数倍，且不得小于512')
        );
        $form->addInput($imageSize);

        $imageQuality = new Typecho_Widget_Helper_Form_Element_Select(
            'imageQuality',
            array(
                'standard' => 'Standard (标准，推荐)',
                'hd'       => 'HD (高清)',
            ),
            'standard',
            _t('图像质量')
        );
        $form->addInput($imageQuality);

        // ─── Prompt 设置 ───────────────────────────────────────────────
        $defaultPrompt = new Typecho_Widget_Helper_Form_Element_Textarea(
            'defaultPrompt', null,
            '一张精美且专业的博客封面图，高品质，无水印',
            _t('默认图像生成 Prompt 后缀'),
            _t('每次生成图像时自动附加到 Prompt 末尾，可用于控制风格。不含文章内容部分。')
        );
        $form->addInput($defaultPrompt);

        $autoPromptFromContent = new Typecho_Widget_Helper_Form_Element_Radio(
            'autoPromptFromContent',
            array('1' => '开启', '0' => '关闭（使用默认 Prompt）'),
            '1',
            _t('自动从文章内容生成 Prompt'),
            _t('开启后，将先用文本 AI 分析文章内容，生成专属绘图 Prompt，再生成封面')
        );
        $form->addInput($autoPromptFromContent);

        $imagePromptSystem = new Typecho_Widget_Helper_Form_Element_Textarea(
            'imagePromptSystem', null,
            '你是一位专业的图像生成提示工程师。请根据给定的博客文章标题和摘要，生成一段简洁、准确、生动的图像生成提示（限200字符以内），要求适用主流AI图像生成模型。请侧重于视觉隐喻，而非文字的字面直译；图像中不得包含人脸。请仅回复提示文本本身。',
            _t('图像生成默认提示词'),
            _t('“自动从文章内容生成 Prompt”开启时发送给文本模型的提示指令，可按需更改')
        );
        $form->addInput($imagePromptSystem);

        $autoSummary = new Typecho_Widget_Helper_Form_Element_Radio(
            'autoSummary',
            array('1' => '开启', '0' => '关闭'),
            '1',
            _t('自动生成文章摘要'),
            _t('发布时若摘要为空，自动用 AI 生成并填入')
        );
        $form->addInput($autoSummary);

        $generateOG = new Typecho_Widget_Helper_Form_Element_Radio(
            'generateOG',
            array('1' => '开启', '0' => '关闭'),
            '1',
            _t('生成 OG 分享图'),
            _t('在封面图上叠加标题文字，生成专用的 Open Graph 分享图')
        );
        $form->addInput($generateOG);

        // ─── 图片存储 ──────────────────────────────────────────────────
        $saveDir = new Typecho_Widget_Helper_Form_Element_Text(
            'saveDir', null, '/usr/uploads/ai-covers',
            _t('封面存储目录'),
            _t('相对于网站根目录的路径，目录权限必须可读写')
        );
        $form->addInput($saveDir);

        $enableCoverCompress = new Typecho_Widget_Helper_Form_Element_Radio(
            'enableCoverCompress',
            array('1' => '开启', '0' => '关闭'),
            '1',
            _t('启用封面图压缩'),
            _t('保存封面后自动进行服务器端压缩，推荐开启，封面图体积过大会影响网站加载速度')
        );
        $form->addInput($enableCoverCompress);

        $compressRatio = new Typecho_Widget_Helper_Form_Element_Text(
            'compressRatio', null, '0.6',
            _t('压缩比例'),
            _t('范围 0.1 - 1.0，压缩比例过大可能会影响图片质量，建议0.6-0.8之间')
        );
        $form->addInput($compressRatio);

        $compressThresholdKB = new Typecho_Widget_Helper_Form_Element_Text(
            'compressThresholdKB', null, '500',
            _t('图片大小超出时压缩'),
            _t('单位 KB，默认 500。仅当图片大于该大小时触发自动压缩')
        );
        $form->addInput($compressThresholdKB);

        $imageSaveQuality = new Typecho_Widget_Helper_Form_Element_Text(
            'imageSaveQuality', null, '85',
            _t('图片保存质量'),
            _t('1-100，默认 85。用于 JPG/PNG 保存质量控制')
        );
        $form->addInput($imageSaveQuality);

        // OG 图字体路径
        $ogFont = new Typecho_Widget_Helper_Form_Element_Text(
            'ogFont', null, '',
            _t('OG 图标题字体路径（可选）'),
            _t('TTF/OTF 字体绝对路径，用于 OG 图中文标题渲染。留空则用 GD 内置字体（英文）')
        );
        $form->addInput($ogFont);

        // ═══════════════════════════════════════════════════════════════════
        //  AI 评论回复设置
        // ═══════════════════════════════════════════════════════════════════

        $replySection = new Typecho_Widget_Helper_Form_Element_Text(
            '__replySection',
            null,
            '',
            _t('<h3 style="margin-top:30px;padding-top:20px;border-top:2px solid #e5e7eb;">AI 评论回复设置</h3>')
        );
        $replySection->input->setAttribute('style', 'display:none');
        $form->addInput($replySection);

        // 工作模式
        $replyMode = new Typecho_Widget_Helper_Form_Element_Radio(
            'replyMode',
            array(
                'off' => '关闭（不使用 AI 回复功能）',
                'auto' => '自动回复（AI 自动生成并发布回复）',
                'manual' => '人工审核（AI 生成回复，管理员审核后发布）',
                'suggest' => '仅建议（AI 在后台生成建议，不自动发布）',
            ),
            'off',
            _t('AI 回复工作模式'),
            _t('选择 AI 评论回复的工作方式')
        );
        $form->addInput($replyMode);

        // AI 回复 API 配置（OpenAI 兼容格式）
        $replyEndpoint = new Typecho_Widget_Helper_Form_Element_Text(
            'replyEndpoint', null, '',
            _t('AI 回复 API 端点'),
            _t('例如：https://api.deepseek.com、https://dashscope.aliyuncs.com/compatible-mode/v1，不用带后缀')
        );
        $form->addInput($replyEndpoint);

        $replyApiKey = new Typecho_Widget_Helper_Form_Element_Text(
            'replyApiKey', null, '',
            _t('AI 回复 API Key'),
            _t('用于生成评论回复的 API 密钥')
        );
        $replyApiKey->input->setAttribute('type', 'password');
        $replyApiKey->input->setAttribute('autocomplete', 'off');
        $form->addInput($replyApiKey);

        $replyModel = new Typecho_Widget_Helper_Form_Element_Text(
            'replyModel', null, '',
            _t('AI 回复模型名称'),
            _t('例如：deepseek-chat、qwen-turbo、gpt-3.5-turbo、moonshot-v1-8k')
        );
        $form->addInput($replyModel);

        $replyTriggerTitle = new Typecho_Widget_Helper_Form_Element_Text(
            '__replyTriggerTitle',
            null,
            '',
            _t('<h4 style="margin-top:20px;">触发条件设置</h4>')
        );
        $replyTriggerTitle->input->setAttribute('style', 'display:none');
        $form->addInput($replyTriggerTitle);

        $replyToAll = new Typecho_Widget_Helper_Form_Element_Radio(
            'replyToAll',
            array('1' => '是', '0' => '否'),
            '1',
            _t('回复所有评论'),
            _t('开启后将回复所有符合条件的评论，关闭则只在包含关键词时回复')
        );
        $form->addInput($replyToAll);

        $replyKeywords = new Typecho_Widget_Helper_Form_Element_Textarea(
            'replyKeywords', null, '',
            _t('触发关键词'),
            _t('当评论包含这些关键词时触发回复，每行一个关键词。留空则只回复包含问号的评论')
        );
        $form->addInput($replyKeywords);

        $replyFirstLevelOnly = new Typecho_Widget_Helper_Form_Element_Radio(
            'replyFirstLevelOnly',
            array('1' => '仅一级评论', '0' => '所有层级'),
            '0',
            _t('回复层级限制'),
            _t('选择只回复一级评论（对文章的直接评论），还是也回复评论的回复')
        );
        $form->addInput($replyFirstLevelOnly);

        $replyExcludeAuthors = new Typecho_Widget_Helper_Form_Element_Text(
            'replyExcludeAuthors', null, '',
            _t('排除回复的作者'),
            _t('不回复这些作者的评论，多个用户名用逗号分隔。例如：admin,editor')
        );
        $form->addInput($replyExcludeAuthors);

        // 上下文设置
        $replyContextTitle = new Typecho_Widget_Helper_Form_Element_Text(
            '__replyContextTitle',
            null,
            '',
            _t('<h4 style="margin-top:20px;">上下文设置</h4>')
        );
        $replyContextTitle->input->setAttribute('style', 'display:none');
        $form->addInput($replyContextTitle);

        $replyIncludeArticle = new Typecho_Widget_Helper_Form_Element_Radio(
            'replyIncludeArticle',
            array('1' => '包含', '0' => '不包含'),
            '1',
            _t('包含文章内容'),
            _t('回复生成时是否将文章内容作为上下文')
        );
        $form->addInput($replyIncludeArticle);

        $replyIncludeParent = new Typecho_Widget_Helper_Form_Element_Radio(
            'replyIncludeParent',
            array('1' => '包含', '0' => '不包含'),
            '1',
            _t('包含父评论'),
            _t('回复评论的回复时，是否包含上级评论内容作为上下文')
        );
        $form->addInput($replyIncludeParent);

        $replyMaxContextLength = new Typecho_Widget_Helper_Form_Element_Text(
            'replyMaxContextLength', null, '2000',
            _t('最大上下文长度'),
            _t('限制发送给 AI 的上下文最大字符数，防止超出模型限制')
        );
        $form->addInput($replyMaxContextLength);

        // 安全设置
        $replySafetyTitle = new Typecho_Widget_Helper_Form_Element_Text(
            '__replySafetyTitle',
            null,
            '',
            _t('<h4 style="margin-top:20px;">安全与限制设置</h4>')
        );
        $replySafetyTitle->input->setAttribute('style', 'display:none');
        $form->addInput($replySafetyTitle);

        $replyBlockedWords = new Typecho_Widget_Helper_Form_Element_Textarea(
            'replyBlockedWords', null, '',
            _t('敏感词列表'),
            _t('包含这些词的评论不会触发 AI 回复，每行一个词')
        );
        $form->addInput($replyBlockedWords);

        $replyMaxPerHour = new Typecho_Widget_Helper_Form_Element_Text(
            'replyMaxPerHour', null, '10',
            _t('每小时最大回复数'),
            _t('限制每小时自动回复的数量，0 表示无限制')
        );
        $form->addInput($replyMaxPerHour);

        $replyMaxPerDay = new Typecho_Widget_Helper_Form_Element_Text(
            'replyMaxPerDay', null, '50',
            _t('每天最大回复数'),
            _t('限制每天自动回复的数量，0 表示无限制')
        );
        $form->addInput($replyMaxPerDay);

        $replyDelayMin = new Typecho_Widget_Helper_Form_Element_Text(
            'replyDelayMin', null, '0',
            _t('最小延迟（秒）'),
            _t('回复前的最小延迟时间，模拟人工回复')
        );
        $form->addInput($replyDelayMin);

        $replyDelayMax = new Typecho_Widget_Helper_Form_Element_Text(
            'replyDelayMax', null, '30',
            _t('最大延迟（秒）'),
            _t('回复前的最大延迟时间，实际延迟在此范围内随机')
        );
        $form->addInput($replyDelayMax);

        $replySecret = new Typecho_Widget_Helper_Form_Element_Text(
            'replySecret', null, self::getReplySecret(),
            _t('隐私密钥'),
            _t('用于哈希 IP 地址的密钥，生产环境建议修改为随机字符串')
        );
        $form->addInput($replySecret);

        // AI 身份设置
        $replyIdentityTitle = new Typecho_Widget_Helper_Form_Element_Text(
            '__replyIdentityTitle',
            null,
            '',
            _t('<h4 style="margin-top:20px;">AI 身份设置</h4>')
        );
        $replyIdentityTitle->input->setAttribute('style', 'display:none');
        $form->addInput($replyIdentityTitle);

        $replyAiName = new Typecho_Widget_Helper_Form_Element_Text(
            'replyAiName', null, 'AI助手',
            _t('AI 显示名称'),
            _t('在回复中显示的 AI 名称')
        );
        $form->addInput($replyAiName);

        $replyShowBadge = new Typecho_Widget_Helper_Form_Element_Radio(
            'replyShowBadge',
            array('1' => '显示', '0' => '不显示'),
            '1',
            _t('显示 AI 回复标识'),
            _t('是否在 AI 生成的回复旁显示标识')
        );
        $form->addInput($replyShowBadge);

        $replySignature = new Typecho_Widget_Helper_Form_Element_Text(
            'replySignature', null, '—— AI助手',
            _t('AI 回复签名'),
            _t('在 AI 回复末尾添加的签名，留空则不添加')
        );
        $form->addInput($replySignature);

        $replySystemPrompt = new Typecho_Widget_Helper_Form_Element_Textarea(
            'replySystemPrompt', null,
            "你是一位友善的博客评论回复助手。请根据文章内容和评论内容，给出恰当、有建设性的回复。回复应该：\n1. 简洁明了，不超过 200 字\n2. 友善有礼，体现对读者的尊重\n3. 针对评论内容给出实质性回应\n4. 必要时可以提出问题引导进一步讨论\n5. 使用中文回复",
            _t('AI 角色设定（系统提示词）'),
            _t('设定 AI 的角色和回复风格')
        );
        $form->addInput($replySystemPrompt);
    }

    /**
     * 个人设置（无需个人设置）
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form) {}

    // ═══════════════════════════════════════════════════════════════════
    //  核心 Hook 回调
    // ═══════════════════════════════════════════════════════════════════

    /**
     * 兼容不同 Typecho 版本/Hook 传参格式
     */
    private static function normalizePublishContents($contents, $class = null)
    {
        if (is_array($contents)) {
            return $contents;
        }

        $normalized = [];
        if (is_object($contents)) {
            foreach (['cid', 'title', 'text', 'type'] as $key) {
                if (isset($contents->$key)) {
                    $normalized[$key] = $contents->$key;
                }
            }
        }

        if (empty($normalized['cid']) && is_object($class) && isset($class->cid)) {
            $normalized['cid'] = $class->cid;
        }
        if (empty($normalized['cid']) && is_object($class) && method_exists($class, 'cid')) {
            $normalized['cid'] = $class->cid();
        }
        if (empty($normalized['cid']) && is_object($class) && isset($class->request) && is_object($class->request)) {
            if (method_exists($class->request, 'get')) {
                $normalized['cid'] = (int)$class->request->get('cid', 0);
            } elseif (isset($class->request->cid)) {
                $normalized['cid'] = (int)$class->request->cid;
            }
        }
        if (empty($normalized['type']) && is_object($class) && isset($class->type)) {
            $normalized['type'] = $class->type;
        }
        if (empty($normalized['type'])) {
            $normalized['type'] = 'post';
        }

        return $normalized;
    }

    /**
     * 触发封面生成（同步）
     */
    public static function triggerGeneration($cid, $contents)
    {
        try {
            $options = Typecho_Widget::widget('Widget_Options');
            $cfg     = $options->plugin('AICover');

            $title   = strip_tags($contents['title'] ?? '');
            $body    = strip_tags($contents['text']  ?? '');
            $body    = mb_substr($body, 0, 800); // 控制 token 用量

            // Step 1: 生成绘图 Prompt
            $prompt = self::buildImagePrompt($title, $body, $cfg);

            // Step 2: 生成图像
            $imageData = self::callImageAPI($prompt, $cfg);
            if (!$imageData) {
                self::log("封面生成失败: 图像 API 返回空", $cid);
                return;
            }

            // Step 3: 保存图像
            $coverPath = self::saveImageFile($imageData, $cid, $cfg);
            if (!$coverPath) {
                self::log("封面保存失败", $cid);
                return;
            }

            // Step 4: 写入数据库封面字段
            self::saveCoverToPost($cid, $coverPath);

            // Step 5: 生成 OG 图
            if ($cfg->generateOG) {
                self::generateOGImage($coverPath, $title, $cid, $cfg);
            }

            // Step 6: 生成摘要
            if ($cfg->autoSummary) {
                self::generateAndSaveSummary($cid, $contents);
            }

            // 记录历史
            self::logHistory($cid, $prompt, $coverPath);

            self::log("封面生成成功: $coverPath", $cid);

        } catch (Exception $e) {
            self::log("封面生成异常: " . $e->getMessage(), $cid);
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    //  AI 调用方法
    // ═══════════════════════════════════════════════════════════════════

    /**
     * 构建绘图 Prompt
     */
    public static function buildImagePrompt($title, $body, $cfg)
    {
        $suffix = trim($cfg->defaultPrompt ?? '');

        if (!$cfg->autoPromptFromContent || empty($cfg->textApiKey)) {
            // 简单模式：直接用标题
            $prompt = $title ? "为文章: {$title} 生成博客封面" : '生成博客封面';
            return $prompt . ($suffix ? ", $suffix" : '');
        }

        // 用 AI 生成专属 Prompt
        $systemMsg = '你是一位专业的图像生成提示工程师。请根据给定的博客文章标题和摘要，生成一段简洁、准确、生动的图像生成提示（限200字符以内），要求适用主流AI图像生成模型。请侧重于视觉隐喻，而非文字的字面直译；图像中不得包含人脸。请仅回复提示文本本身。';
        // 兼容历史配置：旧版本尚未保存 imagePromptSystem 时，直接访问可能触发配置对象空指针
        if (is_object($cfg) && isset($cfg->imagePromptSystem)) {
            $customSystemMsg = trim((string)$cfg->imagePromptSystem);
            if ($customSystemMsg !== '') {
                $systemMsg = $customSystemMsg;
            }
        }
        $userMsg   = "标题: {$title}\n\n内容摘要: {$body}";

        $aiPrompt = self::callTextAPI($systemMsg, $userMsg, $cfg, 200);
        if (!$aiPrompt) {
            // 降级
            return ($title ? "为文章: {$title} 生成博客封面" : '生成博客封面') . ($suffix ? ", $suffix" : '');
        }

        $aiPrompt = trim(strip_tags($aiPrompt));
        return $aiPrompt . ($suffix ? ", $suffix" : '');
    }

    /**
     * 调用图像生成 API
     * @return string|false 返回图像二进制数据
     */
    public static function callImageAPI($prompt, $cfg)
    {
        self::$lastError = '';
        $provider = self::resolveImageProvider($cfg);

        switch ($provider) {
            case 'zhipu':
                return self::callZhipuImageAPI($prompt, $cfg);
            case 'wanx':
                return self::callWanxImageAPI($prompt, $cfg);
            case 'stability':
                return self::callStabilityImageAPI($prompt, $cfg);
            case 'openai_compat':
            default:
                return self::callOpenAIImageAPI($prompt, $cfg);
        }
    }

    /**
     * 自定义兼容图像 API
     */
    private static function callOpenAIImageAPI($prompt, $cfg)
    {
        $endpoint = self::normalizeImageEndpointByProvider($cfg->customEndpoint ?? '', 'openai_compat');

        $size    = self::normalizeImageSize((string)($cfg->imageSize ?? '1536x576'));
        $quality = $cfg->imageQuality ?? 'standard';
        $model   = $cfg->imageModel   ?? 'Cogview-3-Flash';

        $reqBody = [
            'model'           => $model,
            'prompt'          => $prompt,
            'n'               => 1,
            'size'            => $size,
            'quality'         => $quality,
            'response_format' => 'b64_json',
        ];

        $body = json_encode($reqBody);

        $response = self::httpPost($endpoint, $body, [
            'Authorization: Bearer ' . $cfg->apiKey,
            'Content-Type: application/json',
        ]);

        if (!$response) return false;

        $data = json_decode($response, true);
        if (isset($data['error'])) {
            self::$lastError = is_array($data['error'])
                ? ($data['error']['message'] ?? '图像接口返回错误')
                : (string)$data['error'];
            self::log('图像 API 错误: ' . self::$lastError);
            return false;
        }

        return self::extractImageBinaryFromResponseData($data, $response);
    }

    /**
     * 智谱原生图像接口
     */
    private static function callZhipuImageAPI($prompt, $cfg)
    {
        $endpoint = self::normalizeImageEndpointByProvider($cfg->customEndpoint ?? '', 'zhipu');
        $model    = $cfg->imageModel ?? 'cogview-3-flash';
        $size     = self::normalizeImageSize((string)($cfg->imageSize ?? '1536x576'));

        $body = json_encode([
            'model'  => $model,
            'prompt' => $prompt,
            'size'   => $size,
        ]);

        $response = self::httpPost($endpoint, $body, [
            'Authorization: Bearer ' . ($cfg->apiKey ?? ''),
            'Content-Type: application/json',
        ]);
        if (!$response) return false;
        $data = json_decode($response, true);
        return self::extractImageBinaryFromResponseData($data, $response);
    }

    /**
     * 阿里通义万相（DashScope 原生）
     */
    private static function callWanxImageAPI($prompt, $cfg)
    {
        $endpoint = self::normalizeImageEndpointByProvider($cfg->customEndpoint ?? '', 'wanx');
        $model    = $cfg->imageModel ?: 'wanx2.1-t2i-turbo';
        $sizeNorm = self::normalizeImageSize((string)($cfg->imageSize ?? '1536x576'));
        $size     = str_replace('x', '*', $sizeNorm);

        $bodyPrompt = json_encode([
            'model'      => $model,
            'input'      => ['prompt' => $prompt],
            'parameters' => ['size' => $size],
        ]);
        $bodyMessages = json_encode([
            'model'      => $model,
            'input'      => [
                'messages' => [[
                    'role'    => 'user',
                    'content' => [['text' => $prompt]],
                ]],
            ],
            'parameters' => ['size' => $size],
        ]);
        // 某些版本要求 content 为纯文本而不是数组
        $bodyMessagesText = json_encode([
            'model'      => $model,
            'input'      => [
                'messages' => [[
                    'role'    => 'user',
                    'content' => $prompt,
                ]],
            ],
            'parameters' => ['size' => $size],
        ]);

        $headers = [
            'Authorization: Bearer ' . ($cfg->apiKey ?? ''),
            'Content-Type: application/json',
            'X-DashScope-Async: disable',
        ];

        $response = self::httpPost($endpoint, $bodyPrompt, $headers, 90);
        if (!$response) return false;
        $data = json_decode($response, true);

        // 某些万相端点要求 input.messages，而不是 input.prompt
        $wanxMsgError = self::responseContainsKeyword($data, $response, 'input.messages');
        if ($wanxMsgError) {
            $response = self::httpPost($endpoint, $bodyMessages, $headers, 90);
            if (!$response) return false;
            $data = json_decode($response, true);
            if (self::responseContainsKeyword($data, $response, 'input.messages')) {
                $response = self::httpPost($endpoint, $bodyMessagesText, $headers, 90);
                if (!$response) return false;
                $data = json_decode($response, true);
            }
        }

        $bin = self::extractImageBinaryFromResponseData($data, $response);
        if ($bin !== false) {
            return $bin;
        }

        // 异步任务兜底
        $taskId = $data['output']['task_id'] ?? null;
        if (!empty($taskId)) {
            $taskEndpoint = self::buildWanxTaskEndpoint($endpoint, $taskId);
            for ($i = 0; $i < 8; $i++) {
                sleep(1);
                $taskResp = self::httpGet($taskEndpoint, 30, [
                    'Authorization: Bearer ' . ($cfg->apiKey ?? ''),
                ]);
                if (!$taskResp) {
                    continue;
                }
                $taskData = json_decode($taskResp, true);
                $status = strtoupper((string)($taskData['output']['task_status'] ?? ''));
                if ($status === 'FAILED') {
                    self::$lastError = $taskData['output']['message'] ?? '万相异步任务失败';
                    return false;
                }
                $bin = self::extractImageBinaryFromResponseData($taskData, $taskResp);
                if ($bin !== false) {
                    return $bin;
                }
            }
            self::$lastError = '万相异步任务超时或未返回图片';
        }
        return false;
    }

    /**
     * Stability 原生接口（v2beta）
     */
    private static function callStabilityImageAPI($prompt, $cfg)
    {
        $endpoint = self::normalizeImageEndpointByProvider($cfg->customEndpoint ?? '', 'stability');
        $size = self::normalizeImageSize((string)($cfg->imageSize ?? '1536x576'));
        $aspectRatio = self::sizeToAspectRatio($size, '8:3');

        $body = json_encode([
            'prompt'        => $prompt,
            'output_format' => 'png',
            'aspect_ratio'  => $aspectRatio,
        ]);
        $headers = [
            'Authorization: Bearer ' . ($cfg->apiKey ?? ''),
            'Accept: application/json',
            'Content-Type: application/json',
        ];
        $response = self::httpPost($endpoint, $body, $headers, 90);
        if (!$response) return false;
        $data = json_decode($response, true);

        // 某些 Stability 端点对 aspect_ratio 可选值较严格，回退到 16:9 提高成功率
        if (self::responseContainsKeyword($data, $response, 'aspect_ratio')) {
            $fallbackBody = json_encode([
                'prompt'        => $prompt,
                'output_format' => 'png',
                'aspect_ratio'  => '16:9',
            ]);
            $response = self::httpPost($endpoint, $fallbackBody, $headers, 90);
            if (!$response) return false;
            $data = json_decode($response, true);
        }

        return self::extractImageBinaryFromResponseData($data, $response);
    }

    /**
     * 规范尺寸：确保宽高>=512 且均为16的整数倍
     */
    private static function normalizeImageSize($size, $fallback = '1536x576')
    {
        $size = strtolower(trim((string)$size));
        if (!preg_match('/^(\d+)\s*[x\*]\s*(\d+)$/', $size, $m)) {
            return $fallback;
        }
        $w = (int)$m[1];
        $h = (int)$m[2];
        if ($w <= 0 || $h <= 0) {
            return $fallback;
        }
        $w = max(512, (int)round($w / 16) * 16);
        $h = max(512, (int)round($h / 16) * 16);
        return $w . 'x' . $h;
    }

    /**
     * 将 WxH 尺寸转换为最简宽高比字符串（如 1536x576 -> 8:3）
     */
    private static function sizeToAspectRatio($size, $fallback = '16:9')
    {
        $size = strtolower(trim((string)$size));
        if (!preg_match('/^(\d+)\s*[x\*]\s*(\d+)$/', $size, $m)) {
            return $fallback;
        }
        $w = (int)$m[1];
        $h = (int)$m[2];
        if ($w <= 0 || $h <= 0) {
            return $fallback;
        }
        $a = $w;
        $b = $h;
        while ($b !== 0) {
            $tmp = $a % $b;
            $a = $b;
            $b = $tmp;
        }
        $gcd = max(1, $a);
        return (int)($w / $gcd) . ':' . (int)($h / $gcd);
    }

    private static function buildWanxTaskEndpoint($endpoint, $taskId)
    {
        $endpoint = rtrim((string)$endpoint, '/');
        if (preg_match('#/api/v1/services/.+$#', $endpoint)) {
            $base = preg_replace('#/api/v1/services/.+$#', '', $endpoint);
            return $base . '/api/v1/tasks/' . rawurlencode($taskId);
        }
        return $endpoint . '/tasks/' . rawurlencode($taskId);
    }

    /**
     * 从不同提供商返回中提取图片二进制
     */
    private static function extractImageBinaryFromResponseData($data, $rawResponse = '')
    {
        if (!is_array($data)) {
            self::$lastError = '图像接口返回非 JSON';
            return false;
        }

        if (isset($data['error'])) {
            self::$lastError = is_array($data['error'])
                ? ($data['error']['message'] ?? json_encode($data['error'], JSON_UNESCAPED_UNICODE))
                : (string)$data['error'];
            return false;
        }
        if (isset($data['code']) && (string)$data['code'] !== '200') {
            $msg = $data['message'] ?? $data['msg'] ?? '';
            if ($msg !== '') {
                self::$lastError = (string)$msg;
            }
        }

        $b64Paths = [
            ['data', 0, 'b64_json'],
            ['data', 0, 'base64'],
            ['image_base64'],
            ['output', 'results', 0, 'b64_json'],
            ['output', 'results', 0, 'base64'],
            ['output', 'images', 0, 'b64_json'],
            ['output', 'images', 0, 'base64'],
        ];
        foreach ($b64Paths as $path) {
            $b64 = self::arrayPath($data, $path);
            if (!empty($b64)) {
                $bin = base64_decode((string)$b64, true);
                if ($bin !== false && self::isImageBinary($bin)) {
                    return $bin;
                }
            }
        }

        $urlPaths = [
            ['data', 0, 'url'],
            ['image_url'],
            ['output', 'results', 0, 'url'],
            ['output', 'images', 0, 'url'],
            ['output', 'result_url'],
            ['result', 'url'],
            ['output', 'choices', 0, 'message', 'content', 0, 'image'],
            ['output', 'choices', 0, 'message', 'content', 0, 'image_url'],
        ];
        foreach ($urlPaths as $path) {
            $url = self::arrayPath($data, $path);
            if (!empty($url)) {
                $bin = self::httpGet((string)$url);
                if ($bin !== false && self::isImageBinary($bin)) {
                    return $bin;
                }
            }
        }

        if (self::$lastError === '') {
            self::$lastError = '图像接口返回结构不支持';
        }
        if (!empty($rawResponse)) {
            self::log('图像响应结构不支持: ' . mb_substr((string)$rawResponse, 0, 500));
        }
        return false;
    }

    private static function arrayPath($arr, $path)
    {
        $cur = $arr;
        foreach ($path as $segment) {
            if (is_array($cur) && array_key_exists($segment, $cur)) {
                $cur = $cur[$segment];
                continue;
            }
            return null;
        }
        return $cur;
    }

    /**
     * 判断响应中是否包含关键字（用于接口协议回退）
     */
    private static function responseContainsKeyword($data, $rawResponse, $keyword)
    {
        $keyword = strtolower((string)$keyword);
        if ($keyword === '') return false;

        if (is_array($data)) {
            $flat = strtolower(json_encode($data, JSON_UNESCAPED_UNICODE));
            if (strpos($flat, $keyword) !== false) {
                return true;
            }
        }
        $raw = strtolower((string)$rawResponse);
        return strpos($raw, $keyword) !== false;
    }

    /**
     * 粗略判断是否为有效图片二进制
     */
    private static function isImageBinary($bin)
    {
        if (!is_string($bin) || strlen($bin) < 16) {
            return false;
        }
        $info = @getimagesizefromstring($bin);
        return is_array($info) && !empty($info[0]) && !empty($info[1]);
    }

    /**
     * 调用文本 AI（OpenAI 兼容接口）
     */
    public static function callTextAPI($systemMsg, $userMsg, $cfg, $maxTokens = 500)
    {
        self::$lastError = '';
        $apiKey   = $cfg->textApiKey  ?? '';
        $endpoint = self::normalizeTextEndpoint($cfg->textEndpoint ?? '');
        $model    = $cfg->textModel    ?? 'deepseek-chat';

        if (empty($apiKey)) {
            self::$lastError = '文本 API Key 未配置';
            return false;
        }

        $body = json_encode([
            'model'      => $model,
            'max_tokens' => $maxTokens,
            'messages'   => [
                ['role' => 'system', 'content' => $systemMsg],
                ['role' => 'user',   'content' => $userMsg],
            ],
        ]);

        $response = self::httpPost($endpoint, $body, [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ]);

        if ($response === false || $response === '') {
            if (self::$lastError === '') {
                self::$lastError = '文本接口无响应: ' . $endpoint;
            }
            return false;
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            self::$lastError = '文本接口返回非 JSON: ' . $endpoint;
            return false;
        }

        if (isset($data['error'])) {
            if (is_array($data['error'])) {
                self::$lastError = $data['error']['message'] ?? json_encode($data['error'], JSON_UNESCAPED_UNICODE);
            } else {
                self::$lastError = (string)$data['error'];
            }
            return false;
        }

        if (isset($data['choices'][0]['message']['content'])) {
            $content = $data['choices'][0]['message']['content'];
            if (is_array($content)) {
                // 兼容 content 为数组块的返回结构
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
            $content = trim((string)$content);
            if ($content !== '') {
                return $content;
            }
        }

        if (isset($data['choices'][0]['text'])) {
            $text = trim((string)$data['choices'][0]['text']);
            if ($text !== '') {
                return $text;
            }
        }

        if (isset($data['output_text'])) {
            $text = trim((string)$data['output_text']);
            if ($text !== '') {
                return $text;
            }
        }

        self::$lastError = '文本接口返回结构不支持';
        return false;
    }

    /**
     * 获取最近一次错误信息
     */
    public static function getLastError()
    {
        return self::$lastError;
    }

    // ═══════════════════════════════════════════════════════════════════
    //  图像处理
    // ═══════════════════════════════════════════════════════════════════

    /**
     * 保存图像文件（优先原格式，失败时降级 JPG/PNG）
     * @return string|false 相对路径
     */
    public static function saveImageFile($imageData, $cid, $cfg)
    {
        self::$lastError = '';
        $quality  = (int)($cfg->imageSaveQuality ?? 85);
        $quality  = max(1, min(100, $quality));
        $saveDir  = rtrim(self::normalizeRelativePath($cfg->saveDir ?? '/usr/uploads/ai-covers'), '/');
        $fullDir  = self::toAbsolutePath($saveDir);

        if (!is_dir($fullDir)) {
            $created = @mkdir($fullDir, 0755, true);
            if (!$created && !is_dir($fullDir)) {
                self::$lastError = "目录创建失败: $fullDir";
                self::log(self::$lastError);
                return false;
            }
        }

        if (!is_writable($fullDir)) {
            self::$lastError = "目录不可写: $fullDir";
            self::log(self::$lastError);
            return false;
        }

        // 二次探测：is_writable 在部分环境不可靠，实际写入测试更准确
        $probe = $fullDir . '/.aicover_write_test_' . uniqid('', true) . '.tmp';
        if (@file_put_contents($probe, 'ok') === false) {
            self::$lastError = "目录写入测试失败: $fullDir";
            self::log(self::$lastError);
            return false;
        }
        @unlink($probe);

        $filename = "cover_{$cid}_" . date('Ymd_His');

        // ── 策略一：按原始格式直接落盘 ────────────────────────────────────
        $rawSaved = self::saveRawImageBinary($imageData, $fullDir, $cid);
        if ($rawSaved !== false) {
            $relPath = self::normalizeRelativePath($saveDir . '/' . $rawSaved);
            self::log('按原始格式保存: ' . $rawSaved, (int)$cid);
            $compressResult = self::compressCoverByPath($relPath, $cfg, false, (int)$cid, true);
            if (is_array($compressResult) && !empty($compressResult['new_path'])) {
                return (string)$compressResult['new_path'];
            }
            return $relPath;
        }

        // ── 策略二：GD 解码后编码（JPEG → PNG 降级）───────────────────────
        $image = @imagecreatefromstring($imageData);
        if ($image) {
            $attempts = [];
            if (function_exists('imagejpeg')) {
                $attempts['jpg'] = ['func' => 'imagejpeg', 'args' => [$quality]];
            }
            if (function_exists('imagepng')) {
                $attempts['png'] = ['func' => 'imagepng', 'args' => [6]];
            }

            foreach ($attempts as $ext => $info) {
                $tryFile = $fullDir . '/' . $filename . '.' . $ext;
                $target  = $image;
                if ($ext === 'jpg') {
                    $target = self::flattenAlpha($image);
                }
                $args = array_merge([$target, $tryFile], $info['args']);
                $ok   = @call_user_func_array($info['func'], $args);
                if ($target !== $image) {
                    imagedestroy($target);
                }
                if ($ok && file_exists($tryFile) && filesize($tryFile) > 0) {
                    imagedestroy($image);
                    $relPath = self::normalizeRelativePath($saveDir . '/' . $filename . '.' . $ext);
                    self::log("GD 转换成功（{$ext}）: {$filename}.{$ext}", (int)$cid);
                    $compressResult = self::compressCoverByPath($relPath, $cfg, false, (int)$cid, true);
                    if (is_array($compressResult) && !empty($compressResult['new_path'])) {
                        return (string)$compressResult['new_path'];
                    }
                    return $relPath;
                }
                if (file_exists($tryFile)) {
                    @unlink($tryFile);
                }
            }
            imagedestroy($image);
        }

        self::$lastError = '图像保存失败（无法识别原始格式且 GD 编码不可用）';
        self::log(self::$lastError);
        return false;
    }

    /**
     * 按路径压缩封面图
     * @return array|false
     */
    public static function compressCoverByPath($relativePath, $cfg, $force = false, $cid = 0, $allowFormatChange = false)
    {
        $relativePath = self::normalizeRelativePath($relativePath);
        if ($relativePath === '' || !self::isAllowedCoverPath($relativePath, $cfg)) {
            self::$lastError = '压缩路径不合法';
            return false;
        }

        $fullPath = self::toAbsolutePath($relativePath);
        if (!file_exists($fullPath)) {
            self::$lastError = '压缩目标不存在';
            return false;
        }

        $before = (int)@filesize($fullPath);
        if ($before <= 0) {
            self::$lastError = '压缩目标为空文件';
            return false;
        }

        $enabled = ((int)($cfg->enableCoverCompress ?? 1) === 1);
        $thresholdBytes = max(1, (int)($cfg->compressThresholdKB ?? 500)) * 1024;
        if (!$force && (!$enabled || $before <= $thresholdBytes)) {
            return [
                'compressed' => false,
                'before'     => $before,
                'after'      => $before,
                'new_path'   => $relativePath,
            ];
        }

        $ratio = (float)($cfg->compressRatio ?? 0.6);
        $ratio = max(0.1, min(1.0, $ratio));
        $jpegQuality = max(1, min(100, (int)round($ratio * 100)));
        $pngLevel = max(0, min(9, (int)round((1 - $ratio) * 9)));

        $bin = @file_get_contents($fullPath);
        if ($bin === false || $bin === '') {
            self::$lastError = '读取压缩目标失败';
            return false;
        }

        $img = @imagecreatefromstring($bin);
        if (!$img) {
            self::$lastError = '压缩失败：图像格式不支持';
            return false;
        }

        $ext = strtolower((string)pathinfo($fullPath, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
            // 其它格式默认不处理；允许变更格式时，尝试转为 JPG 压缩
            if ($allowFormatChange && function_exists('imagejpeg')) {
                $jpgTmp = $fullPath . '.tmp_' . uniqid('', true) . '.jpg';
                $target = self::flattenAlpha($img);
                $okJpg  = @imagejpeg($target, $jpgTmp, $jpegQuality);
                imagedestroy($target);
                if ($okJpg && file_exists($jpgTmp)) {
                    $afterJpg = (int)@filesize($jpgTmp);
                    if ($afterJpg > 0 && $afterJpg < $before) {
                        $newFull = preg_replace('/\.[^.]+$/', '.jpg', $fullPath);
                        if (!@rename($jpgTmp, $newFull)) {
                            if (@copy($jpgTmp, $newFull)) {
                                @unlink($jpgTmp);
                            } else {
                                @unlink($jpgTmp);
                                imagedestroy($img);
                                self::$lastError = '压缩格式转换失败';
                                return false;
                            }
                        }
                        @unlink($fullPath);
                        imagedestroy($img);
                        $newRel = preg_replace('/\.[^.\/]+$/', '.jpg', $relativePath);
                        self::log('封面压缩并转 JPG 成功: ' . $relativePath . ' -> ' . $newRel . ' ' . $before . ' -> ' . $afterJpg, (int)$cid);
                        return [
                            'compressed' => true,
                            'before'     => $before,
                            'after'      => $afterJpg,
                            'new_path'   => $newRel,
                        ];
                    }
                }
                @unlink($jpgTmp);
            }
            imagedestroy($img);
            return [
                'compressed' => false,
                'before'     => $before,
                'after'      => $before,
                'new_path'   => $relativePath,
            ];
        }

        $tmp = $fullPath . '.tmp_' . uniqid('', true);
        $saved = false;
        if (($ext === 'jpg' || $ext === 'jpeg') && function_exists('imagejpeg')) {
            $target = self::flattenAlpha($img);
            $saved  = @imagejpeg($target, $tmp, $jpegQuality);
            imagedestroy($target);
        } elseif ($ext === 'png' && function_exists('imagepng')) {
            $saved = @imagepng($img, $tmp, $pngLevel);
        }
        imagedestroy($img);

        if (!$saved || !file_exists($tmp)) {
            @unlink($tmp);
            self::$lastError = '压缩编码失败';
            return false;
        }

        $after = (int)@filesize($tmp);
        if ($after <= 0 || $after >= $before) {
            // PNG 在无损压缩下收益很小，允许时尝试转 JPG 进一步压缩
            if ($allowFormatChange && $ext === 'png' && function_exists('imagejpeg')) {
                $jpgTmp = $fullPath . '.tmp_' . uniqid('', true) . '.jpg';
                $img2 = @imagecreatefromstring($bin);
                if ($img2) {
                    $target2 = self::flattenAlpha($img2);
                    $okJpg   = @imagejpeg($target2, $jpgTmp, $jpegQuality);
                    imagedestroy($target2);
                    imagedestroy($img2);
                    if ($okJpg && file_exists($jpgTmp)) {
                        $afterJpg = (int)@filesize($jpgTmp);
                        if ($afterJpg > 0 && $afterJpg < $before) {
                            @unlink($tmp);
                            $newFull = preg_replace('/\.[^.]+$/', '.jpg', $fullPath);
                            if (!@rename($jpgTmp, $newFull)) {
                                if (@copy($jpgTmp, $newFull)) {
                                    @unlink($jpgTmp);
                                } else {
                                    @unlink($jpgTmp);
                                    self::$lastError = '压缩格式转换失败';
                                    return false;
                                }
                            }
                            @unlink($fullPath);
                            $newRel = preg_replace('/\.[^.\/]+$/', '.jpg', $relativePath);
                            self::log('PNG 压缩转 JPG 成功: ' . $relativePath . ' -> ' . $newRel . ' ' . $before . ' -> ' . $afterJpg, (int)$cid);
                            return [
                                'compressed' => true,
                                'before'     => $before,
                                'after'      => $afterJpg,
                                'new_path'   => $newRel,
                            ];
                        }
                    }
                }
                @unlink($jpgTmp);
            }
            @unlink($tmp);
            return [
                'compressed' => false,
                'before'     => $before,
                'after'      => $before,
                'new_path'   => $relativePath,
            ];
        }

        if (!@rename($tmp, $fullPath)) {
            if (@copy($tmp, $fullPath)) {
                @unlink($tmp);
            } else {
                @unlink($tmp);
                self::$lastError = '压缩文件替换失败';
                return false;
            }
        }

        self::log('封面压缩成功: ' . $relativePath . ' ' . $before . ' -> ' . $after, (int)$cid);
        return [
            'compressed' => true,
            'before'     => $before,
            'after'      => $after,
            'new_path'   => $relativePath,
        ];
    }

    /**
     * 检测图像二进制数据的格式（通过魔数字节）
     * @return string 'png'|'jpg'|'gif'|'bmp'|''
     */
    private static function detectImageFormat($imageData)
    {
        if (empty($imageData) || strlen($imageData) < 12) {
            return '';
        }
        $header = substr($imageData, 0, 12);
        if (substr($header, 0, 4) === "\x89PNG")      return 'png';
        if (substr($header, 0, 2) === "\xFF\xD8")      return 'jpg';
        if (substr($header, 0, 6) === 'GIF87a' ||
            substr($header, 0, 6) === 'GIF89a')        return 'gif';
        if (substr($header, 0, 2) === 'BM')            return 'bmp';
        return '';
    }

    /**
     * 将含透明通道的 GD 图像与白色背景合并，用于 JPEG 编码前处理
     * @return resource|\GdImage
     */
    private static function flattenAlpha($src)
    {
        $w    = imagesx($src);
        $h    = imagesy($src);
        $flat = imagecreatetruecolor($w, $h);
        // 关闭 alpha 保存，确保输出为不透明 RGB
        imagealphablending($flat, false);
        imagesavealpha($flat, false);
        $white = imagecolorallocate($flat, 255, 255, 255);
        imagefill($flat, 0, 0, $white);
        // 开启 alpha 混合，让源图的透明区域正确合并到白色背景
        imagealphablending($flat, true);
        imagecopy($flat, $src, 0, 0, 0, 0, $w, $h);
        return $flat;
    }

    /**
     * 当 GD 无法解码时，尝试按原始图片格式直接保存
     * @return string|false 返回文件名
     */
    private static function saveRawImageBinary($imageData, $fullDir, $cid)
    {
        if (empty($imageData)) {
            return false;
        }

        // 优先用 getimagesizefromstring 检测 MIME
        $ext = '';
        $info = @getimagesizefromstring($imageData);
        $mime = is_array($info) ? (string)($info['mime'] ?? '') : '';
        $extMap = [
            'image/jpeg' => 'jpg',
            'image/jpg'  => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/avif' => 'avif',
            'image/bmp'  => 'bmp',
        ];
        $ext = $extMap[strtolower($mime)] ?? '';

        // 降级：通过魔数字节判断格式（适用于 getimagesizefromstring 缺失或失败的环境）
        if ($ext === '') {
            $header = substr($imageData, 0, 12);
            if (substr($header, 0, 4) === "\x89PNG")                        $ext = 'png';
            elseif (substr($header, 0, 2) === "\xFF\xD8")                   $ext = 'jpg';
            elseif (substr($header, 0, 6) === 'GIF87a' ||
                    substr($header, 0, 6) === 'GIF89a')                     $ext = 'gif';
            elseif (substr($header, 0, 2) === 'BM')                        $ext = 'bmp';
        }

        if ($ext === '') {
            return false;
        }

        $filename = "cover_{$cid}_" . date('Ymd_His') . '.' . $ext;
        $fullPath = rtrim($fullDir, '/\\') . '/' . $filename;
        $bytes = @file_put_contents($fullPath, $imageData);
        if ($bytes === false || $bytes <= 0) {
            return false;
        }
        return $filename;
    }

    /**
     * 生成 OG 分享图（封面 + 标题文字叠加）
     */
    public static function generateOGImage($coverPath, $title, $cid, $cfg)
    {
        self::$lastError = '';
        if (empty($coverPath) || empty($title)) {
            self::$lastError = '缺少封面路径或标题';
            return false;
        }

        $fullCover = self::toAbsolutePath($coverPath);
        if (!file_exists($fullCover)) {
            self::$lastError = '封面文件不存在: ' . $fullCover;
            return false;
        }

        // 读取封面
        if (substr($coverPath, -5) === '.webp' && function_exists('imagecreatefromwebp')) {
            $img = @imagecreatefromwebp($fullCover);
        } else {
            $binary = @file_get_contents($fullCover);
            $img = $binary ? @imagecreatefromstring($binary) : false;
        }
        if (!$img) {
            // 兜底：GD 无法解码时，直接复制原图作为 OG 图（无文字叠加）
            $coverExt = strtolower(pathinfo($fullCover, PATHINFO_EXTENSION));
            if ($coverExt === '') {
                $coverExt = 'jpg';
            }
            $dir = dirname($fullCover);
            $base = pathinfo($fullCover, PATHINFO_FILENAME);
            $coverDir = rtrim(self::normalizeRelativePath(dirname($coverPath)), '/');
            if ($coverDir === '' || $coverDir === '.') {
                $coverDir = '/';
            }
            $ogPath = $dir . '/' . $base . '_og.' . $coverExt;
            $ogRelPath = self::normalizeRelativePath($coverDir . '/' . $base . '_og.' . $coverExt);
            if (@copy($fullCover, $ogPath)) {
                self::saveMeta($cid, 'aicover_og', $ogRelPath);
                self::log('OG 生成降级：复制原图（GD 无法解码）', (int)$cid);
                return $ogRelPath;
            }

            self::$lastError = '无法读取封面图像（格式不支持或文件损坏）';
            return false;
        }

        $w = imagesx($img);
        $h = imagesy($img);

        // 创建渐变半透明遮罩（底部）
        $overlay = imagecreatetruecolor($w, $h);
        imagealphablending($overlay, false);
        imagesavealpha($overlay, true);

        for ($y = 0; $y < $h; $y++) {
            $alpha = (int)(127 * (1 - max(0, ($y - $h * 0.5)) / ($h * 0.5)));
            $alpha = max(0, min(127, $alpha));
            $color = imagecolorallocatealpha($overlay, 0, 0, 0, $alpha);
            imageline($overlay, 0, $y, $w, $y, $color);
        }

        // 合并到封面
        imagecopy($img, $overlay, 0, 0, 0, 0, $w, $h);
        imagedestroy($overlay);

        // 写标题文字
        $fontFile = !empty($cfg->ogFont) && file_exists($cfg->ogFont) ? $cfg->ogFont : null;
        $white    = imagecolorallocate($img, 255, 255, 255);

        if ($fontFile) {
            // 使用 TrueType 字体，支持中文
            $fontSize   = max(18, (int)($w / 25));
            $maxWidth   = $w - 80;
            $lines      = self::wrapTextTTF($title, $fontFile, $fontSize, $maxWidth);
            $lineHeight = $fontSize + 8;
            $totalH     = count($lines) * $lineHeight;
            $startY     = $h - $totalH - 40;

            foreach ($lines as $i => $line) {
                imagettftext($img, $fontSize, 0, 40, $startY + $i * $lineHeight + $lineHeight, $white, $fontFile, $line);
            }
        } else {
            // GD 内置字体（不支持中文，英文可用）
            $font     = 5;
            $charW    = imagefontwidth($font);
            $charH    = imagefontheight($font);
            $maxChars = (int)(($w - 80) / $charW);
            $lines    = array_chunk(str_split(mb_substr($title, 0, 60)), $maxChars);
            $lines    = array_map(fn($l) => implode('', $l), $lines);
            $startY   = $h - count($lines) * ($charH + 4) - 40;

            foreach ($lines as $i => $line) {
                imagestring($img, $font, 40, $startY + $i * ($charH + 4), $line, $white);
            }
        }

        // 保存 OG 图：JPEG / PNG 降级
        $dir      = dirname($fullCover);
        $base     = pathinfo($fullCover, PATHINFO_FILENAME);
        $coverDir = rtrim(self::normalizeRelativePath(dirname($coverPath)), '/');
        if ($coverDir === '' || $coverDir === '.') {
            $coverDir = '/';
        }
        $srcExt  = strtolower(pathinfo($fullCover, PATHINFO_EXTENSION)) ?: 'jpg';
        $quality = (int)($cfg->imageSaveQuality ?? 85);

        $ogRelPath = '';

        // GD 编码保存（JPEG → PNG 降级）

        $gdFuncMap = [
            'jpg'  => function_exists('imagejpeg') ? 'imagejpeg' : null,
            'png'  => function_exists('imagepng')  ? 'imagepng'  : null,
        ];

        $saved = false;
        foreach ($gdFuncMap as $ext => $func) {
            if (!$func) {
                continue;
            }
            $tryPath = $dir . '/' . $base . '_og.' . $ext;
            $target  = ($ext === 'jpg') ? self::flattenAlpha($img) : $img;
            $args    = ($ext === 'png') ? [$target, $tryPath, 6] : [$target, $tryPath, $quality];
            $ok      = @call_user_func_array($func, $args);
            if ($target !== $img) {
                imagedestroy($target);
            }
            if ($ok && file_exists($tryPath) && filesize($tryPath) > 0) {
                $saved     = true;
                $ogRelPath = self::normalizeRelativePath($coverDir . '/' . $base . '_og.' . $ext);
                break;
            }
            if (file_exists($tryPath)) {
                @unlink($tryPath);
            }
        }

        imagedestroy($img);

        if ($saved) {
            self::saveMeta($cid, 'aicover_og', $ogRelPath);
            return $ogRelPath;
        }

        // ── OG 保存策略三：复制原封面作为 OG 图（最终保底）─────────────────
        $fallbackPath    = $dir . '/' . $base . '_og.' . $srcExt;
        $fallbackRelPath = self::normalizeRelativePath($coverDir . '/' . $base . '_og.' . $srcExt);
        if (@copy($fullCover, $fallbackPath)) {
            self::saveMeta($cid, 'aicover_og', $fallbackRelPath);
            self::log('OG 生成降级：复制原图（所有编码方式失败）', (int)$cid);
            return $fallbackRelPath;
        }

        self::$lastError = 'OG 图写入失败（所有编码方式均不可用）';
        return false;
    }

    /**
     * 自动换行（TrueType）
     */
    private static function wrapTextTTF($text, $font, $size, $maxWidth)
    {
        $words  = mb_str_split($text);
        $lines  = [];
        $line   = '';

        foreach ($words as $char) {
            $testLine = $line . $char;
            $box      = imagettfbbox($size, 0, $font, $testLine);
            $testW    = abs($box[4] - $box[0]);
            if ($testW > $maxWidth && !empty($line)) {
                $lines[] = $line;
                $line    = $char;
            } else {
                $line = $testLine;
            }
        }
        if (!empty($line)) {
            $lines[] = $line;
        }
        return $lines;
    }

    // ═══════════════════════════════════════════════════════════════════
    //  摘要与标题生成
    // ═══════════════════════════════════════════════════════════════════

    /**
     * 生成并保存摘要
     */
    public static function generateAndSaveSummary($cid, $contents)
    {
        $options = Typecho_Widget::widget('Widget_Options');
        $cfg     = $options->plugin('AICover');

        if (empty($cfg->textApiKey)) return;

        $title = strip_tags($contents['title'] ?? '');
        $body  = strip_tags($contents['text']  ?? '');
        $body  = mb_substr($body, 0, 1500);

        $sysMsg  = '你是一位专业博客编辑，请为以下文章生成一段高SEO又简洁明了的中文摘要，100字以内，不要包含"本文"、"作者"等冗余词，直接陈述核心内容。只返回摘要文本，不要其他内容。';
        $userMsg = "标题: {$title}\n\n正文节选:\n{$body}";

        $summary = self::callTextAPI($sysMsg, $userMsg, $cfg, 200);
        if (!$summary) return;

        $summary = trim($summary);

        // 保存到自定义字段，兼容 Typecho 默认表结构
        self::saveMeta($cid, 'customSummary', $summary);
    }

    /**
     * 生成标题建议（仅供 AJAX 接口使用）
     */
    public static function generateTitleSuggestions($body, $cfg)
    {
        if (empty($cfg->textApiKey)) return [];

        $body = mb_substr(strip_tags($body), 0, 800);
        $sysMsg  = '请为以下博客文章正文生成3个吸引人、高SEO的标题，风格简洁有力，每行一个，不要编号或符号，只返回标题文本。';
        $userMsg = "文章正文节选:\n{$body}";

        $result = self::callTextAPI($sysMsg, $userMsg, $cfg, 150);
        if (!$result) return [];

        return array_filter(array_map('trim', explode("\n", $result)));
    }

    // ═══════════════════════════════════════════════════════════════════
    //  OG Meta 注入
    // ═══════════════════════════════════════════════════════════════════

    /**
     * 在文章页面 <head> 注入 OG 标签
     */
    public static function renderOGMeta($archive = null)
    {
        // 在不同模板/版本下，header hook 传参可能是字符串而不是 Widget_Archive
        if (!is_object($archive) || !method_exists($archive, 'is')) {
            $archive = Typecho_Widget::widget('Widget_Archive');
        }
        if (!is_object($archive) || !method_exists($archive, 'is')) {
            return;
        }
        if (!$archive->is('single')) return;

        $options = Typecho_Widget::widget('Widget_Options');
        $cfg     = $options->plugin('AICover');
        if (!$cfg->generateOG) return;

        $cid   = (int)($archive->cid ?? 0);
        if ($cid <= 0) return;
        $ogImg = self::getMeta($cid, 'aicover_og');

        // 降级到普通封面
        if (!$ogImg) {
            $ogImg = self::getMeta($cid, 'thumb');
        }
        if (!$ogImg && isset($archive->fields)) {
            $ogImg = $archive->fields->thumb ?? '';
        }

        if (empty($ogImg)) return;

        // 处理相对路径
        if (strpos($ogImg, 'http') !== 0) {
            $siteUrl = rtrim($options->siteUrl, '/');
            $ogImg   = $siteUrl . '/' . ltrim($ogImg, '/');
        }

        $title = htmlspecialchars($archive->title, ENT_QUOTES);
        $metaDesc = self::getMeta($cid, 'customSummary');
        $rawDesc  = $metaDesc ?: ($archive->description ?? $archive->excerpt ?? '');
        $desc  = htmlspecialchars(mb_substr(strip_tags($rawDesc), 0, 120), ENT_QUOTES);
        $url   = htmlspecialchars($archive->permalink, ENT_QUOTES);

        echo <<<HTML
<!-- AICover OG Tags -->
<meta property="og:title"       content="{$title}" />
<meta property="og:description" content="{$desc}" />
<meta property="og:image"       content="{$ogImg}" />
<meta property="og:url"         content="{$url}" />
<meta property="og:type"        content="article" />
<meta name="twitter:card"        content="summary_large_image" />
<meta name="twitter:title"       content="{$title}" />
<meta name="twitter:description" content="{$desc}" />
<meta name="twitter:image"       content="{$ogImg}" />
<!-- /AICover OG Tags -->

HTML;
    }

    /**
     * 在文章页面底部注入 AI 回复队列消费触发器
     * JS 立即调用消费端点，完成后若有新回复则自动刷新页面（无需用户手动刷新）
     */
    public static function renderReplyQueueConsumer($archive = null)
    {
        try {
            $cfg = Typecho_Widget::widget('Widget_Options')->plugin('AICover');
            if (empty($cfg->replyMode) || $cfg->replyMode === 'off') {
                return;
            }
        } catch (Exception $e) {
            return;
        }

        if (!is_object($archive) || !method_exists($archive, 'is')) {
            try {
                $archive = Typecho_Widget::widget('Widget_Archive');
            } catch (Exception $e) {
                return;
            }
        }
        if (!is_object($archive) || !method_exists($archive, 'is') || !$archive->is('single')) {
            return;
        }

        $cid = (int)$archive->cid;
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
            . '})()</script>' . "\n";
    }

    // ═══════════════════════════════════════════════════════════════════
    //  编辑器面板 UI
    // ═══════════════════════════════════════════════════════════════════

    /**
     * 在文章编辑器底部注入 AI 封面操作面板
     */
    public static function renderEditorPanel($post = null)
    {
        $options  = Typecho_Widget::widget('Widget_Options');
        $cfg      = $options->plugin('AICover');
        $cid      = 0;
        if (is_object($post)) {
            if (isset($post->cid)) {
                $cid = (int)$post->cid;
            } elseif (method_exists($post, 'cid')) {
                $cid = (int)$post->cid();
            } elseif (isset($post->request) && is_object($post->request) && method_exists($post->request, 'get')) {
                $cid = (int)$post->request->get('cid', 0);
            }
        }
        $siteUrl  = rtrim($options->siteUrl, '/');
        $actionUrl = Typecho_Common::url('/action/aicover', $options->index);

        // 获取当前封面和历史
        $currentCover = '';
        $ogImg        = '';
        $history      = [];

        if ($cid) {
            $currentCover = self::getMeta($cid, 'thumb');
            $ogImg        = self::getMeta($cid, 'aicover_og');
            $history      = self::getHistory($cid);
        }

        $nonce      = self::createNonce($cid);
        $pluginDir  = Typecho_Common::url('/usr/plugins/AICover', $siteUrl);

        // 渲染面板 HTML
        ?>
<link rel="stylesheet" href="<?php echo $pluginDir; ?>/static/admin.css?v=1.0">
<div class="aicover-panel" id="aicover-panel">
    <div class="aicover-panel__header">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        <span>AI 封面生成器</span>
        <span class="aicover-badge">AICover</span>
    </div>
    <div class="aicover-panel__body">
        <!-- 当前封面预览 -->
        <div class="aicover-preview-row">
            <div class="aicover-preview-box" id="aicover-cover-box">
                <?php if ($currentCover): ?>
                    <img src="<?php echo htmlspecialchars($currentCover); ?>" alt="封面" id="aicover-current-img">
                    <span class="aicover-preview-label">当前封面</span>
                <?php else: ?>
                    <div class="aicover-placeholder">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none"><rect x="3" y="3" width="18" height="18" rx="2" stroke="currentColor" stroke-width="1.5"/><path d="m3 15 5-5 4 4 3-3 6 6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><circle cx="8.5" cy="8.5" r="1.5" fill="currentColor"/></svg>
                        <p>暂无封面</p>
                    </div>
                <?php endif; ?>
            </div>
            <?php if ($ogImg): ?>
            <div class="aicover-preview-box">
                <img src="<?php echo htmlspecialchars($ogImg); ?>" alt="OG 图" id="aicover-og-img">
                <span class="aicover-preview-label">OG 分享图</span>
            </div>
            <?php endif; ?>
        </div>

        <!-- Prompt 输入 -->
        <div class="aicover-field">
            <label class="aicover-label">
                自定义 Prompt
                <span class="aicover-hint">（留空则自动从文章内容生成）</span>
            </label>
            <textarea id="aicover-custom-prompt" class="aicover-textarea"
                placeholder="例: A futuristic cityscape at night with neon lights, cyberpunk style, no text..."></textarea>
        </div>

        <!-- 操作按钮区 -->
        <div class="aicover-actions">
            <button type="button" class="aicover-btn aicover-btn--primary" id="aicover-btn-cover"
                data-cid="<?php echo $cid; ?>" data-nonce="<?php echo $nonce; ?>"
                data-action="<?php echo $actionUrl; ?>">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z" stroke="currentColor" stroke-width="2"/></svg>
                生成 / 重新生成封面
            </button>

            <button type="button" class="aicover-btn aicover-btn--secondary" id="aicover-btn-summary"
                data-cid="<?php echo $cid; ?>" data-nonce="<?php echo $nonce; ?>"
                data-action="<?php echo $actionUrl; ?>">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" stroke="currentColor" stroke-width="2"/><polyline points="14 2 14 8 20 8" stroke="currentColor" stroke-width="2"/><line x1="16" y1="13" x2="8" y2="13" stroke="currentColor" stroke-width="2"/><line x1="16" y1="17" x2="8" y2="17" stroke="currentColor" stroke-width="2"/></svg>
                重新生成摘要
            </button>

            <button type="button" class="aicover-btn aicover-btn--secondary" id="aicover-btn-title"
                data-cid="<?php echo $cid; ?>" data-nonce="<?php echo $nonce; ?>"
                data-action="<?php echo $actionUrl; ?>">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" stroke="currentColor" stroke-width="2"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" stroke="currentColor" stroke-width="2"/></svg>
                标题建议
            </button>

            <button type="button" class="aicover-btn aicover-btn--secondary" id="aicover-btn-og"
                data-cid="<?php echo $cid; ?>" data-nonce="<?php echo $nonce; ?>"
                data-action="<?php echo $actionUrl; ?>">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><rect x="3" y="5" width="18" height="14" rx="2" stroke="currentColor" stroke-width="2"/><path d="m7 14 3-3 2 2 3-3 2 2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                生成 OG 分享图
            </button>

            <button type="button" class="aicover-btn aicover-btn--ghost" id="aicover-btn-prompt"
                data-cid="<?php echo $cid; ?>" data-nonce="<?php echo $nonce; ?>"
                data-action="<?php echo $actionUrl; ?>">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3" stroke="currentColor" stroke-width="2"/><line x1="12" y1="17" x2="12.01" y2="17" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                预览 Prompt
            </button>
        </div>

        <!-- 状态/结果区 -->
        <div class="aicover-status" id="aicover-status"></div>

        <!-- 历史记录 -->
        <?php if (!empty($history)): ?>
        <div class="aicover-history">
            <div class="aicover-history__title">生成历史</div>
            <div class="aicover-history__list" id="aicover-history-list">
                <?php foreach (array_slice($history, 0, 5) as $h): ?>
                <div class="aicover-history__item" data-path="<?php echo htmlspecialchars($h['cover_path']); ?>">
                    <img src="<?php echo htmlspecialchars($h['cover_path']); ?>" alt="历史封面">
                    <div class="aicover-history__meta">
                        <div class="aicover-history__time"><?php echo date('m-d H:i', strtotime($h['created_at'])); ?></div>
                        <div class="aicover-history__prompt"><?php echo htmlspecialchars(mb_substr($h['prompt'], 0, 60)); ?>…</div>
                        <button type="button" class="aicover-btn aicover-btn--xs aicover-history__use"
                            data-path="<?php echo htmlspecialchars($h['cover_path']); ?>"
                            data-cid="<?php echo $cid; ?>" data-nonce="<?php echo $nonce; ?>"
                            data-action="<?php echo $actionUrl; ?>">
                            使用此封面
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<script src="<?php echo $pluginDir; ?>/static/admin.js?v=1.0"></script>
        <?php
    }

    // ═══════════════════════════════════════════════════════════════════
    //  数据库工具
    // ═══════════════════════════════════════════════════════════════════

    private static function initDatabase()
    {
        if (self::$historyTableInitialized) {
            return;
        }
        $db     = Typecho_Db::get();
        $prefix = $db->getPrefix();
        $adapter = strtolower((string)$db->getAdapterName());

        if (strpos($adapter, 'sqlite') !== false) {
            $createTableSql = "CREATE TABLE IF NOT EXISTS `{$prefix}aicover_history` (
                `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                `cid` INTEGER NOT NULL,
                `prompt` TEXT NOT NULL,
                `cover_path` TEXT NOT NULL,
                `created_at` TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            );";
            $createIndexSql = "CREATE INDEX IF NOT EXISTS `{$prefix}aicover_history_cid`
                ON `{$prefix}aicover_history` (`cid`);";
        } elseif (strpos($adapter, 'pgsql') !== false || strpos($adapter, 'postgres') !== false) {
            $createTableSql = "CREATE TABLE IF NOT EXISTS \"{$prefix}aicover_history\" (
                \"id\" SERIAL PRIMARY KEY,
                \"cid\" INTEGER NOT NULL,
                \"prompt\" TEXT NOT NULL,
                \"cover_path\" VARCHAR(500) NOT NULL,
                \"created_at\" TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            );";
            $createIndexSql = "CREATE INDEX IF NOT EXISTS \"{$prefix}aicover_history_cid\"
                ON \"{$prefix}aicover_history\" (\"cid\");";
        } else {
            // 默认按 MySQL/MariaDB 语法
            $createTableSql = "CREATE TABLE IF NOT EXISTS `{$prefix}aicover_history` (
                `id`         INT(11)      NOT NULL AUTO_INCREMENT,
                `cid`        INT(11)      NOT NULL,
                `prompt`     TEXT         NOT NULL,
                `cover_path` VARCHAR(500) NOT NULL,
                `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `cid` (`cid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
            $createIndexSql = null;
        }

        try {
            $db->query($createTableSql);
            if (!empty($createIndexSql)) {
                $db->query($createIndexSql);
            }
            self::$historyTableInitialized = true;
        } catch (Exception $e) {
            self::log('历史表初始化失败: ' . $e->getMessage());
        }

        // 初始化评论回复相关表
        self::initReplyTables();
    }

    /**
     * 懒初始化 AI 回复基础设施（表结构/索引）
     * 供运行期调用，避免升级后必须手动停用再启用插件
     */
    public static function ensureReplyInfrastructure()
    {
        if (self::$replyInfrastructureChecked) {
            return;
        }
        self::$replyInfrastructureChecked = true;
        self::initDatabase();
    }

    /**
     * 获取 AI 回复隐私密钥（为空或弱默认值时返回站点级回退密钥）
     */
    public static function getReplySecret($cfg = null)
    {
        if ($cfg === null) {
            try {
                $cfg = Typecho_Widget::widget('Widget_Options')->plugin('AICover');
            } catch (Exception $e) {
                $cfg = (object)array();
            }
        }

        $secret = trim((string)($cfg->replySecret ?? ''));
        if (!self::isWeakReplySecretValue($secret)) {
            return $secret;
        }

        $options = Typecho_Widget::widget('Widget_Options');
        $seed = (string)($options->siteUrl ?? '') . '|' . (string)__TYPECHO_ROOT_DIR__;
        return hash('sha256', $seed);
    }

    /**
     * replySecret 是否为弱值（空或历史默认值）
     */
    public static function isWeakReplySecret($cfg = null)
    {
        if ($cfg === null) {
            try {
                $cfg = Typecho_Widget::widget('Widget_Options')->plugin('AICover');
            } catch (Exception $e) {
                $cfg = (object)array();
            }
        }
        return self::isWeakReplySecretValue(trim((string)($cfg->replySecret ?? '')));
    }

    private static function isWeakReplySecretValue($secret)
    {
        return $secret === '' || $secret === 'aicover_default_salt_change_in_production';
    }

    /**
     * 初始化评论回复相关数据表
     */
    private static function initReplyTables()
    {
        $db = Typecho_Db::get();
        $prefix = $db->getPrefix();
        $adapter = strtolower((string)$db->getAdapterName());

        // 待审核回复队列表
        if (strpos($adapter, 'sqlite') !== false) {
            $queueTable = "CREATE TABLE IF NOT EXISTS `{$prefix}aicover_reply_queue` (
                `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                `coid` INTEGER NOT NULL,
                `cid` INTEGER NOT NULL,
                `parent` INTEGER DEFAULT 0,
                `author` TEXT NOT NULL,
                `text` TEXT NOT NULL,
                `ai_reply` TEXT NOT NULL,
                `status` TEXT NOT NULL DEFAULT 'pending',
                `created_at` TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `processed_at` TEXT
            );";
            $queueIndex = "CREATE INDEX IF NOT EXISTS `{$prefix}aicover_reply_queue_coid`
                ON `{$prefix}aicover_reply_queue` (`coid`);";
        } elseif (strpos($adapter, 'pgsql') !== false || strpos($adapter, 'postgres') !== false) {
            $queueTable = "CREATE TABLE IF NOT EXISTS \"{$prefix}aicover_reply_queue\" (
                \"id\" SERIAL PRIMARY KEY,
                \"coid\" INTEGER NOT NULL,
                \"cid\" INTEGER NOT NULL,
                \"parent\" INTEGER DEFAULT 0,
                \"author\" TEXT NOT NULL,
                \"text\" TEXT NOT NULL,
                \"ai_reply\" TEXT NOT NULL,
                \"status\" TEXT NOT NULL DEFAULT 'pending',
                \"created_at\" TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                \"processed_at\" TIMESTAMP
            );";
            $queueIndex = "CREATE INDEX IF NOT EXISTS \"{$prefix}aicover_reply_queue_coid\"
                ON \"{$prefix}aicover_reply_queue\" (\"coid\");";
        } else {
            $queueTable = "CREATE TABLE IF NOT EXISTS `{$prefix}aicover_reply_queue` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `coid` INT(11) NOT NULL,
                `cid` INT(11) NOT NULL,
                `parent` INT(11) DEFAULT 0,
                `author` VARCHAR(200) NOT NULL,
                `text` TEXT NOT NULL,
                `ai_reply` TEXT NOT NULL,
                `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `processed_at` DATETIME,
                PRIMARY KEY (`id`),
                KEY `coid` (`coid`),
                KEY `cid` (`cid`),
                KEY `status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
            $queueIndex = null;
        }

        // 回复日志表（用于限流统计）
        if (strpos($adapter, 'sqlite') !== false) {
            $logTable = "CREATE TABLE IF NOT EXISTS `{$prefix}aicover_reply_log` (
                `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                `coid` INTEGER NOT NULL,
                `cid` INTEGER NOT NULL,
                `ip_hash` TEXT NOT NULL,
                `event_type` TEXT NOT NULL DEFAULT 'generation',
                `created_at` TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            );";
            $logIndex = "CREATE INDEX IF NOT EXISTS `{$prefix}aicover_reply_log_time`
                ON `{$prefix}aicover_reply_log` (`created_at`);";
        } elseif (strpos($adapter, 'pgsql') !== false || strpos($adapter, 'postgres') !== false) {
            $logTable = "CREATE TABLE IF NOT EXISTS \"{$prefix}aicover_reply_log\" (
                \"id\" SERIAL PRIMARY KEY,
                \"coid\" INTEGER NOT NULL,
                \"cid\" INTEGER NOT NULL,
                \"ip_hash\" TEXT NOT NULL,
                \"event_type\" VARCHAR(20) NOT NULL DEFAULT 'generation',
                \"created_at\" TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            );";
            $logIndex = "CREATE INDEX IF NOT EXISTS \"{$prefix}aicover_reply_log_time\"
                ON \"{$prefix}aicover_reply_log\" (\"created_at\");";
        } else {
            $logTable = "CREATE TABLE IF NOT EXISTS `{$prefix}aicover_reply_log` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `coid` INT(11) NOT NULL,
                `cid` INT(11) NOT NULL,
                `ip_hash` VARCHAR(64) NOT NULL,
                `event_type` VARCHAR(20) NOT NULL DEFAULT 'generation',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
            $logIndex = null;
        }

        // 建议表
        if (strpos($adapter, 'sqlite') !== false) {
            $suggestTable = "CREATE TABLE IF NOT EXISTS `{$prefix}aicover_reply_suggestions` (
                `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                `coid` INTEGER NOT NULL,
                `cid` INTEGER NOT NULL,
                `suggestion_text` TEXT NOT NULL,
                `is_used` INTEGER DEFAULT 0,
                `created_at` TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            );";
        } elseif (strpos($adapter, 'pgsql') !== false || strpos($adapter, 'postgres') !== false) {
            $suggestTable = "CREATE TABLE IF NOT EXISTS \"{$prefix}aicover_reply_suggestions\" (
                \"id\" SERIAL PRIMARY KEY,
                \"coid\" INTEGER NOT NULL,
                \"cid\" INTEGER NOT NULL,
                \"suggestion_text\" TEXT NOT NULL,
                \"is_used\" INTEGER DEFAULT 0,
                \"created_at\" TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            );";
        } else {
            $suggestTable = "CREATE TABLE IF NOT EXISTS `{$prefix}aicover_reply_suggestions` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `coid` INT(11) NOT NULL,
                `cid` INT(11) NOT NULL,
                `suggestion_text` TEXT NOT NULL,
                `is_used` TINYINT(1) DEFAULT 0,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `coid` (`coid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
        }

        // 异步任务表（前台评论请求中仅入队，后台异步消费）
        if (strpos($adapter, 'sqlite') !== false) {
            $jobTable = "CREATE TABLE IF NOT EXISTS `{$prefix}aicover_reply_jobs` (
                `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                `coid` INTEGER NOT NULL,
                `cid` INTEGER NOT NULL,
                `status` TEXT NOT NULL DEFAULT 'pending',
                `attempts` INTEGER NOT NULL DEFAULT 0,
                `payload` TEXT NOT NULL,
                `last_error` TEXT,
                `created_at` TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `started_at` TEXT,
                `processed_at` TEXT
            );";
            $jobIndex = "CREATE INDEX IF NOT EXISTS `{$prefix}aicover_reply_jobs_status`
                ON `{$prefix}aicover_reply_jobs` (`status`, `id`);";
        } elseif (strpos($adapter, 'pgsql') !== false || strpos($adapter, 'postgres') !== false) {
            $jobTable = "CREATE TABLE IF NOT EXISTS \"{$prefix}aicover_reply_jobs\" (
                \"id\" SERIAL PRIMARY KEY,
                \"coid\" INTEGER NOT NULL,
                \"cid\" INTEGER NOT NULL,
                \"status\" VARCHAR(20) NOT NULL DEFAULT 'pending',
                \"attempts\" INTEGER NOT NULL DEFAULT 0,
                \"payload\" TEXT NOT NULL,
                \"last_error\" TEXT,
                \"created_at\" TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                \"started_at\" TIMESTAMP,
                \"processed_at\" TIMESTAMP
            );";
            $jobIndex = "CREATE INDEX IF NOT EXISTS \"{$prefix}aicover_reply_jobs_status\"
                ON \"{$prefix}aicover_reply_jobs\" (\"status\", \"id\");";
        } else {
            $jobTable = "CREATE TABLE IF NOT EXISTS `{$prefix}aicover_reply_jobs` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `coid` INT(11) NOT NULL,
                `cid` INT(11) NOT NULL,
                `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
                `attempts` INT(11) NOT NULL DEFAULT 0,
                `payload` LONGTEXT NOT NULL,
                `last_error` TEXT,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `started_at` DATETIME,
                `processed_at` DATETIME,
                PRIMARY KEY (`id`),
                KEY `status_id` (`status`, `id`),
                KEY `coid` (`coid`),
                KEY `cid` (`cid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
            $jobIndex = null;
        }

        try {
            $db->query($queueTable);
            if (!empty($queueIndex)) {
                $db->query($queueIndex);
            }
            $db->query($logTable);
            if (!empty($logIndex)) {
                $db->query($logIndex);
            }
            $db->query($suggestTable);
            $db->query($jobTable);
            if (!empty($jobIndex)) {
                $db->query($jobIndex);
            }
            // 老版本升级补丁：补齐 event_type 字段（失败忽略，RateLimiter 有降级兜底）
            self::ensureReplyLogEventTypeColumn($db, $prefix, $adapter);
        } catch (Exception $e) {
            self::log('评论回复表初始化失败: ' . $e->getMessage());
        }
    }

    /**
     * 为历史安装补齐 event_type 字段
     */
    private static function ensureReplyLogEventTypeColumn($db, $prefix, $adapter)
    {
        try {
            if (strpos($adapter, 'sqlite') !== false) {
                // SQLite 旧版本兼容：不存在则添加，存在会抛异常，忽略即可
                $db->query("ALTER TABLE `{$prefix}aicover_reply_log` ADD COLUMN `event_type` TEXT NOT NULL DEFAULT 'generation';");
                return;
            }

            if (strpos($adapter, 'pgsql') !== false || strpos($adapter, 'postgres') !== false) {
                $db->query("ALTER TABLE \"{$prefix}aicover_reply_log\" ADD COLUMN IF NOT EXISTS \"event_type\" VARCHAR(20) NOT NULL DEFAULT 'generation';");
                return;
            }

            // MySQL/MariaDB
            $db->query("ALTER TABLE `{$prefix}aicover_reply_log` ADD COLUMN `event_type` VARCHAR(20) NOT NULL DEFAULT 'generation';");
        } catch (Exception $e) {
            // 已存在或适配器不支持时静默忽略
        }
    }

    public static function saveCoverToPost($cid, $path)
    {
        self::saveMeta($cid, 'thumb', self::normalizeRelativePath($path));
    }

    public static function saveMeta($cid, $key, $value)
    {
        $db = Typecho_Db::get();
        // 检查是否已有该 meta
        $existing = $db->fetchRow(
            $db->select()->from('table.fields')
               ->where('cid = ?', $cid)
               ->where('name = ?', $key)
        );
        if ($existing) {
            $db->query(
                $db->update('table.fields')
                   ->rows(['str_value' => $value])
                   ->where('cid = ?', $cid)
                   ->where('name = ?', $key)
            );
        } else {
            $db->query(
                $db->insert('table.fields')
                   ->rows([
                       'cid'       => $cid,
                       'name'      => $key,
                       'type'      => 'str',
                       'str_value' => $value,
                   ])
            );
        }
    }

    public static function getMeta($cid, $key)
    {
        $db = Typecho_Db::get();
        $row = $db->fetchRow(
            $db->select('str_value')->from('table.fields')
               ->where('cid = ?', $cid)
               ->where('name = ?', $key)
        );
        return $row ? $row['str_value'] : '';
    }

    public static function logHistory($cid, $prompt, $coverPath)
    {
        self::initDatabase();
        $db = Typecho_Db::get();
        try {
            $db->query(
                $db->insert('table.aicover_history')
                   ->rows([
                       'cid'        => $cid,
                       'prompt'     => $prompt,
                       'cover_path' => $coverPath,
                       'created_at' => date('Y-m-d H:i:s'),
                   ])
            );
        } catch (Exception $e) {
            self::log('写入历史失败: ' . $e->getMessage(), (int)$cid);
        }
    }

    public static function getHistory($cid)
    {
        self::initDatabase();
        $db = Typecho_Db::get();
        try {
            return $db->fetchAll(
                $db->select()->from('table.aicover_history')
                   ->where('cid = ?', $cid)
                   ->order('id', Typecho_Db::SORT_DESC)
                   ->limit(10)
            );
        } catch (Exception $e) {
            self::log('读取历史失败: ' . $e->getMessage(), (int)$cid);
            return [];
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    //  HTTP 工具
    // ═══════════════════════════════════════════════════════════════════

    public static function httpPost($url, $body, $headers = [], $timeout = 60)
    {
        self::$lastError = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_POST            => true,
            CURLOPT_POSTFIELDS      => $body,
            CURLOPT_HTTPHEADER      => $headers,
            CURLOPT_CONNECTTIMEOUT  => 10,
            CURLOPT_TIMEOUT         => $timeout,
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_SSL_VERIFYHOST  => 2,
            // 带鉴权头时不自动跟随重定向，避免凭据泄漏
            CURLOPT_FOLLOWLOCATION  => false,
        ]);
        $result = curl_exec($ch);
        if (curl_errno($ch)) {
            self::$lastError = 'cURL 错误: ' . curl_error($ch);
            self::log(self::$lastError);
            curl_close($ch);
            return false;
        }
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode >= 400) {
            $snippet = trim((string)$result);
            $snippet = mb_substr($snippet, 0, 300);
            self::$lastError = 'HTTP ' . $httpCode . ($snippet !== '' ? ' - ' . $snippet : '');
        }
        return $result;
    }

    public static function httpGet($url, $timeout = 30, $headers = [])
    {
        self::$lastError = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // 带鉴权头时不自动跟随重定向，避免凭据泄漏
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $result = curl_exec($ch);
        if (curl_errno($ch)) {
            self::$lastError = 'cURL 错误: ' . curl_error($ch);
            curl_close($ch);
            return false;
        }
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode >= 400) {
            $snippet = trim((string)$result);
            $snippet = mb_substr($snippet, 0, 300);
            self::$lastError = 'HTTP ' . $httpCode . ($snippet !== '' ? ' - ' . $snippet : '');
        }
        return $result;
    }

    // ═══════════════════════════════════════════════════════════════════
    //  日志
    // ═══════════════════════════════════════════════════════════════════

    public static function log($msg, $cid = 0)
    {
        $logDir  = __TYPECHO_ROOT_DIR__ . '/usr/plugins/AICover/logs';
        if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
        $logFile = $logDir . '/aicover-' . date('Y-m') . '.log';
        $line    = '[' . date('Y-m-d H:i:s') . ']' . ($cid ? "[CID:{$cid}]" : '') . ' ' . $msg . PHP_EOL;
        @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    }
}

// 运行期自注册 AI 回复钩子（避免仅依赖 activate 阶段的历史注册缓存）
if (defined('__TYPECHO_ROOT_DIR__')) {
    $replyDir = __DIR__ . '/Reply';
    if (is_dir($replyDir)) {
        foreach (['Provider.php', 'ContextBuilder.php', 'Filter.php', 'RateLimiter.php', 'ReplyGenerator.php', 'HookHandler.php'] as $file) {
            $path = $replyDir . '/' . $file;
            if (file_exists($path)) {
                require_once $path;
            }
        }
    }
    if (class_exists('AICover_Reply_HookHandler')) {
        AICover_Reply_HookHandler::registerHooks();
    }
}
