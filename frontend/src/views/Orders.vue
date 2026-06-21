<template>
  <div class="page-container">
    <div class="page-header">
      <h1 class="page-title">订单管理</h1>
      <el-button type="primary" size="large" @click="openCreateOrder">
        <el-icon><Plus /></el-icon>
        <span>新建订单</span>
      </el-button>
    </div>

    <div class="filter-section card">
      <el-tabs v-model="activeTab" class="order-tabs" @tab-change="handleTabChange">
        <el-tab-pane label="全部" name="all" />
        <el-tab-pane label="待支付" name="pending" />
        <el-tab-pane label="已冻结" name="frozen">
          <span class="tab-badge" v-if="frozenCount > 0">{{ frozenCount }}</span>
        </el-tab-pane>
        <el-tab-pane label="已支付" name="paid" />
        <el-tab-pane label="已完成" name="completed" />
        <el-tab-pane label="已取消" name="cancelled" />
      </el-tabs>
    </div>

    <div v-if="showBatchToolbar" class="batch-toolbar card">
      <div class="batch-info">
        <el-checkbox
          :model-value="isAllSelected"
          :indeterminate="isIndeterminate"
          @change="handleToggleAll"
        >
          全选
        </el-checkbox>
        <span class="selected-count">已选 <b>{{ selectedIds.length }}</b> / {{ selectableOrders.length }} 项</span>
      </div>
      <div class="batch-actions">
        <template v-if="activeTab === 'pending' || activeTab === 'all'">
          <el-button
            type="warning"
            :disabled="!hasPendingSelected"
            @click="handleBatchFreeze"
          >
            <el-icon><Lock /></el-icon>
            批量冻结
          </el-button>
        </template>
        <template v-if="activeTab === 'frozen' || activeTab === 'all'">
          <el-button
            type="primary"
            :disabled="!hasFrozenSelected"
            @click="handleBatchRetry"
          >
            <el-icon><RefreshRight /></el-icon>
            批量补款恢复
          </el-button>
        </template>
        <el-button @click="clearSelection">取消选择</el-button>
      </div>
    </div>

    <div class="order-list">
      <template v-if="orders.length > 0">
        <OrderCard
          v-for="order in orders"
          :key="order.id"
          :order="order"
          :selectable="showBatchToolbar"
          :selected="selectedIds.includes(order.id)"
          @retry="handleRetryOrder"
          @cancel="handleCancelOrder"
          @complete="handleCompleteOrder"
          @pay="handlePayOrder"
          @select="handleSelectOrder"
        />
      </template>
      <el-empty v-else :description="emptyText" />
    </div>

    <CreateOrderDialog v-model:visible="createOrderVisible" @success="handleOrderCreated" />

    <el-dialog
      v-model="batchResultVisible"
      :title="batchResultTitle"
      width="640px"
      :close-on-click-modal="false"
    >
      <div class="batch-result-summary">
        <div class="summary-item success">
          <el-icon color="#67c23a" size="24"><CircleCheckFilled /></el-icon>
          <div>
            <div class="summary-label">成功</div>
            <div class="summary-value">{{ batchResult.success_count || 0 }}</div>
          </div>
        </div>
        <div class="summary-item skipped" v-if="batchResult.skipped_count > 0">
          <el-icon color="#909399" size="24"><InfoFilled /></el-icon>
          <div>
            <div class="summary-label">跳过</div>
            <div class="summary-value">{{ batchResult.skipped_count }}</div>
          </div>
        </div>
        <div class="summary-item failed" v-if="batchResult.failed_count > 0">
          <el-icon color="#f56c6c" size="24"><CircleCloseFilled /></el-icon>
          <div>
            <div class="summary-label">失败</div>
            <div class="summary-value">{{ batchResult.failed_count }}</div>
          </div>
        </div>
        <div class="summary-item frozen" v-if="batchResult.frozen_count > 0">
          <el-icon color="#e6a23c" size="24"><WarningFilled /></el-icon>
          <div>
            <div class="summary-label">仍需补款</div>
            <div class="summary-value">{{ batchResult.frozen_count }}</div>
          </div>
        </div>
      </div>

      <div v-if="Number(batchResult.total_still_frozen_amount || 0) > 0" class="total-shortage">
        <el-icon color="#e6a23c"><InfoFilled /></el-icon>
        <span>补款差额合计：<b style="color:#e6a23c">¥{{ Number(batchResult.total_still_frozen_amount || 0).toFixed(2) }}</b></span>
        <span v-if="batchResult.suggest_total_recharge && Number(batchResult.suggest_total_recharge) > Number(batchResult.total_still_frozen_amount)" class="suggest-extra">
          ，建议充值 <b style="color:#f56c6c">¥{{ Number(batchResult.suggest_total_recharge).toFixed(0) }}</b> 以完成全部订单
        </span>
      </div>

      <el-tabs v-if="hasBatchResultDetail" v-model="resultTab" class="result-tabs">
        <el-tab-pane v-if="batchResult.skipped_items && batchResult.skipped_items.length > 0" label="已跳过" name="skipped">
          <div class="detail-list">
            <div v-for="(item, index) in batchResult.skipped_items" :key="'k-' + index" class="detail-item">
              <div class="detail-main">
                <div class="detail-title">
                  <el-icon color="#909399" size="16"><InfoFilled /></el-icon>
                  <span>{{ item.title || '未知订单' }}</span>
                </div>
                <div class="detail-info">
                  <span v-if="item.order_no">订单号：{{ item.order_no }}</span>
                  <span v-if="item.amount">金额：¥{{ Number(item.amount || 0).toFixed(2) }}</span>
                </div>
              </div>
              <div class="detail-reason skipped">{{ item.reason }}</div>
            </div>
          </div>
        </el-tab-pane>
        <el-tab-pane v-if="batchResult.failed_items && batchResult.failed_items.length > 0" label="失败明细" name="failed">
          <div class="detail-list">
            <div v-for="(item, index) in batchResult.failed_items" :key="'f-' + index" class="detail-item">
              <div class="detail-main">
                <div class="detail-title">
                  <el-icon color="#f56c6c" size="16"><CircleCloseFilled /></el-icon>
                  <span>{{ item.title || '未知订单' }}</span>
                </div>
                <div class="detail-info">
                  <span v-if="item.order_no">订单号：{{ item.order_no }}</span>
                  <span v-if="item.amount">金额：¥{{ Number(item.amount || 0).toFixed(2) }}</span>
                </div>
              </div>
              <div class="detail-reason">{{ item.reason }}</div>
            </div>
          </div>
        </el-tab-pane>
        <el-tab-pane v-if="batchResult.still_frozen_items && batchResult.still_frozen_items.length > 0" label="待补款明细" name="frozen">
          <div class="detail-list">
            <div v-for="(item, index) in batchResult.still_frozen_items" :key="'s-' + index" class="detail-item">
              <div class="detail-main">
                <div class="detail-title">
                  <el-icon color="#e6a23c" size="16"><WarningFilled /></el-icon>
                  <span>{{ item.title || '未知订单' }}</span>
                </div>
                <div class="detail-info">
                  <span v-if="item.order_no">订单号：{{ item.order_no }}</span>
                  <span>订单金额：¥{{ Number(item.amount || 0).toFixed(2) }}</span>
                </div>
              </div>
              <div class="detail-reason shortage">
                <el-icon color="#e6a23c"><WarningFilled /></el-icon>
                <span>还差</span>
                <b>¥{{ Number(item.shortage || 0).toFixed(2) }}</b>
                <span v-if="item.suggest_recharge > 0">
                  ，建议充值 <el-tag type="warning" size="small" effect="plain">¥{{ item.suggest_recharge }}</el-tag>
                </span>
              </div>
            </div>
          </div>
        </el-tab-pane>
      </el-tabs>

      <template #footer>
        <el-button @click="handleCloseBatchResult">关闭</el-button>
        <el-button
          v-if="batchResult.frozen_count > 0"
          type="primary"
          @click="handleRechargeForAll"
        >
          <el-icon><Wallet /></el-icon>
          <span v-if="batchResult.suggest_total_recharge && batchResult.suggest_total_recharge > batchResult.total_still_frozen_amount">
            建议充值 ¥{{ batchResult.suggest_total_recharge.toFixed(2) }} 并全部重试
          </span>
          <span v-else>
            充值 ¥{{ Number(batchResult.total_still_frozen_amount || 0).toFixed(2) }} 并全部重试
          </span>
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, computed, inject, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  getOrderList,
  getFrozenOrders,
  retryPayment,
  cancelOrder,
  completeOrder,
  batchFreezeOrders,
  batchRetryPayment
} from '@/api/order'
import OrderCard from '@/components/OrderCard.vue'
import CreateOrderDialog from '@/components/CreateOrderDialog.vue'

const route = useRoute()
const router = useRouter()

const fetchWalletInfo = inject('fetchWalletInfo')
const openRechargeDialog = inject('openRechargeDialog')
const orderRefreshSignal = inject('orderRefreshSignal')
const notifyOrderRefresh = inject('notifyOrderRefresh')

const createOrderVisible = ref(false)
const orders = ref([])
const frozenOrders = ref([])
const activeTab = ref('all')
const selectedIds = ref([])
const batchResultVisible = ref(false)
const batchResult = ref({})
const resultTab = ref('failed')

const frozenCount = computed(() => frozenOrders.value.length)

const emptyText = computed(() => {
  const map = {
    all: '暂无订单',
    pending: '暂无待支付订单',
    frozen: '暂无冻结订单',
    paid: '暂无已支付订单',
    completed: '暂无已完成订单',
    cancelled: '暂无已取消订单'
  }
  return map[activeTab.value] || '暂无订单'
})

const showBatchToolbar = computed(() => {
  return activeTab.value === 'pending' || activeTab.value === 'frozen' || activeTab.value === 'all'
})

const selectableOrders = computed(() => {
  return orders.value.filter(o => o.status === 'pending' || o.status === 'frozen')
})

const isAllSelected = computed(() => {
  if (selectableOrders.value.length === 0) return false
  return selectableOrders.value.every(o => selectedIds.value.includes(o.id))
})

const isIndeterminate = computed(() => {
  const count = selectableOrders.value.filter(o => selectedIds.value.includes(o.id)).length
  return count > 0 && count < selectableOrders.value.length
})

const hasPendingSelected = computed(() => {
  return orders.value.some(o => o.status === 'pending' && selectedIds.value.includes(o.id))
})

const hasFrozenSelected = computed(() => {
  return orders.value.some(o => o.status === 'frozen' && selectedIds.value.includes(o.id))
})

const batchResultTitle = computed(() => {
  return batchResult.value.action === 'freeze' ? '批量冻结结果' : '批量补款恢复结果'
})

const hasBatchResultDetail = computed(() => {
  return (batchResult.value.failed_items && batchResult.value.failed_items.length > 0) ||
         (batchResult.value.still_frozen_items && batchResult.value.still_frozen_items.length > 0) ||
         (batchResult.value.skipped_items && batchResult.value.skipped_items.length > 0)
})

const fetchOrders = async () => {
  try {
    const status = activeTab.value === 'all' ? null : activeTab.value
    orders.value = await getOrderList(status)
  } catch (e) {
    console.error(e)
  }
}

const fetchFrozenOrders = async () => {
  try {
    frozenOrders.value = await getFrozenOrders()
  } catch (e) {
    console.error(e)
  }
}

const refreshAllOrders = async () => {
  await Promise.all([
    fetchOrders(),
    fetchFrozenOrders()
  ])
}

const handleTabChange = () => {
  const query = { ...route.query }
  if (activeTab.value === 'all') {
    delete query.status
  } else {
    query.status = activeTab.value
  }
  router.replace({ path: '/orders', query })
  clearSelection()
  fetchOrders()
}

const openCreateOrder = () => {
  createOrderVisible.value = true
}

const handleOrderCreated = () => {
  refreshAllOrders()
  fetchWalletInfo()
}

const handleSelectOrder = (order, isSelected) => {
  if (isSelected) {
    if (!selectedIds.value.includes(order.id)) {
      selectedIds.value.push(order.id)
    }
  } else {
    const idx = selectedIds.value.indexOf(order.id)
    if (idx > -1) {
      selectedIds.value.splice(idx, 1)
    }
  }
}

const handleToggleAll = (val) => {
  if (val) {
    selectedIds.value = selectableOrders.value.map(o => o.id)
  } else {
    clearSelection()
  }
}

const clearSelection = () => {
  selectedIds.value = []
}

const handleBatchFreeze = async () => {
  const pendingIds = orders.value
    .filter(o => o.status === 'pending' && selectedIds.value.includes(o.id))
    .map(o => o.id)

  if (pendingIds.length === 0) {
    ElMessage.warning('请选择待支付状态的订单')
    return
  }

  try {
    await ElMessageBox.confirm(
      `确定要冻结选中的 ${pendingIds.length} 个订单吗？`,
      '确认批量冻结',
      {
        confirmButtonText: '确定冻结',
        cancelButtonText: '取消',
        type: 'warning'
      }
    )
  } catch (e) {
    if (e !== 'cancel') return
    return
  }

  try {
    const res = await batchFreezeOrders(pendingIds, '批量冻结')
    batchResult.value = { ...res, action: 'freeze' }

    if (res.success_count > 0) {
      ElMessage.success(`成功冻结 ${res.success_count} 个订单`)
    }

    if (res.failed_count > 0 || res.success_count > 0 || res.skipped_count > 0) {
      batchResultVisible.value = true
      if (res.failed_count > 0) {
        resultTab.value = 'failed'
      } else if (res.skipped_count > 0) {
        resultTab.value = 'skipped'
      }
    }

    clearSelection()
    refreshAllOrders()
    fetchWalletInfo()
  } catch (e) {
    console.error(e)
  }
}

const handleBatchRetry = async () => {
  const frozenIds = orders.value
    .filter(o => o.status === 'frozen' && selectedIds.value.includes(o.id))
    .map(o => o.id)

  if (frozenIds.length === 0) {
    ElMessage.warning('请选择已冻结状态的订单')
    return
  }

  try {
    await ElMessageBox.confirm(
      `确定要对选中的 ${frozenIds.length} 个冻结订单进行补款恢复吗？`,
      '确认批量补款恢复',
      {
        confirmButtonText: '确定补款',
        cancelButtonText: '取消',
        type: 'warning'
      }
    )
  } catch (e) {
    if (e !== 'cancel') return
    return
  }

  try {
    const res = await batchRetryPayment(frozenIds)
    batchResult.value = { ...res, action: 'retry' }

    if (res.success_count > 0) {
      ElMessage.success(`成功支付 ${res.success_count} 个订单`)
    }

    if (res.failed_count > 0 || res.frozen_count > 0 || res.success_count > 0) {
      batchResultVisible.value = true
      if (res.frozen_count > 0) {
        resultTab.value = 'frozen'
      } else if (res.failed_count > 0) {
        resultTab.value = 'failed'
      }
    }

    clearSelection()
    refreshAllOrders()
    fetchWalletInfo()
  } catch (e) {
    console.error(e)
  }
}

const handleCloseBatchResult = () => {
  batchResultVisible.value = false
  batchResult.value = {}
}

const handleRechargeForAll = () => {
  const shortageAmount = Number(batchResult.value.total_still_frozen_amount || 0)
  const suggestAmount = Number(batchResult.value.suggest_total_recharge || 0)
  const amount = suggestAmount > shortageAmount ? suggestAmount : shortageAmount
  if (amount <= 0) return
  batchResultVisible.value = false
  openRechargeDialog(null, amount)
}

const handleRetryOrder = async (order) => {
  try {
    const res = await retryPayment(order.id)

    if (res.success) {
      ElMessage.success('支付成功')
      notifyOrderRefresh(order.id)
      fetchWalletInfo()
    } else if (res.frozen) {
      ElMessage.warning(`余额仍然不足，还差 ¥${res.shortage.toFixed(2)}`)
      openRechargeDialog(order.id, res.shortage, order)
    }
  } catch (e) {
    console.error(e)
  }
}

const handleCancelOrder = async (order) => {
  try {
    await ElMessageBox.confirm(
      `确定要取消订单「${order.title}」吗？`,
      '确认取消',
      {
        confirmButtonText: '确定取消',
        cancelButtonText: '再想想',
        type: 'warning'
      }
    )

    await cancelOrder(order.id)
    ElMessage.success('订单已取消')
    notifyOrderRefresh(order.id)
  } catch (e) {
    if (e !== 'cancel') {
      console.error(e)
    }
  }
}

const handleCompleteOrder = async (order) => {
  try {
    await completeOrder(order.id)
    ElMessage.success('订单已完成')
    notifyOrderRefresh(order.id)
  } catch (e) {
    console.error(e)
  }
}

const handlePayOrder = (order) => {
  handleRetryOrder(order)
}

watch(
  () => orderRefreshSignal.version,
  () => {
    refreshAllOrders()
  }
)

watch(
  () => orders.value,
  () => {
    const orderIds = new Set(orders.value.map(o => o.id))
    const filtered = selectedIds.value.filter(id => orderIds.has(id))
    if (filtered.length !== selectedIds.value.length) {
      selectedIds.value = filtered
    }
  }
)

onMounted(() => {
  if (route.query.status) {
    activeTab.value = route.query.status
  }
  refreshAllOrders()
})
</script>

<style scoped>
.filter-section {
  padding: 0;
  overflow: hidden;
}

.order-tabs :deep(.el-tabs__header) {
  margin: 0;
  padding: 0 20px;
}

.order-tabs :deep(.el-tabs__nav-wrap::after) {
  height: 0;
}

.order-tabs :deep(.el-tabs__item) {
  height: 56px;
  line-height: 56px;
}

.tab-badge {
  display: inline-block;
  min-width: 18px;
  height: 18px;
  line-height: 18px;
  text-align: center;
  background: #e6a23c;
  color: #fff;
  border-radius: 9px;
  font-size: 12px;
  padding: 0 6px;
  margin-left: 4px;
}

.batch-toolbar {
  margin-top: 16px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 16px 20px;
}

.batch-info {
  display: flex;
  align-items: center;
  gap: 16px;
}

.selected-count {
  font-size: 14px;
  color: #606266;
}

.selected-count b {
  color: #409eff;
  font-size: 16px;
}

.batch-actions {
  display: flex;
  gap: 10px;
}

.order-list {
  margin-top: 20px;
}

.batch-result-summary {
  display: flex;
  gap: 24px;
  padding: 16px;
  background: #f5f7fa;
  border-radius: 8px;
  margin-bottom: 16px;
}

.summary-item {
  display: flex;
  align-items: center;
  gap: 10px;
}

.summary-label {
  font-size: 12px;
  color: #909399;
}

.summary-value {
  font-size: 22px;
  font-weight: 600;
}

.summary-item.success .summary-value {
  color: #67c23a;
}

.summary-item.failed .summary-value {
  color: #f56c6c;
}

.summary-item.frozen .summary-value {
  color: #e6a23c;
}

.total-shortage {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 10px 14px;
  background: #fdf6ec;
  border-radius: 4px;
  margin-bottom: 16px;
  font-size: 14px;
  color: #e6a23c;
}

.result-tabs {
  margin-top: 8px;
}

.detail-list {
  max-height: 360px;
  overflow-y: auto;
  padding-right: 4px;
}

.detail-item {
  padding: 14px;
  background: #fafafa;
  border-radius: 6px;
  margin-bottom: 10px;
}

.detail-item:last-child {
  margin-bottom: 0;
}

.detail-main {
  margin-bottom: 8px;
}

.detail-title {
  display: flex;
  align-items: center;
  gap: 6px;
  font-weight: 500;
  color: #303133;
  font-size: 14px;
  margin-bottom: 4px;
}

.detail-info {
  display: flex;
  gap: 16px;
  font-size: 12px;
  color: #909399;
}

.detail-reason {
  display: flex;
  align-items: center;
  gap: 4px;
  padding: 8px 12px;
  background: #fef0f0;
  color: #f56c6c;
  border-radius: 4px;
  font-size: 13px;
}

.detail-reason.shortage {
  background: #fdf6ec;
  color: #e6a23c;
  flex-wrap: wrap;
}

.detail-reason.skipped {
  background: #f4f4f5;
  color: #909399;
}

.detail-reason b {
  font-size: 15px;
}

.summary-item.skipped .summary-value {
  color: #909399;
}

.total-shortage {
  flex-wrap: wrap;
}

.suggest-extra {
  margin-left: 6px;
}
</style>
