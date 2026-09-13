<?php
declare(strict_types=1);
namespace app\controller;
use app\model\InspectionItem;
use app\model\Record;
use app\model\User;
use app\service\QrService;
use app\service\RecordSequenceService;
use think\facade\Log;
use think\facade\Request;
use think\Response;
class RecordController
{
    protected function seq(): RecordSequenceService
    {
        return new RecordSequenceService();
    }

    private function normalizeCheckDate($checkDate): ?string
    {
        if (!$checkDate) {
            return null;
        }
        $checkDate = (string) $checkDate;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkDate)) {
            return '__INVALID__';
        }
        $dt = \DateTime::createFromFormat('Y-m-d', $checkDate);
        if (!$dt || $dt->format('Y-m-d') !== $checkDate) {
            return '__INVALID__';
        }
        return $checkDate;
    }

    public function index(): Response
    {
        try {
            $userId = Request::param('user_id');
            $token = Request::param('token');
            $checkDate = $this->normalizeCheckDate(Request::param('check_date'));
            $status = Request::param('status');
            if ($token) {
                $user = User::where('token', $token)->find();
                if (!$user) {
                    return api_json(['code' => 404, 'message' => '无效的 token', 'data' => null]);
                }
                if (isset($user->is_active) && (int) $user->is_active !== 1) {
                    return api_json(['code' => 403, 'message' => '账号已禁用', 'data' => null]);
                }
                $userId = $user->id;
            }
            if (!$userId) {
                return api_json(['code' => 400, 'message' => '缺少 user_id 或 token', 'data' => null]);
            }
            if ($checkDate === '__INVALID__') {
                return api_json(['code' => 400, 'message' => 'check_date 格式错误（应为 YYYY-MM-DD）', 'data' => null]);
            }
            $query = Record::with(['item'])->where('user_id', (int) $userId);
            if ($checkDate) {
                $query->where('check_date', $checkDate);
            }
            if ($status) {
                $query->where('status', $status);
            }
            $list = $query->order('sequence_key', 'asc')->select();
            return api_json(['code' => 0, 'message' => 'ok', 'data' => $list->toArray()]);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            Log::error('RecordController@index: ' . $msg . "\n" . $e->getTraceAsString());
            if (stripos($msg, 'Unknown column') !== false && stripos($msg, 'check_date') !== false) {
                return api_json(['code' => 500, 'message' => '数据库缺少 records.check_date 字段，请执行 migrate_add_check_date.sql', 'data' => null]);
            }
            return api_json(['code' => 500, 'message' => '服务器错误', 'data' => null]);
        }
    }
    /**
     * 解析单条记录的扣分值：未传时用检查项标准分，传了必须是 0～1000 的整数。
     * 返回 [score, errorMessage]。
     */
    private function resolveItemScore($rawScore, int $defaultScore): array
    {
        if ($rawScore === null || $rawScore === '') {
            return [$defaultScore, null];
        }
        if (!preg_match('/^\d+$/', (string) $rawScore)) {
            return [null, '扣分值必须是不小于 0 的整数'];
        }
        $score = (int) $rawScore;
        if ($score < 0 || $score > 1000) {
            return [null, '扣分值需在 0～1000 之间'];
        }
        return [$score, null];
    }

    /**
     * 单张保存：上传与建记录按图片逐张调用，某张失败不影响已保存的图片。
     * 参数：user_id、item_id、issue_image、check_date(可选)、score(可选，默认取检查项标准分)
     */
    public function saveOne(): Response
    {
        try {
            $userId = (int) Request::param('user_id');
            $itemId = (int) Request::param('item_id');
            $issueImage = trim((string) Request::param('issue_image', ''));
            $checkDateRaw = trim((string) Request::param('check_date', ''));
            if (!$userId) {
                return api_json(['code' => 400, 'message' => '缺少 user_id', 'data' => null]);
            }
            if (!$itemId) {
                return api_json(['code' => 400, 'message' => '请选择检查项', 'data' => null]);
            }
            if ($issueImage === '') {
                return api_json(['code' => 400, 'message' => '缺少问题图片', 'data' => null]);
            }
            $user = User::find($userId);
            if (!$user) {
                return api_json(['code' => 404, 'message' => '用户不存在', 'data' => null]);
            }
            $item = InspectionItem::find($itemId);
            if (!$item) {
                return api_json(['code' => 404, 'message' => '检查项不存在', 'data' => null]);
            }
            $checkDate = $checkDateRaw !== '' ? $this->normalizeCheckDate($checkDateRaw) : date('Y-m-d');
            if ($checkDate === '__INVALID__') {
                return api_json(['code' => 400, 'message' => 'check_date 格式错误（应为 YYYY-MM-DD）', 'data' => null]);
            }
            [$score, $scoreError] = $this->resolveItemScore(Request::param('score', null), (int) $item->score);
            if ($scoreError !== null) {
                return api_json(['code' => 400, 'message' => $scoreError, 'data' => null]);
            }

            $sequenceKey = $this->seq()->getNextSequenceKey($userId, $checkDate);
            $record = Record::create([
                'user_id'      => $userId,
                'item_id'      => $itemId,
                'item_name_snapshot'  => (string) $item->name,
                'item_score_snapshot' => $score,
                'sequence_key' => $sequenceKey,
                'issue_image'  => $issueImage,
                'status'       => 'pending',
                'check_date'   => $checkDate,
            ]);
            $record = Record::with(['item'])->find($record->id)->toArray();
            return api_json(['code' => 0, 'message' => 'ok', 'data' => $record]);
        } catch (\Throwable $e) {
            Log::error('RecordController@saveOne: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            return api_json(['code' => 500, 'message' => '服务器错误', 'data' => null]);
        }
    }

    public function save(): Response
    {
        try {
            $userId = (int) Request::param('user_id');
            $items = Request::param('items'); // [{ item_id, issue_image, score? }]
            $baseUrl = trim((string) Request::param('base_url', ''));
            if (!$userId || !is_array($items) || empty($items)) {
                return api_json(['code' => 400, 'message' => '参数错误', 'data' => null]);
            }
            $user = User::find($userId);
            if (!$user) {
                return api_json(['code' => 404, 'message' => '用户不存在', 'data' => null]);
            }
            $checkDate = trim((string) Request::param('check_date', '')) ?: date('Y-m-d');
            if ($this->normalizeCheckDate($checkDate) === '__INVALID__') {
                return api_json(['code' => 400, 'message' => 'check_date 格式错误（应为 YYYY-MM-DD）', 'data' => null]);
            }

            // 预取检查项，用于校验、写入快照，避免后续修改 inspection_items 造成历史漂移
            $itemIds = [];
            foreach ($items as $item) {
                $itemIds[] = (int) ($item['item_id'] ?? 0);
            }
            $itemMap = [];
            if (!empty($itemIds)) {
                $rows = InspectionItem::whereIn('id', array_values(array_unique($itemIds)))->select();
                foreach ($rows as $row) {
                    $itemMap[(int) $row->id] = $row;
                }
            }

            // 先做整单校验：缺信息 / 扣分非法时直接指出是第几张，一张都不入库
            foreach ($items as $i => $item) {
                $itemId = (int) ($item['item_id'] ?? 0);
                $issueImage = trim((string) ($item['issue_image'] ?? ''));
                $ordinal = $i + 1;
                if (!$itemId) {
                    return api_json(['code' => 400, 'message' => "第 {$ordinal} 张图片未选择检查项", 'data' => null]);
                }
                if (!isset($itemMap[$itemId])) {
                    return api_json(['code' => 400, 'message' => "第 {$ordinal} 张图片的检查项不存在", 'data' => null]);
                }
                if ($issueImage === '') {
                    return api_json(['code' => 400, 'message' => "第 {$ordinal} 张图片缺少图片路径", 'data' => null]);
                }
                [, $scoreError] = $this->resolveItemScore($item['score'] ?? null, (int) $itemMap[$itemId]->score);
                if ($scoreError !== null) {
                    return api_json(['code' => 400, 'message' => "第 {$ordinal} 张图片{$scoreError}", 'data' => null]);
                }
            }

            $startKey = $this->seq()->getNextSequenceKey($userId, $checkDate);

            $created = [];
            foreach ($items as $i => $item) {
                $itemId = (int) ($item['item_id'] ?? 0);
                $issueImage = trim((string) ($item['issue_image'] ?? ''));
                $defaultScore = (int) $itemMap[$itemId]->score;
                [$score] = $this->resolveItemScore($item['score'] ?? null, $defaultScore);
                $snapName = (string) $itemMap[$itemId]->name;
                $record = Record::create([
                    'user_id'      => $userId,
                    'item_id'      => $itemId,
                    'item_name_snapshot'  => $snapName,
                    'item_score_snapshot' => $score,
                    'sequence_key' => $startKey + $i,
                    'issue_image'  => $issueImage,
                    'status'       => 'pending',
                    'check_date'   => $checkDate,
                ]);
                $created[] = Record::with(['item'])->find($record->id)->toArray();
            }

            // 可选：同一步生成“带 token 链接 + 唯一二维码”
            if ($baseUrl !== '') {
                $qr = (new QrService())->generateForUser($user, $baseUrl);
                return api_json([
                    'code' => 0,
                    'message' => 'ok',
                    'data' => [
                        'records' => $created,
                        'link' => $qr['link'],
                        'qr_code_url' => $qr['qr_code_url'],
                    ],
                ]);
            }

            return api_json(['code' => 0, 'message' => 'ok', 'data' => $created]);
        } catch (\Throwable $e) {
            Log::error('RecordController@save: ' . $e->getMessage());
            return api_json(['code' => 500, 'message' => '服务器错误', 'data' => null]);
        }
    }
    public function delete(int $id): Response
    {
        try {
            $record = Record::find($id);
            if (!$record) {
                return api_json(['code' => 404, 'message' => '记录不存在', 'data' => null]);
            }
            $userId = $record->user_id;
            $seqKey = $record->sequence_key;
            $checkDate = $record->check_date ? (string) $record->check_date : null;
            $record->delete();
            $this->seq()->reorderAfterDelete($userId, $seqKey, $checkDate);
            return api_json(['code' => 0, 'message' => 'ok', 'data' => null]);
        } catch (\Throwable $e) {
            Log::error('RecordController@delete: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            return api_json(['code' => 500, 'message' => '服务器错误', 'data' => null]);
        }
    }
    public function uploadFix(int $id): Response
    {
        try {
            $record = Record::find($id);
            if (!$record) {
                return api_json(['code' => 404, 'message' => '记录不存在', 'data' => null]);
            }
            $token = (string) Request::param('token');
            if (!$token) {
                return api_json(['code' => 401, 'message' => '缺少 token', 'data' => null]);
            }
            $user = User::where('token', $token)->find();
            if (!$user) {
                return api_json(['code' => 401, 'message' => '无效的 token', 'data' => null]);
            }
            if ((int) $record->user_id !== (int) $user->id) {
                return api_json(['code' => 403, 'message' => '无权操作该记录', 'data' => null]);
            }
            $fixImage = Request::param('fix_image');
            if (!$fixImage) {
                return api_json(['code' => 400, 'message' => '缺少 fix_image', 'data' => null]);
            }
            $record->fix_image = $fixImage;
            $record->status = 'completed';
            $record->save();
            $record = Record::with(['item'])->find($record->id)->toArray();
            return api_json(['code' => 0, 'message' => 'ok', 'data' => $record]);
        } catch (\Throwable $e) {
            Log::error('RecordController@uploadFix: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            return api_json(['code' => 500, 'message' => '服务器错误', 'data' => null]);
        }
    }
}
