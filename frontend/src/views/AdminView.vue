<template>
  <div class="admin-page">
    <header class="page-header">
      <h1 class="page-title">检查上传</h1>
      <p class="page-desc">选择员工、上传问题图片并关联检查项，key 序号连续、删除后自动重排，生成整改链接与二维码</p>
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
        <p class="card-hint">可一次选择多张问题图片；逐张选择检查项与扣分值后一起保存，保存失败会指明具体是哪一张，已保存的图片不会丢失</p>
      </div>

      <!-- 待保存的新图片：逐张补检查项和扣分值，显示临时 key #n -->
      <div v-if="pendingItems.length" class="card">
        <div class="card-header">
          <span class="card-icon">
            <el-icon><List /></el-icon>
          </span>
          <h2 class="card-title">为每张图片补充检查项和扣分值（保存后序号为 #{{ nextKey }}～#{{ nextKey + pendingItems.length - 1 }}）</h2>
        </div>
        <p class="card-hint-inline">逐张选择检查项并确认扣分值（默认带出该检查项分值，可修改）；缺少信息的图片会标红提示，已保存的图片不会因其他图片失败而丢失。</p>
        <div v-if="saveSummary" class="save-summary" :class="saveSummary.type">
          <el-icon><WarningFilled v-if="saveSummary.type === 'error'" /><CircleCheckFilled v-else /></el-icon>
          <span>{{ saveSummary.text }}</span>
        </div>
        <div class="pending-grid">
          <div
            v-for="(item, idx) in pendingItems"
            :key="item.uid"
            :ref="(el) => setTileRef(el, item.uid)"
            class="pending-item"
            :class="{ 'is-error': item.error, 'is-saving': item.saving, 'is-done': item.done }"
          >
            <span class="pending-key-badge">#{{ nextKey + idx }}</span>
            <el-button
              text
              class="pending-remove"
              :disabled="saving"
              title="移除该图片"
              @click="removePending(item.uid)"
            >
              <el-icon><CloseBold /></el-icon>
            </el-button>
            <div class="pending-preview">
              <img v-if="item.url" :src="item.url" alt="预览" />
              <div v-if="item.saving" class="pending-mask">
                <el-icon class="is-loading"><Loading /></el-icon>
                <span>保存中…</span>
              </div>
              <div v-else-if="item.done" class="pending-mask done">
                <el-icon><CircleCheckFilled /></el-icon>
                <span>已保存</span>
              </div>
            </div>
            <div class="pending-fields">
              <el-select
                v-model="item.itemId"
                placeholder="请选择检查项"
                class="pending-select"
                :disabled="saving"
                @change="(val) => onItemChange(item, val)"
              >
                <el-option v-for="i in inspectionItems" :key="i.id" :label="`${i.name} (-${i.score}分)`" :value="i.id">
                  <span>{{ i.name }}</span>
                  <el-tag type="danger" size="small" class="ml-2">-{{ i.score }}分</el-tag>
                </el-option>
              </el-select>
              <div class="score-row">
                <span class="score-label">扣分值</span>
                <el-input-number
                  v-model="item.score"
                  :min="0"
                  :max="100"
                  :step="1"
                  :precision="0"
                  controls-position="right"
                  size="small"
                  class="score-input"
                  :disabled="saving"
                  @change="() => clearError(item)"
                />
                <span class="score-unit">分</span>
              </div>
              <p v-if="item.error" class="pending-error">
                <el-icon><WarningFilled /></el-icon>{{ item.error }}
              </p>
            </div>
          </div>
        </div>
        <el-button type="primary" size="large" :loading="saving" class="save-btn" @click="saveRecords">
          <el-icon class="mr-2"><Check /></el-icon>
          {{ saving ? '正在逐张保存…' : `一起保存（${pendingItems.length} 张）` }}
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
import { ref, watch, computed, nextTick, onBeforeUnmount, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  UploadFilled, User, PictureFilled, List, Check, CircleCheckFilled, Picture, Delete,
  CloseBold, WarningFilled, Loading,
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
const saveSummary = ref(null)

const nextKey = computed(() => {
  if (existingRecords.value.length === 0) return 1
  const max = Math.max(...existingRecords.value.map((r) => r.sequence_key))
  return max + 1
})

// 待保存的图片：逐张维护检查项、扣分值、错误提示与保存状态
const pendingItems = ref([])
const itemMap = computed(() => {
  const m = new Map()
  inspectionItems.value.forEach((i) => m.set(i.id, i))
  return m
})

function revokeItemUrl(item) {
  if (item?.url && item.url.startsWith('blob:')) {
    URL.revokeObjectURL(item.url)
  }
}

// fileList 变化（新增/删除）时增量同步 pendingItems，保留已填的检查项、分值与错误状态
function syncPendingItems() {
  const prev = new Map(pendingItems.value.map((p) => [p.uid, p]))
  const next = []
  for (const f of fileList.value) {
    const existed = prev.get(f.uid)
    if (existed) {
      existed.raw = f.raw || existed.raw
      next.push(existed)
    } else {
      next.push({
        uid: f.uid,
        url: f.raw ? URL.createObjectURL(f.raw) : null,
        itemId: null,
        score: null,
        raw: f.raw,
        error: '',
        saving: false,
        done: false,
        uploadedPath: '',
      })
    }
  }
  for (const p of prev.values()) {
    if (!fileList.value.some((f) => f.uid === p.uid)) revokeItemUrl(p)
  }
  pendingItems.value = next
}
watch(fileList, syncPendingItems, { deep: true })

// 选择检查项后自动带出该检查项的默认扣分值
function onItemChange(item, itemId) {
  const matched = itemMap.value.get(itemId)
  if (matched) item.score = matched.score
  clearError(item)
}
function clearError(item) {
  if (item) item.error = ''
}

function removePending(uid) {
  if (saving.value) return
  const idx = pendingItems.value.findIndex((p) => p.uid === uid)
  if (idx !== -1) revokeItemUrl(pendingItems.value[idx])
  pendingItems.value = pendingItems.value.filter((p) => p.uid !== uid)
  fileList.value = fileList.value.filter((f) => f.uid !== uid)
  saveSummary.value = null
}

// 错误项滚动定位
const tileRefs = new Map()
function setTileRef(el, uid) {
  if (el) tileRefs.set(uid, el)
  else tileRefs.delete(uid)
}
function scrollToFirstInvalid() {
  nextTick(() => {
    const first = pendingItems.value.find((p) => p.error)
    const el = first && tileRefs.get(first.uid)
    if (el && el.scrollIntoView) el.scrollIntoView({ behavior: 'smooth', block: 'center' })
  })
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
  previewUrl.value = uploadFile.url || URL.createObjectURL(uploadFile.raw)
  previewVisible.value = true
}
function onUploadChange(_uploadFile, uploadFiles) {
  fileList.value = uploadFiles
}
function handleRemove() {}

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

// 取出错误信息（优先业务返回的“第 n 张…”，兜底网络错误）
function errMessage(err) {
  return err?.response?.data?.message || err?.message || '网络错误'
}

async function saveRecords() {
  if (!selectedUserId.value) {
    ElMessage.warning('请选择员工')
    return
  }
  if (saving.value || pendingItems.value.length === 0) return

  // 1) 逐张校验：检查项 + 扣分值，缺少信息时精确指出是哪一张
  const invalidKeys = []
  pendingItems.value.forEach((item, idx) => {
    const keyNo = nextKey.value + idx
    if (!item.itemId) {
      item.error = `#${keyNo} 未选择检查项`
    } else if (item.score === null || item.score === undefined || Number.isNaN(Number(item.score))) {
      item.error = `#${keyNo} 未填写扣分值`
    } else {
      item.error = ''
    }
    if (item.error) invalidKeys.push(keyNo)
  })
  if (invalidKeys.length) {
    saveSummary.value = {
      type: 'error',
      text: `以下 ${invalidKeys.length} 张图片信息不完整，无法保存：#${invalidKeys.join('、#')}`,
    }
    ElMessage.warning(`有 ${invalidKeys.length} 张图片缺少检查项或扣分值，请按红色提示补充后再保存`)
    scrollToFirstInvalid()
    return
  }

  // 2) 逐张上传 + 保存：某张失败不影响其他张，已保存的不会丢失
  saving.value = true
  saveSummary.value = null
  const baseUrl = typeof window !== 'undefined' ? window.location.origin + '/fix' : 'http://localhost:3000/fix'
  let successCount = 0
  const failedLabels = []

  for (const item of pendingItems.value) {
    if (item.done) {
      successCount += 1
      continue
    }
    item.saving = true
    item.error = ''
    try {
      // 上传过的图片复用路径，重试时不重复上传
      if (!item.uploadedPath) {
        const upRes = await api.uploadImage(item.raw, null, { skipErrorMessage: true })
        item.uploadedPath = upRes?.path || ''
      }
      if (!item.uploadedPath) throw new Error('图片上传失败')

      const saved = await api.createRecords(
        {
          user_id: selectedUserId.value,
          check_date: formatDate(selectedDate.value),
          items: [{ item_id: item.itemId, issue_image: item.uploadedPath, score: item.score }],
          base_url: baseUrl,
        },
        { skipErrorMessage: true }
      )
      if (saved && !Array.isArray(saved)) {
        resultLink.value = saved.link || resultLink.value
        resultQrUrl.value = saved.qr_code_url || resultQrUrl.value
      }
      item.done = true
      successCount += 1
    } catch (err) {
      const msg = errMessage(err)
      // 单张提交时后端的“第 n 张”指该请求内序号，这里统一标注为当前图片的 #key
      const keyNo = nextKey.value + pendingItems.value.indexOf(item)
      item.error = msg.replace(/^第\s*\d+\s*张图片?/, `#${keyNo} 这张图片`).replace(/^第\s*\d+\s*张/, `#${keyNo}`)
      if (item.error === msg && !msg.startsWith(`#${keyNo}`)) item.error = `#${keyNo}：${msg}`
      failedLabels.push(item.error)
    } finally {
      item.saving = false
    }
  }

  // 3) 刷新已有图片；从待保存列表移除已成功的（失败的保留，信息不丢、可改后重试）
  await loadRecords()
  const failedItems = pendingItems.value.filter((p) => !p.done)
  pendingItems.value.forEach((p) => {
    if (p.done) revokeItemUrl(p)
  })
  fileList.value = fileList.value.filter((f) => failedItems.some((p) => p.uid === f.uid))
  pendingItems.value = failedItems

  saving.value = false

  if (failedItems.length === 0) {
    saveSummary.value = { type: 'success', text: `${successCount} 张图片全部保存成功，已生成/更新整改链接与二维码` }
    ElMessage.success(`已保存 ${successCount} 张图片`)
  } else if (successCount > 0) {
    saveSummary.value = {
      type: 'error',
      text: `${successCount} 张已保存（不会丢失）；剩余 ${failedItems.length} 张保存失败：${failedLabels.join('；')}。可修改后再次点击保存重试。`,
    }
    ElMessage.warning(`${successCount} 张已保存，${failedItems.length} 张失败（已保留，可补充后重试）`)
    scrollToFirstInvalid()
  } else {
    saveSummary.value = {
      type: 'error',
      text: `${failedItems.length} 张图片全部保存失败：${failedLabels.join('；')}。已填写的检查项与扣分值均保留，可修改后重试。`,
    }
    ElMessage.error('全部保存失败，请查看每张图片下方的原因')
    scrollToFirstInvalid()
  }
}

function copyLink() {
  if (!resultLink.value) return
  navigator.clipboard.writeText(resultLink.value).then(() => ElMessage.success('已复制到剪贴板'))
}

onMounted(() => {
  syncPendingItems()
})
onBeforeUnmount(() => {
  pendingItems.value.forEach(revokeItemUrl)
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
  transition: box-shadow 0.2s, border-color 0.2s;
}

.pending-item:hover {
  box-shadow: 0 4px 12px rgb(0 0 0 / 0.08);
}

.pending-item.is-error {
  border-color: #ef4444;
  box-shadow: 0 0 0 2px rgba(239, 68, 68, 0.12);
}

.pending-item.is-done {
  border-color: #10b981;
}

.pending-remove {
  position: absolute;
  top: 6px;
  right: 6px;
  z-index: 2;
  width: 26px;
  height: 26px;
  padding: 0;
  border-radius: 50%;
  background: rgb(15 23 42 / 0.55);
  color: white;
}

.pending-remove:hover {
  background: rgb(239 68 68 / 0.9);
  color: white;
}

.pending-mask {
  position: absolute;
  inset: 0;
  background: rgb(15 23 42 / 0.55);
  color: white;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 6px;
  font-size: 13px;
}

.pending-mask.done {
  background: rgb(16 185 129 / 0.6);
}

.pending-mask .el-icon {
  font-size: 26px;
}

.pending-fields {
  padding: 12px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.pending-fields :deep(.el-select) {
  width: 100%;
}

.score-row {
  display: flex;
  align-items: center;
  gap: 8px;
}

.score-label {
  font-size: 13px;
  color: #64748b;
  flex-shrink: 0;
}

.score-input {
  width: 120px;
}

.score-unit {
  font-size: 13px;
  color: #64748b;
}

.pending-error {
  margin: 0;
  font-size: 12px;
  line-height: 1.5;
  color: #dc2626;
  display: flex;
  align-items: flex-start;
  gap: 4px;
  word-break: break-all;
}

.pending-error .el-icon {
  margin-top: 2px;
  flex-shrink: 0;
}

.save-summary {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  padding: 10px 14px;
  border-radius: 10px;
  font-size: 13px;
  line-height: 1.6;
  margin: -4px 0 16px;
}

.save-summary .el-icon {
  margin-top: 3px;
  flex-shrink: 0;
  font-size: 16px;
}

.save-summary.error {
  background: #fef2f2;
  color: #b91c1c;
  border: 1px solid #fecaca;
}

.save-summary.success {
  background: #ecfdf5;
  color: #047857;
  border: 1px solid #a7f3d0;
}

.pending-preview {
  aspect-ratio: 16/10;
  background: #e2e8f0;
  overflow: hidden;
}

.pending-preview img {
  width: 100%;
  height: 100%;
  object-fit: cover;
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
