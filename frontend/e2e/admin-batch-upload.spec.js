// @ts-check
// 运行前请先执行: npx playwright install chromium
// 并确保应用已启动（如 docker compose up -d 后访问 http://localhost:3000）
import { test, expect } from '@playwright/test'

// 登录管理员（使用真实后端；若环境不可用可在 CI 中改为统一 mock）
async function loginAdmin(page) {
  await page.goto('/login')
  await page.getByPlaceholder('用户名').fill('admin')
  await page.getByPlaceholder('密码').fill('admin123')
  await page.getByRole('button', { name: /登\s*录/ }).click()
  await expect(page).toHaveURL(/\/admin/, { timeout: 10000 })
}

// 通过 input[type=file] 模拟一次选择多张图片
async function chooseImages(page, count) {
  const png = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
    'base64'
  )
  const files = []
  for (let i = 0; i < count; i += 1) {
    files.push({ name: `issue_${i + 1}.png`, mimeType: 'image/png', buffer: png })
  }
  await page.locator('input[type="file"]').setInputFiles(files)
}

test.describe('管理员批量上传问题图片', () => {
  test.beforeEach(async ({ page }) => {
    // 记录列表一律返回空，避免依赖真实数据
    await page.route('**/api/records*', async (route) => {
      if (route.request().method() === 'GET') {
        await route.fulfill({ status: 200, json: { code: 0, message: 'ok', data: [] } })
      } else {
        await route.continue()
      }
    })
    await loginAdmin(page)
  })

  test('缺信息时逐张标红并指出是哪一张，保存时不发请求', async ({ page }) => {
    await chooseImages(page, 2)
    // 两张待保存卡片出现
    await expect(page.locator('.pending-item')).toHaveCount(2)
    await expect(page.getByText(/一起保存（2 张）/)).toBeVisible()

    let createCalled = false
    let uploadCalled = false
    // 与 beforeEach 的 mock 叠加：POST 标记后放行给真实后端（预期不会发生），GET 由 beforeEach 兜底
    await page.route('**/api/records*', (route) => {
      if (route.request().method() === 'POST') createCalled = true
      route.continue()
    })
    await page.route('**/api/upload/image', (route) => {
      uploadCalled = true
      route.continue()
    })

    // 不选检查项、不填扣分值，直接保存
    await page.getByRole('button', { name: /一起保存/ }).click()

    await expect(page.locator('.save-summary.error')).toContainText('#1')
    await expect(page.locator('.save-summary.error')).toContainText('#2')
    await expect(page.locator('.pending-item.is-error')).toHaveCount(2)
    await expect(page.locator('.pending-error').first()).toContainText('#1')
    expect(createCalled).toBe(false)
    expect(uploadCalled).toBe(false)
  })

  test('一张保存失败时，其他张仍然保存，失败的图片保留并标明原因', async ({ page }) => {
    await chooseImages(page, 2)
    const tiles = page.locator('.pending-item')
    await expect(tiles).toHaveCount(2)

    // 第一张：地面清洁；第二张：桌面整理（选中后自动带出扣分值）
    await tiles.nth(0).locator('.el-select').click()
    await page.locator('.el-select-dropdown__item').filter({ hasText: '地面清洁' }).click()
    await tiles.nth(1).locator('.el-select').click()
    await page.locator('.el-select-dropdown__item').filter({ hasText: '桌面整理' }).click()

    let uploadHits = 0
    await page.route('**/api/upload/image', async (route) => {
      uploadHits += 1
      await route.fulfill({
        status: 200,
        json: { code: 0, message: 'ok', data: { path: `/uploads/admin/mock_${uploadHits}.png` } },
      })
    })

    let recordHits = 0
    await page.route('**/api/records*', async (route) => {
      if (route.request().method() !== 'POST') {
        await route.fulfill({ status: 200, json: { code: 0, message: 'ok', data: [] } })
        return
      }
      recordHits += 1
      if (recordHits === 1) {
        await route.fulfill({
          status: 200,
          json: { code: 500, message: '第 1 张图片保存失败（前 0 张已保存），其余未保存', data: { saved_count: 0 } },
        })
      } else {
        await route.fulfill({
          status: 200,
          json: { code: 0, message: 'ok', data: { records: [], link: 'http://x/fix?token=t', qr_code_url: '/uploads/qr.png' } },
        })
      }
    })

    await page.getByRole('button', { name: /一起保存/ }).click()

    // 汇总提示：1 张已保存、1 张失败
    await expect(page.locator('.save-summary.error')).toContainText('1 张已保存')
    await expect(page.locator('.save-summary.error')).toContainText('1 张保存失败')
    // 失败的那张保留在待保存列表（成功的已进入“已有图片”），且标明原因、保留已填检查项
    await expect(page.locator('.pending-item')).toHaveCount(1)
    await expect(page.locator('.pending-item.is-error .pending-error')).toContainText('保存失败')
    await expect(page.locator('.pending-item .el-select')).toBeVisible()
    // 两张图都已上传（第二张成功、第一张失败后可直接重试，无需重新选图）
    expect(uploadHits).toBe(2)
  })
})
