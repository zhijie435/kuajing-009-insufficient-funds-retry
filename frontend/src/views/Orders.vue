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

    <div class="order-list">
      <template v-if="orders.length > 0">
        <OrderCard
          v-for="order in orders"
          :key="order.id"
          :order="order"
          @retry="handleRetryOrder"
          @cancel="handleCancelOrder"
          @complete="handleCompleteOrder"
          @pay="handlePayOrder"
        />
      </template>
      <el-empty v-else :description="emptyText" />
    </div>

    <CreateOrderDialog v-model:visible="createOrderVisible" @success="handleOrderCreated" />
  </div>
</template>

<script setup>
import { ref, computed, inject, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { getOrderList, getFrozenOrders, retryPayment, cancelOrder, completeOrder } from '@/api/order'
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

const handleTabChange = () => {
  const query = { ...route.query }
  if (activeTab.value === 'all') {
    delete query.status
  } else {
    query.status = activeTab.value
  }
  router.replace({ path: '/orders', query })
  fetchOrders()
}

const openCreateOrder = () => {
  createOrderVisible.value = true
}

const handleOrderCreated = () => {
  fetchOrders()
  fetchFrozenOrders()
  fetchWalletInfo()
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
    fetchOrders()
    fetchFrozenOrders()
  }
)

onMounted(() => {
  if (route.query.status) {
    activeTab.value = route.query.status
  }
  fetchOrders()
  fetchFrozenOrders()
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

.order-list {
  margin-top: 20px;
}
</style>
