# AICover - Typecho AI插件

> 为 Typecho 提供文章编辑相关的 AI 工具，包括但不限于：封面生成、摘要生成、标题建议、图像 Prompt 预览、OG 分享图、封面历史管理、封面图压缩，以及 AI 评论自动回复。

---

[详细安装配置教程](https://www.xiaotiewinner.com/2026/ai-cover-doc.html)

## 功能概览

- 🎨 编辑页手动生成封面（支持自定义 Prompt）
- 🔮 自动从文章内容生成绘图 Prompt（可关闭）
- 📝 自动生成文章摘要
- 💡 根据文章内容生成标题建议
- 💬 AI 评论自动回复（支持自动/人工审核/建议三种模式）
- 📣 生成 OG 分享并自动插入head（显著提高SEO与博客知名度）
- 🗂️ 封面历史管理（设为封面 / 删除）
- 🗜️ 服务器端图片压缩（按阈值自动触发）

---

## 安装

0. 本插件基于 `Typecho v1.2.1` 与 `handsome v10.1.0` 开发，确保你的版本符合要求
1. 将 `AICover` 上传到 `usr/plugins/AICover`
2. 在 Typecho 后台启用插件
3. 进入插件设置页填写 API 参数
4. 确保封面目录有读写权限（默认目录 `/usr/uploads/ai-covers`）

---

## 当前工作模式

当前版本是**手动生成模式**（编辑页按钮触发），不依赖发布时自动生成。

---

## 插件设置项说明

### 文本生成

- `文本生成 API 端点`：文本模型接口地址（OpenAI 兼容）
- `文本生成 API Key`：文本接口密钥
- `文本生成模型`：文本模型名（用于摘要 / 标题 / Prompt 生成）

### 图像生成

- `图像生成 API 端点`：图像接口地址（可留空走默认）
- `图像生成 API Key`：图像接口密钥
- `图像生成模型`：图像模型名
- `图像尺寸`：图像尺寸（会自动校正为 >=512 且宽高为 16 的倍数）
- `图像质量`：图像生成质量参数（standard / hd）

### AI 评论回复

- `AI 回复工作模式`：选择工作方式（关闭 / 自动回复 / 人工审核 / 仅建议）
- `AI 回复 API 端点`：文本模型接口地址（OpenAI 兼容格式，如 https://api.deepseek.com）
- `AI 回复 API Key`：用于生成评论回复的 API 密钥
- `AI 回复模型名称`：模型名（如 deepseek-chat、qwen-turbo、gpt-3.5-turbo）
- `回复所有评论`：开启则回复所有符合条件的评论，关闭则只在包含关键词时回复
- `触发关键词`：评论包含这些关键词时触发回复（每行一个，留空则只回复带问号的评论）
- `回复层级限制`：仅回复一级评论，或回复所有层级
- `排除回复的作者`：不回复这些作者的评论（多个用户名用逗号分隔）
- `包含文章内容`：回复生成时是否将文章内容作为上下文
- `包含父评论`：回复评论的回复时，是否包含上级评论内容
- `最大上下文长度`：限制发送给 AI 的上下文最大字符数
- `敏感词列表`：包含这些词的评论不会触发 AI 回复（每行一个词）
- `每小时/每天最大回复数`：限流设置，0 表示无限制
- `最小/最大延迟`：回复前的延迟时间（秒），模拟人工回复
- `AI 显示名称`：在回复中显示的 AI 名称
- `显示 AI 回复标识`：是否在 AI 生成的回复旁显示标识
- `AI 回复签名`：在 AI 回复末尾添加的签名
- `AI 角色设定`：系统提示词，设定 AI 的角色和回复风格
- `隐私密钥`（replySecret）：用于哈希 IP 地址的密钥，默认为 `aicover_default_salt_change_in_production`，生产环境建议修改

### Prompt

- `默认图像生成 Prompt 后缀`：默认的图像生成的提示词后缀
- `自动从文章内容生成 Prompt`：是否先用文本 AI 生成绘图 Prompt
- `图像生成默认提示词`：自动生成绘图 Prompt 时的系统指令

### 其它功能

- `自动生成文章摘要`：是否开启摘要生成能力，发布时若摘要为空，自动用 AI 生成并填入
- `生成 OG 分享图`：在封面图上叠加标题文字，生成专用的 Open Graph 分享图
- `封面存储目录`：封面保存目录，相对于网站根目录的路径，目录权限必须可读写
- `启用封面图压缩`：JPG/PNG 保存质量（1-100）
- `OG 图标题字体路径`：TTF/OTF 字体绝对路径，用于 OG 图中文标题渲染。留空则用 GD 内置字体（英文）

### 封面压缩（服务器端）

- `启用封面图压缩`：保存封面后自动进行服务器端压缩，推荐开启，封面图体积过大会影响网站加载速度
- `压缩比例`：范围 0.1 - 1.0，压缩比例过大可能会影响图片质量，建议0.6-0.8之间
- `图片大小超出时压缩`：超出该大小才自动压缩（默认 `500KB`）

> 压缩逻辑：保存后按阈值触发；同格式压缩无收益时，自动流程可尝试转为 JPG 以进一步缩小体积。

---

## 接口与按钮对应

`/action/aicover` 支持：

- `do=cover` 生成封面
- `do=summary` 生成摘要
- `do=title` 生成标题建议
- `do=prompt` 预览最终 Prompt
- `do=og` 生成 OG 分享图
- `do=use` 将历史图设为封面
- `do=delete` 删除历史图
- `do=compress` 手动压缩指定封面
- `do=test&type=text|image` 测试接口连通性
- `do=reply_approve&id=xxx` 审核通过 AI 回复
- `do=reply_reject&id=xxx` 拒绝 AI 回复
- `do=reply_regenerate&id=xxx` 重新生成 AI 回复
- `do=reply_use_suggestion&id=xxx&coid=xxx&cid=xxx` 使用建议作为回复
- `do=reply_delete_suggestion&id=xxx` 删除建议
- `do=reply_test` 测试 AI 回复 API 连通性

---

## 字段映射（主题调用）

- 封面：`fields[thumb]`
- 摘要：`fields[customSummary]`
- OG：`fields[aicover_og]`
- AI 回复标识：AI 生成的评论在 `fields[aicover_ai_generated]` 中标记为 `1`

示例：

```php
<?php if ($this->fields->thumb): ?>
  <img src="<?php $this->fields->thumb(); ?>" alt="封面">
<?php endif; ?>
```

```php
<?php echo $this->fields->customSummary; ?>
<?php echo $this->fields->aicover_og; ?>
```

---

## 封面管理页（Panel）

支持：

- 查看历史封面、文章、Prompt、封面大小、生成时间
- 一键设为封面
- 一键删除
- 一键手动压缩
- 显示当前压缩配置（开关/比例/阈值）

---

## AI 回复管理页

支持：

- **审核队列**：查看待审核的 AI 回复，支持通过/拒绝/重新生成
- **建议列表**：查看 AI 生成的建议回复，支持使用/删除
- **统计信息**：查看今日/昨日回复数量、成功率、平均响应时间
- **API 测试**：一键测试 AI 回复 API 连通性
- **配置状态**：显示关键配置项的状态和警告

三种工作模式：
- **自动回复**：AI 自动生成并发布回复
- **人工审核**：AI 生成回复，管理员审核后发布
- **仅建议**：AI 在后台生成建议，不自动发布

---

## 日志

插件日志路径：

```text
/usr/plugins/AICover/logs/aicover-YYYY-MM.log
```

---

## 常见问题

**1）生成失败：图像 API 调用失败**  
检查 API Key、API 端点、模型名和服务器网络。

**2）压缩未生效**  
确认压缩开关已开启、文件超过阈值，且图片格式为可压缩格式（JPG/PNG）。  
如果是 PNG，压缩收益可能较小，自动流程会在必要时尝试转 JPG。

**3）中文 OG 文字乱码**  
配置 `ogFont` 为可用的中文字体文件（TTF/OTF）。

**4）AI 回复不生效**  
检查以下几点：
- AI 回复工作模式是否设置为"自动回复"或"人工审核"
- AI 回复 API 端点、API Key、模型名称是否配置正确
- 评论作者是否被排除（如设置了不回复 admin）
- 评论是否包含敏感词被过滤
- 是否超出每小时/每天的最大生成数限制（按近1小时/近24小时滚动窗口统计）
- AI 不会回复自己的评论（避免循环）
- AI 不会回复文章作者本人发布的评论（避免自问自答）
- 自动回复模式下，仅对已审核通过（approved）的评论自动发布
- 插件升级后请先“停用再启用”一次，或进入 AI 回复管理页触发懒初始化

**5）如何区分 AI 回复和普通评论**  
AI 生成的评论在数据库 `fields` 表中 `aicover_ai_generated` 字段标记为 `1`，主题可通过此字段识别并添加特殊样式。

---

## 修改履历 (Changelog)

### v1.1.1 (2026-04-08)

#### 安全修复 (Security Fixes)

- **[C-1]** 修复 ReplyAdmin.php 管理面板 XSS 漏洞：所有 `data-id` 属性值现在使用 `htmlspecialchars()` 进行转义
- **[C-2]** 修复 IP 地址直接存储问题：AI 生成评论的 IP 现在使用 SHA-256 哈希存储，保护用户隐私
- **[C-3]** 添加管理后台 AJAX 操作 CSRF 防护：所有管理操作现在需要验证 nonce token

#### 高优先级修复 (High Priority Fixes)

- **[H-1]** 修复 HookHandler.php 中 `onComment` 方法缺少输入验证的问题
- **[H-2]** 修复频率限制器 fail-open 问题：数据库错误时默认拒绝而非允许，防止滥用
- **[H-3]** 修复 Action.php 中 `@unlink` 错误抑制问题：删除文件时添加错误日志记录
- **[H-4]** 优化 HTTP 请求超时设置：添加 10 秒连接超时，防止请求无限等待

#### 中优先级改进 (Medium Priority Improvements)

- **[M-5]** 增强评论内容安全：队列和评论数据中的 author 和 text 字段现在经过 `strip_tags()` 处理

#### 低优先级优化 (Low Priority Optimizations)

- **[L-1]** 将评论线程最大深度硬编码值 (5) 提取为常量 `MAX_THREAD_DEPTH`
- **[L-2]** 将 IP 哈希盐值从硬编码改为可配置的 `replySecret`（需在插件设置中配置）

#### 行为变更 (Breaking Changes)

- 频率限制器在数据库不可用时行为变更：从"默认允许"改为"默认拒绝"，更安全但可能影响正常使用

### v1.1.2 (2026-04-08)

#### BUG 修复

- **[BUG]** 修复人工审核模式下 AI 回复未触发的问题：
  - 改用 `finishComment` 钩子替代 `register_shutdown_function`，确保在评论写入数据库后正确触发
  - 添加 Reply 类文件的预加载，避免钩子执行时类未定义
  - 重构 `onComment` 为 filter 类型（仅做初步检查），新增 `onFinishComment` 处理实际逻辑

### v1.1.3 (2026-04-08)

#### BUG 修复

- **[BUG]** 修复 AI 评论回复管理页面"当前提供商"显示错误的问题：现已通过 `detectReplyProvider()` 函数从 API 端点 URL 中正确解析提供商名称（deepseek/qwen/openai 等）
- **[BUG]** 修复前端发布评论时出现 "Cannot use object of type Widget\\Feedback as array" 错误：`HookHandler::onComment` 和 `onFinishComment` 方法现在正确处理 Typecho 传入的混合类型参数（数组或对象）
- **[BUG]** 修复审核通过时错误信息不明确的问题：`approveReply()` 方法的错误响应现在包含具体异常消息，便于调试

#### 优化

- **[OPT]** 优化 `approveReply()` 注释说明，明确标注队列数据结构中各字段含义（`coid`=触发AI回复的评论ID，`parent`=该评论的父评论ID）

### v1.1.4 (2026-04-08)

#### BUG 修复与稳定性增强

- **[FIX]** 修复 auto 模式“评论已提交但 AI 未生成/未发布”问题：在 `finishComment` 链路增加评论状态校验与关键步骤日志，定位跳过原因更直观。
- **[FIX]** 修复同一评论可能重复入审核队列/建议列表的问题：对 `manual/suggest` 改为按 `coid+cid` 幂等更新。
- **[FIX]** 修复审核并发下重复发布风险：审核通过流程增加原子抢占（`pending -> processing -> approved`）。
- **[FIX]** 修复使用建议时目标评论绑定风险：`reply_use_suggestion` 改为使用建议记录内的 `coid/cid`，不信任前端传参。
- **[FIX]** 修复限流口径偏差：限流/统计改为按“AI 生成请求”计数，并补充历史安装的 `event_type` 字段迁移兜底。
- **[FIX]** 新增“作者评论跳过”规则：文章作者本人评论不触发 AI 自动回复。
- **[FIX]** AI 发布评论资料优化：`mail/url` 使用文章作者（博主）账号资料。

#### 安全增强

- **[SEC]** AI 回复 Provider 启用 TLS 证书校验（`SSL_VERIFYPEER=true` / `SSL_VERIFYHOST=2`）。

### v1.1.5 (2026-04-08)

#### 评论回复链路修复（无感异步）

- **[FIX]** 修复“发表评论后 AI 需手动刷新页面才开始生成”的问题：评论提交后立即入队，并在同请求内触发后台异步消费请求（fire-and-forget），无需用户刷新页面。
- **[FIX]** 修复“某些环境下评论提交被 AI 同步请求阻塞导致超时失败”的问题：`finishComment` 链路不再同步调用 AI API，统一改为入队 + 后台消费。
- **[FIX]** `reply_consume` 增强为可按 `cid` 定向消费：仅处理当前文章队列任务，避免误消费其他文章任务。
- **[OPT]** 前端消费脚本改为静默兜底触发（不自动刷新页面）：在非后台异步触发场景下，仍可推进队列，不打断阅读体验。

---

## License

本项目采用 MIT 协议开源，你可以自由使用、修改与分发。  
详情请查看仓库中的 `LICENSE` 文件。

### 署名要求

二次分发、修改发布或商用时，请保留原作者署名与链接：

- 作者：小铁
- 博客：https://www.xiaotiewinner.com
