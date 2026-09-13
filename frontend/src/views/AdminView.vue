<template>
  <div class="admin-page">
    <header class="page-header">
      <h1 class="page-title">检查上传</h1>
      <p class="page-desc">选择员工，一次选择多张问题图片，逐张补检查项与扣分值后一起保存；逐张入库，失败会指出是第几张且不影响已保存图片，序号连续、自动生成整改链接与二维码</p>
    </header>

    <section v-loading="loading" class="admin-section">
      <div class="card">
        <div class="card-header">
          <span class="card-icon">
            <el-icon><User /></el-icon>
          </span>
          <h2 class="card-title">选择员工</h2>
        </div>
        <div class="filter-row">
          <el-select v-model="selectedUserId" placeholder="请选择员工" filterable class="employee-select">
            <el-option v-for="u in users" :key="u.id" :label="u.name" :value="u.id" />
          </el-select>
          <el-date-picker
            v-model="selectedDate"
            type="date"
            placeholder="检查日期"
            value-format="YYYY-MM-DD"
            class="date-picker"
          />
        </div>
      </div>

      <!-- 该员工已有问题图片：key 横向可换行，可删除，序号连续 -->
      <div v-if="selectedUserId && existingRecords.length > 0" class="card existing-card">
        <div class="card-header">
          <span class="card-icon existing">
            <el-icon><Picture /></el-icon>
          </span>
          <h2 class="card-title">已有问题图片（#key + 检查项 + 扣分）</h2>
        </div>
        <p class="card-hint-inline">序号 #1、#2… 横向排列可换行；删除某张后序号自动连续，无跳跃。</p>
        <div class="key-grid">
          <div
            v-for="r in existingRecords"
            :key="r.id"
            class="key-tile"
          >
            <span class="key-badge">#{{ r.sequence_key }}</span>
            <div class="key-preview">
              <img :src="imageUrl(r.issue_image)" alt="问题图" @error="(e) => (e.target.style.display = 'none')" />
            </div>
            <div class="key-meta">
              <span class="key-item-name">{{ r.item_name_snapshot || r.item?.name }}</span>
              <span class="key-score">-{{ (r.item_score_snapshot ?? r.item?.score) }}分</span>
            </div>
            <el-button type="danger" text size="small" class="key-delete" @click="deleteRecord(r.id)">
              <el-icon><Delete /></el-icon> 删除
            </el-button>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-header">
          <span class="card-icon">
            <el-icon><PictureFilled /></el-icon>
          </span>
          <h2 class="card-title">上传问题图片</h2>
        </div>
        <div class="upload-wrapper">
          <el-upload
            :file-list="fileList"
            :auto-upload="false"
            :limit="20"
            multiple
            drag
            accept="image/jpeg,image/png,image/gif"
            list-type="picture-card"
            class="issue-upload"
            :on-preview="handlePreview"
            :on-remove="handleRemove"
            :on-change="onUploadChange"
          >
            <div class="upload-content">
              <el-icon class="upload-icon"><UploadFilled /></el-icon>
              <p class="upload-text">拖拽图片到此处，或 <em>点击上传</em></p>
              <p class="upload-hint">支持 JPG、PNG、GIF</p>
            </div>
          </el-upload>
        </div>
        <p class="card-hint">可一次选择多张图片；随后逐张选择检查项、填写扣分值，再一起保存。逐张入库，某张失败不影响其它已保存图片。</p>
      </div>

      <!-- 待保存的新图片：逐张补检查项与扣分值，逐张保存，失败定位到具体图片 -->
      <div v-if="pendingItems.length" class="card">
        <div class="card-header">
          <span class="card-icon">
            <el-icon><List /></el-icon>
          </span>
          <h2 class="card-title">为每张图片补全检查项与扣分值（共 {{ pendingItems.length }} 张，保存后序号连续）</h2>
        </div>
        <p class="card-hint-inline">
          逐张选择检查项、填写扣分值后一起保存；保存成功的图片立即入库，某张失败或信息缺失时会标红并指出是第几张，已保存的图片不会受影响。
        </p>
        <div class="pending-grid">
          <div
            v-for="(item, idx) in pendingItems"
            :key="item.uid"
            :data-uid="item.uid"
            class="pending-item"
            :class="{ 'is-error': !!item.error, 'is-saving': item.status === 'saving' }"
          >
            <span class="pending-key-badge">第 {{ idx + 1 }} 张</span>
            <div class="pending-preview">
              <img v-if="item.url" :src="item.url" alt="预览" />
              <span v-if="item.status === 'saving'" class="pending-mask">
                <el-icon class="is-loading"><Loading /></el-icon>保存中…
              </span>
              <span v-else-if="item.status === 'saved'" class="pending-mask success">
                <el-icon><CircleCheckFilled /></el-icon>已保存
              </span>
            </div>
            <div class="pending-form">
              <el-select
                v-model="item.itemId"
                placeholder="选择检查项"
                class="pending-select"
                :disabled="saving"
                @change="onItemChange(item)"
              >
                <el-option v-for="i in inspectionItems" :key="i.id" :label="`${i.name} (-${i.score}分)`" :value="i.id">
                  <span>{{ i.name }}</span>
                  <el-tag type="danger" size="small" class="ml-2">-{{ i.score }}分</el-tag>
                </el-option>
              </el-select>
              <div class="pending-score-wrap">
                <span class="pending-score-label">扣分</span>
                <el-input-number
                  v-model="item.score"
                  :min="0"
                  :max="1000"
                  :precision="0"
                  :controls="false"
                  placeholder="分值"
                  class="pending-score"
                  :disabled="saving"
                  @input="onScoreInput(item)"
                />
                <span class="pending-score-unit">分</span>
              </div>
            </div>
            <div v-if="item.error" class="pending-error">
              <el-icon><WarningFilled /></el-icon>
              <span>{{ item.error }}</span>
            </div>
          </div>
        </div>
        <el-button type="primary" size="large" :loading="saving" class="save-btn" @click="saveRecords">
          <el-icon class="mr-2"><Check /></el-icon>
          保存全部（{{ pendingItems.length }} 张）并生成链接与二维码
        </el-button>
      </div>

      <div v-if="resultLink" class="card result-card">
        <div class="card-header success">
          <span class="card-icon success">
            <el-icon><CircleCheckFilled /></el-icon>
          </span>
          <h2 class="card-title">已生成整改链接与二维码</h2>
        </div>
        <div class="result-content">
          <div class="result-link-wrap">
            <label class="result-label">整改链接（可复制给员工扫码或打开）：</label>
            <el-input v-model="resultLink" readonly size="large">
              <template #append>
                <el-button type="primary" @click="copyLink">复制</el-button>
              </template>
            </el-input>
          </div>
          <div v-if="resultQrUrl" class="result-qr">
            <label class="result-label">二维码：</label>
            <img :src="imageUrl(resultQrUrl)" alt="二维码" class="qr-image" />
          </div>
        </div>
      </div>
    </section>

    <el-dialog v-model="previewVisible" title="图片预览" width="80%" class="preview-dialog" append-to-body>
      <img v-if="previewUrl" :src="previewUrl" alt="预览" class="w-full rounded-lg" />
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, watch, onBeforeUnmount } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  UploadFilled,
  User,
  PictureFilled,
  List,
  Check,
  CircleCheckFilled,
  Picture,
  Delete,
  Loading,
  WarningFilled,
} from '@element-plus/icons-vue'
import { api, apiBase } from '@/api/request'

const loading = ref(false)
const saving = ref(false)
const users = ref([])
const inspectionItems = ref([])
const selectedUserId = ref(null)
const selectedDate = ref(new Date())
const existingRecords = ref([])
const fileList = ref([])
const resultLink = ref('')
const resultQrUrl = ref('')
const previewVisible = ref(false)
const previewUrl = ref('')

// 每张待保存图片：{ uid, raw, url, itemId, score, scoreTouched, status, error }
const pendingItems = ref([])
const objectUrls = new Map()

function getObjectUrl(uid, raw) {
  if (!raw) return null
  if (objectUrls.has(uid)) return objectUrls.get(uid)
  const url = URL.createObjectURL(raw)
  objectUrls.set(uid, url)
  return url
}

function releaseObjectUrl(uid) {
  const url = objectUrls.get(uid)
  if (url) {
    URL.revokeObjectURL(url)
    objectUrls.delete(uid)
  }
}

// 以 el-upload 的 fileList 为准同步待保存列表，保留已选检查项 / 扣分值 / 状态
function syncPendingItems() {
  const prev = new Map(pendingItems.value.map((p) => [p.uid, p]))
  const next = fileList.value.map((f) => {
    const old = prev.get(f.uid)
    if (old) {
      return { ...old, raw: f.raw || old.raw, url: getObjectUrl(f.uid, f.raw) || old.url }
    }
    return {
      uid: f.uid,
      raw: f.raw,
      url: getObjectUrl(f.uid, f.raw),
      itemId: null,
      score: null,
      scoreTouched: false,
      status: 'idle', // idle | saving | saved
      error: '',
    }
  })
  for (const uid of objectUrls.keys()) {
    if (!fileList.value.some((f) => f.uid === uid)) releaseObjectUrl(uid)
  }
  pendingItems.value = next
}

function formatDate(date) {
  if (!date) return ''
  if (typeof date === 'string') return date
  const d = new Date(date)
  const y = d.getFullYear()
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${y}-${m}-${day}`
}

async function loadRecords() {
  if (!selectedUserId.value) {
    existingRecords.value = []
    return
  }
  try {
    const list = await api.getRecords({
      user_id: selectedUserId.value,
      check_date: formatDate(selectedDate.value),
    })
    existingRecords.value = list || []
  } catch (_) {
    existingRecords.value = []
  }
}
watch(selectedUserId, loadRecords)
watch(selectedDate, loadRecords)

async function deleteRecord(id) {
  try {
    await ElMessageBox.confirm('删除后序号将自动连续重排，确定删除该条？', '确认删除', {
      confirmButtonText: '删除',
      cancelButtonText: '取消',
      type: 'warning',
    })
    await api.deleteRecord(id)
    await loadRecords()
    ElMessage.success('已删除，序号已连续')
  } catch (e) {
    if (e !== 'cancel') ElMessage.error('删除失败')
  }
}

function imageUrl(path) {
  if (!path) return ''
  const base = apiBase() || (typeof window !== 'undefined' ? window.location.origin : '')
  return path.startsWith('http') ? path : (base.replace(/\/$/, '') + path)
}

function handlePreview(uploadFile) {
  previewUrl.value = uploadFile.url || (uploadFile.raw ? getObjectUrl(uploadFile.uid, uploadFile.raw) : '')
  previewVisible.value = true
}
function onUploadChange(_uploadFile, uploadFiles) {
  fileList.value = uploadFiles
  syncPendingItems()
}
function handleRemove(_uploadFile, uploadFiles) {
  fileList.value = uploadFiles
  syncPendingItems()
}

// 选择检查项后自动带出标准扣分值（管理员手改过则不覆盖）
function onItemChange(item) {
  if (item.scoreTouched) return
  const def = inspectionItems.value.find((i) => i.id === item.itemId)
  item.score = def ? def.score : null
  if (item.error) item.error = ''
}
// 一旦手动改过扣分值，就不再随检查项选择覆盖
function onScoreInput(item) {
  item.scoreTouched = true
  if (item.error) item.error = ''
}

async function loadUsers() {
  loading.value = true
  try {
    users.value = await api.getUsers()
    if (users.value.length && !selectedUserId.value) selectedUserId.value = users.value[0].id
  } finally {
    loading.value = false
  }
}
async function loadItems() {
  try {
    inspectionItems.value = await api.getInspectionItems()
  } catch (_) {}
}

function describeError(item) {
  if (!item.itemId) return '请选择检查项'
  if (item.score === null || item.score === undefined || Number.isNaN(item.score)) return '请填写扣分值'
  if (!Number.isInteger(item.score)) return '扣分值必须是整数'
  if (item.score < 0) return '扣分值不能为负'
  return ''
}

function scrollToPending(uid) {
  if (!uid) return
  const el = document.querySelector(`.pending-item[data-uid="${uid}"]`)
  if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' })
}

async function saveRecords() {
  if (!selectedUserId.value) {
    ElMessage.warning('请选择员工')
    return
  }
  if (!pendingItems.value.length) return

  // 保存前逐张校验，缺信息的直接标红并定位到第一张，不发起任何请求
  let firstInvalidUid = null
  for (const item of pendingItems.value) {
    const msg = describeError(item)
    item.error = msg
    if (msg && !firstInvalidUid) firstInvalidUid = item.uid
  }
  if (firstInvalidUid) {
    const idx = pendingItems.value.findIndex((p) => p.uid === firstInvalidUid) + 1
    const bad = pendingItems.value[idx - 1]
    ElMessage.warning(`第 ${idx} 张图片${bad.error}，请补全后再保存`)
    scrollToPending(firstInvalidUid)
    return
  }

  saving.value = true
  const checkDate = formatDate(selectedDate.value)
  let okCount = 0
  const failNames = []
  try {
    // 逐张「上传图片 + 建记录」：某张失败只影响它自己，前面已保存的不会回滚
    for (const item of pendingItems.value) {
      const ordinal = pendingItems.value.findIndex((p) => p.uid === item.uid) + 1
      item.status = 'saving'
      item.error = ''
      try {
        const upRes = await api.uploadImage(item.raw, null, { skipErrorMessage: true })
        if (!upRes?.path) throw new Error('图片上传失败')
        await api.createRecord(
          {
            user_id: selectedUserId.value,
            check_date: checkDate,
            item_id: item.itemId,
            score: item.score,
            issue_image: upRes.path,
          },
          { skipErrorMessage: true }
        )
        item.status = 'saved'
        okCount += 1
      } catch (e) {
        item.status = 'idle'
        item.error = e?.response?.data?.message || e.message || '保存失败'
        failNames.push(`第 ${ordinal} 张（${item.error}）`)
      }
    }

    // 已保存的立即落库并移出待存列表（连同 blob URL），失败的保留供修正后重试
    const successUids = new Set(pendingItems.value.filter((p) => p.status === 'saved').map((p) => p.uid))
    if (successUids.size) {
      fileList.value = fileList.value.filter((f) => !successUids.has(f.uid))
      syncPendingItems()
      await loadRecords()

      const baseUrl = typeof window !== 'undefined' ? window.location.origin + '/fix' : 'http://localhost:3000/fix'
      try {
        const qrData = await api.generateQr(selectedUserId.value, baseUrl)
        resultLink.value = qrData?.link || ''
        resultQrUrl.value = qrData?.qr_code_url || ''
      } catch (_) {}
    }

    if (!failNames.length) {
      ElMessage.success(`已保存 ${okCount} 张图片，并生成整改链接与二维码`)
    } else if (okCount > 0) {
      ElMessage.warning(`已保存 ${okCount} 张；以下图片保存失败且未丢失，补全后可重试：${failNames.join('；')}`)
      scrollToPending(pendingItems.value[0]?.uid)
    } else {
      ElMessage.error(`全部 ${pendingItems.value.length} 张保存失败：${failNames.join('；')}`)
      scrollToPending(pendingItems.value[0]?.uid)
    }
  } finally {
    saving.value = false
  }
}

function copyLink() {
  if (!resultLink.value) return
  navigator.clipboard.writeText(resultLink.value).then(() => ElMessage.success('已复制到剪贴板'))
}

onBeforeUnmount(() => {
  for (const uid of Array.from(objectUrls.keys())) releaseObjectUrl(uid)
})
loadUsers()
loadItems()
</script>

<style scoped>
.admin-page {
  max-width: 1120px;
  margin: 0 auto;
}

.page-header {
  margin-bottom: 32px;
}

.page-title {
  font-size: 28px;
  font-weight: 700;
  color: #0f172a;
  margin: 0 0 8px;
  letter-spacing: -0.02em;
}

.page-desc {
  font-size: 15px;
  color: #64748b;
  margin: 0;
}

.admin-section {
  display: flex;
  flex-direction: column;
  gap: 24px;
}

.card {
  background: white;
  border-radius: 16px;
  padding: 24px;
  box-shadow: 0 1px 3px rgb(0 0 0 / 0.06);
  border: 1px solid rgba(0, 0, 0, 0.04);
}

.card-header {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 20px;
}

.card-icon {
  width: 40px;
  height: 40px;
  border-radius: 12px;
  background: linear-gradient(135deg, #0ea5e9 0%, #06b6d4 100%);
  color: white;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
}

.card-header.success .card-icon {
  background: linear-gradient(135deg, #10b981 0%, #059669 100%);
}

.card-header .card-icon.existing {
  background: linear-gradient(135deg, #8b5cf6 0%, #a78bfa 100%);
}

.card-hint-inline {
  font-size: 13px;
  color: #94a3b8;
  margin: -8px 0 16px;
}

.key-grid {
  display: flex;
  flex-wrap: wrap;
  gap: 16px;
}

.key-tile {
  position: relative;
  width: 140px;
  background: #f8fafc;
  border-radius: 12px;
  overflow: hidden;
  border: 1px solid #e2e8f0;
  padding-bottom: 36px;
  transition: box-shadow 0.2s;
}

.key-tile:hover {
  box-shadow: 0 4px 12px rgb(0 0 0 / 0.08);
}

.key-badge {
  position: absolute;
  top: 8px;
  left: 8px;
  background: rgba(14, 165, 233, 0.95);
  color: white;
  padding: 2px 8px;
  border-radius: 6px;
  font-size: 12px;
  font-weight: 700;
  z-index: 1;
}

.key-preview {
  aspect-ratio: 4/3;
  background: #e2e8f0;
  overflow: hidden;
}

.key-preview img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.key-meta {
  padding: 8px 10px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 4px;
}

.key-item-name {
  font-size: 12px;
  color: #475569;
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.key-score {
  font-size: 12px;
  font-weight: 600;
  color: #ef4444;
  flex-shrink: 0;
}

.key-delete {
  position: absolute;
  bottom: 6px;
  right: 6px;
}

.pending-key-badge {
  position: absolute;
  top: 8px;
  left: 8px;
  background: rgba(14, 165, 233, 0.95);
  color: white;
  padding: 2px 8px;
  border-radius: 6px;
  font-size: 12px;
  font-weight: 700;
  z-index: 1;
}

.card-title {
  font-size: 18px;
  font-weight: 600;
  color: #1e293b;
  margin: 0;
}

.employee-select {
  max-width: 280px;
}

.filter-row {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  align-items: center;
}

.date-picker {
  width: 200px;
}

.upload-wrapper {
  overflow: hidden;
}

.card-hint {
  margin-top: 16px;
  font-size: 13px;
  color: #94a3b8;
  clear: both;
  display: block;
}

/* picture-card 模式下：触发区占满整行，虚线四边完整显示 */
.issue-upload :deep(.el-upload-list--picture-card) {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.issue-upload :deep(.el-upload-list--picture-card .el-upload-list__item) {
  margin: 0;
}

.issue-upload :deep(.el-upload.el-upload--picture-card) {
  width: 100%;
  min-height: 150px;
  height: auto;
  margin: 0;
  border: none;
  border-radius: 0;
  overflow: visible;
}

.issue-upload :deep(.el-upload-dragger) {
  width: 100%;
  min-height: 150px;
  padding: 20px 16px;
  border-radius: 12px;
  border: 2px dashed #e2e8f0;
  background: #fafbfc;
  transition: all 0.2s;
  box-sizing: border-box;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
}

.issue-upload :deep(.el-upload-dragger:hover) {
  border-color: #0ea5e9;
  background: rgba(14, 165, 233, 0.04);
}

.upload-content {
  text-align: center;
}

.upload-icon {
  font-size: 40px;
  color: #94a3b8;
  margin-bottom: 8px;
}

.issue-upload :deep(.el-upload-dragger:hover) .upload-icon {
  color: #0ea5e9;
}

.upload-text {
  font-size: 14px;
  color: #64748b;
  margin: 0 0 4px;
  line-height: 1.5;
}

.upload-text em {
  color: #0ea5e9;
  font-style: normal;
  font-weight: 500;
}

.upload-hint {
  font-size: 12px;
  color: #94a3b8;
  margin: 0;
}

.pending-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  gap: 16px;
  margin-bottom: 24px;
}

.pending-item {
  position: relative;
  background: #f8fafc;
  border-radius: 12px;
  overflow: hidden;
  border: 1px solid #e2e8f0;
  transition: box-shadow 0.2s;
}

.pending-item:hover {
  box-shadow: 0 4px 12px rgb(0 0 0 / 0.08);
}

.pending-preview {
  position: relative;
  aspect-ratio: 16/10;
  background: #e2e8f0;
  overflow: hidden;
}

.pending-preview img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.pending-form {
  padding: 12px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.pending-select {
  width: 100%;
}

.pending-score-wrap {
  display: flex;
  align-items: center;
  gap: 8px;
}

.pending-score-label {
  font-size: 13px;
  color: #475569;
  flex-shrink: 0;
}

.pending-score {
  flex: 1;
  min-width: 0;
}

.pending-score-unit {
  font-size: 13px;
  color: #64748b;
  flex-shrink: 0;
}

.pending-mask {
  position: absolute;
  inset: 0;
  background: rgb(15 23 42 / 0.55);
  color: white;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  font-size: 13px;
  font-weight: 600;
  z-index: 2;
}

.pending-mask.success {
  background: rgb(16 185 129 / 0.72);
}

.pending-item.is-error {
  border-color: #ef4444;
  box-shadow: 0 0 0 2px rgb(239 68 68 / 0.15);
}

.pending-item.is-saving {
  border-color: #0ea5e9;
}

.pending-error {
  display: flex;
  align-items: flex-start;
  gap: 6px;
  padding: 0 12px 12px;
  font-size: 12px;
  line-height: 1.4;
  color: #dc2626;
}

.pending-error .el-icon {
  margin-top: 1px;
  flex-shrink: 0;
}

.save-btn {
  height: 48px;
  padding: 0 28px;
  font-size: 15px;
  font-weight: 600;
  border-radius: 12px;
}

.mr-2 {
  margin-right: 8px;
}

.result-card {
  border-color: rgba(16, 185, 129, 0.2);
  background: linear-gradient(to bottom, rgba(16, 185, 129, 0.03), white);
}

.result-content {
  display: flex;
  flex-wrap: wrap;
  gap: 32px;
  align-items: flex-start;
}

.result-link-wrap {
  flex: 1;
  min-width: 0;
}

.result-label {
  display: block;
  font-size: 13px;
  font-weight: 500;
  color: #64748b;
  margin-bottom: 8px;
}

.result-qr {
  flex-shrink: 0;
}

.qr-image {
  width: 180px;
  height: 180px;
  border-radius: 12px;
  border: 1px solid #e2e8f0;
  object-fit: contain;
  background: white;
}

.preview-dialog :deep(.el-dialog__body) {
  padding: 16px;
}
</style>
