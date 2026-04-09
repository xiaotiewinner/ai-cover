<?php
/**
 * 频率限制器
 * 控制 AI 回复的频率，防止滥用
 *
 * @author 小铁
 * @version 1.0.0
 */

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class AICover_Reply_RateLimiter
{
    /** @var bool|null 是否支持 event_type 列 */
    private static $hasEventTypeColumn = null;

    /**
     * 检查是否超出频率限制
     *
     * @param object $cfg 插件配置
     * @return array ['allowed' => bool, 'reason' => string]
     */
    public static function checkLimit($cfg = null)
    {
        AICover_Plugin::ensureReplyInfrastructure();

        if ($cfg === null) {
            $cfg = Typecho_Widget::widget('Widget_Options')->plugin('AICover');
        }

        $maxPerHour = (int)($cfg->replyMaxPerHour ?? 10);
        $maxPerDay = (int)($cfg->replyMaxPerDay ?? 50);

        // 0 表示无限制
        if ($maxPerHour === 0 && $maxPerDay === 0) {
            return ['allowed' => true, 'reason' => '无限制'];
        }

        try {
            $db = Typecho_Db::get();
            $prefix = $db->getPrefix();

            // 获取当前小时和当天的回复数量
            $hourAgo = date('Y-m-d H:i:s', strtotime('-1 hour'));
            $dayAgo = date('Y-m-d H:i:s', strtotime('-1 day'));

            // 检查每小时限制
            if ($maxPerHour > 0) {
                $hourSelect = $db->select('COUNT(*) as count')
                    ->from("{$prefix}aicover_reply_log")
                    ->where('created_at > ?', $hourAgo);
                if (self::hasEventTypeColumn()) {
                    $hourSelect->where('event_type = ?', 'generation');
                }
                $hourCount = $db->fetchObject($hourSelect)->count;

                if ((int)$hourCount >= $maxPerHour) {
                    return ['allowed' => false, 'reason' => '已达到每小时最大回复数限制'];
                }
            }

            // 检查每天限制
            if ($maxPerDay > 0) {
                $daySelect = $db->select('COUNT(*) as count')
                    ->from("{$prefix}aicover_reply_log")
                    ->where('created_at > ?', $dayAgo);
                if (self::hasEventTypeColumn()) {
                    $daySelect->where('event_type = ?', 'generation');
                }
                $dayCount = $db->fetchObject($daySelect)->count;

                if ((int)$dayCount >= $maxPerDay) {
                    return ['allowed' => false, 'reason' => '已达到每天最大回复数限制'];
                }
            }

            return ['allowed' => true, 'reason' => '未超出限制'];

        } catch (Exception $e) {
            // 数据库错误时拒绝回复（fail closed），防止滥用
            AICover_Plugin::log('频率限制检查失败: ' . $e->getMessage());
            return ['allowed' => false, 'reason' => '频率限制检查失败，请稍后重试'];
        }
    }

    /**
     * 记录一次回复
     *
     * @param int $coid 评论ID
     * @param int $cid 文章ID
     * @param string $ip 可选的 IP 地址
     * @return bool 是否记录成功
     */
    public static function logReply($coid, $cid, $eventType = 'generation', $ip = null)
    {
        if ($ip === null) {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        }

        // 对 IP 进行哈希处理（保护隐私）
        $cfg = Typecho_Widget::widget('Widget_Options')->plugin('AICover');
        $secret = AICover_Plugin::getReplySecret($cfg);
        $ipHash = hash('sha256', $ip . $secret);

        try {
            $db = Typecho_Db::get();
            $rows = [
                'coid' => (int)$coid,
                'cid' => (int)$cid,
                'ip_hash' => $ipHash,
                'created_at' => date('Y-m-d H:i:s'),
            ];

            if (self::hasEventTypeColumn()) {
                $rows['event_type'] = (string)$eventType;
            }

            try {
                $db->query(
                    $db->insert('table.aicover_reply_log')->rows($rows)
                );
            } catch (Exception $e) {
                // 兼容未迁移旧表：去掉 event_type 再重试
                if (isset($rows['event_type'])) {
                    unset($rows['event_type']);
                    self::$hasEventTypeColumn = false;
                    $db->query(
                        $db->insert('table.aicover_reply_log')->rows($rows)
                    );
                } else {
                    throw $e;
                }
            }
            return true;
        } catch (Exception $e) {
            AICover_Plugin::log('记录回复日志失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 获取当前统计信息
     *
     * @return array
     */
    public static function getStats()
    {
        try {
            $db = Typecho_Db::get();
            $prefix = $db->getPrefix();

            $hourAgo = date('Y-m-d H:i:s', strtotime('-1 hour'));
            $dayAgo = date('Y-m-d H:i:s', strtotime('-1 day'));

            $hourSelect = $db->select('COUNT(*) as count')
                ->from("{$prefix}aicover_reply_log")
                ->where('created_at > ?', $hourAgo);
            $daySelect = $db->select('COUNT(*) as count')
                ->from("{$prefix}aicover_reply_log")
                ->where('created_at > ?', $dayAgo);

            if (self::hasEventTypeColumn()) {
                $hourSelect->where('event_type = ?', 'generation');
                $daySelect->where('event_type = ?', 'generation');
            }

            $hourCount = $db->fetchObject($hourSelect)->count;
            $dayCount = $db->fetchObject($daySelect)->count;

            return [
                'hour' => (int)$hourCount,
                'day' => (int)$dayCount,
            ];

        } catch (Exception $e) {
            return ['hour' => 0, 'day' => 0];
        }
    }

    /**
     * 清理旧日志（保留最近30天）
     *
     * @return int 清理的记录数
     */
    public static function cleanup()
    {
        try {
            $db = Typecho_Db::get();
            $cutoff = date('Y-m-d H:i:s', strtotime('-30 days'));

            $result = $db->query(
                $db->delete('table.aicover_reply_log')
                    ->where('created_at < ?', $cutoff)
            );

            return (int)$result;
        } catch (Exception $e) {
            AICover_Plugin::log('清理旧日志失败: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * 检测日志表是否包含 event_type 字段
     */
    private static function hasEventTypeColumn()
    {
        if (self::$hasEventTypeColumn !== null) {
            return self::$hasEventTypeColumn;
        }

        try {
            $db = Typecho_Db::get();
            // 读取 1 条并尝试访问字段，跨适配器通用且开销低
            $row = $db->fetchRow(
                $db->select()->from('table.aicover_reply_log')->limit(1)
            );
            if (is_array($row)) {
                self::$hasEventTypeColumn = array_key_exists('event_type', $row);
                return self::$hasEventTypeColumn;
            }

            // 空表时，尝试带字段查询来判断
            $db->fetchRow(
                $db->select('event_type')->from('table.aicover_reply_log')->limit(1)
            );
            self::$hasEventTypeColumn = true;
            return true;
        } catch (Exception $e) {
            self::$hasEventTypeColumn = false;
            return false;
        }
    }
}
