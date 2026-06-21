<template>
  <div class="page-container">
    <div class="welcome-section card">
      <div class="welcome-text">
        <h2>欢迎回来，{{ userInfo?.nickname || '用户' }}</h2>
        <p>今天是 {{ currentDate }}</p>
      </div>
      <div class="quick-actions">
        <el-button type="primary" size="large" @click="openCreateOrder">
          <el-icon><Plus /></el-icon>
          <span>创建订单</span>
        </el-button>
        <el-button size="large" @click="goRecharge">
          <el-icon><TopUp /></el-icon>
          <span>快速充值</span>
        </el-button>
      </div>
    </div>

    <div class="stats-section">
      <el-row :gutter="20">
        <el-col :span="8">
          <div class="stat-card balance-card">
            <div class="stat-icon">
              <el-icon size="32" color="#409eff"><Wallet /></el-icon>
            </div>
            <div class="stat-info">
              <div class="stat-label">可用余额</div>
              <div class="stat-value">¥{{ walletInfo?.available_balance || 0 }}</div>
            </div>
          </div>
        </el-col>
        <el-col :span="8">
          <div class="stat-card frozen-card">
            <div class="stat-icon">
              <el-icon size="32" color="#e6a23c"><Lock /></el-icon>
            </div>
            <div class="stat-info">
              <div class="stat-label">冻结金额</div>
              <div class="stat-value">¥{{ walletInfo?.frozen_amount || 0 }}</div>
            </div>
          </div>
        </el-col>
        <el-col :span="8">
          <div class="stat-card order-card">
            <div class="stat-icon">
              <el-icon size="32" color="#67c23a"><List /></el-icon>
            </div>
            <div class="stat-info">
              <div class="stat-label">待处理订单</div>
              <div class="stat-value">{{ pendingCount }}</div>
            </div>
          </div>
        </el-col>
      </el-row>
    </div>

    <div v-if="frozenOrders.length > 0" class="frozen-section card">
      <div class="section-header">
        <h3>
          <el-icon color="#e6a23c"><WarningFilled /></el-icon>
          待处理的冻结订单
        </h3>
        <el-button type="primary" link @click="goToOrders('frozen')">
          查看全部
          <el-icon><ArrowRight /></el-icon>
        </el-button>
      </div>
      <div class="frozen-list">
        <OrderCard
          v-for="order in frozenOrders.slice(0, 3)"
          :key="order.id"
          :order="order"
          @retry="handleRetryOrder"
          @cancel="handleCancelOrder"
        />
      </div>
    </div>

    <div class="recent-orders card">
      <div class="section-header">
        <h3>
          <el-icon color="#409eff"><Document /></el-icon>
          最近订单
        </h3>
        <el-button type="primary" link @click="goToOrders()">
          查看全部
          <el-icon><ArrowRight /></el-icon>
        </el-button>
      </div>
      <div v-if="recentOrders.length > 0">
        <OrderCard
          v-for="order in recentOrders"
          :key="order.id"
          :order="order"
          @retry="handleRetryOrder"
          @cancel="handleCancelOrder"
          @complete="handleCompleteOrder"
          @pay="handlePayOrder"
        />
      </div>
      <el-empty v-else description="暂无订单，创建您的第一笔订单吧" />
    </div>

    <CreateOrderDialog v-model:visible="createOrderVisible" @success="handleOrderCreated" />
  </div>
</template>

<script setup>
import { ref, computed, inject, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { getOrderList, getFrozenOrders, retryPayment, cancelOrder, completeOrder } from '@/api/order'
import OrderCard from '@/components/OrderCard.vue'
import CreateOrderDialog from '@/components/CreateOrderDialog.vue'

const router = useRouter()
const walletInfo = inject('walletInfo')
const userInfo = inject('userInfo')
const fetchWalletInfo = inject('fetchWalletInfo')
const openRechargeDialog = inject('openRechargeDialog')
const orderRefreshSignal = inject('orderRefreshSignal')
const notifyOrderRefresh = inject('notifyOrderRefresh')

const createOrderVisible = ref(false)
const recentOrders = ref([])
const frozenOrders = ref([])

const currentDate = computed(() => {
  const now = new Date()
  return now.toLocaleDateString('zh-CN', {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
    weekday: 'long'
  })
})

const pendingCount = computed(() => {
  return frozenOrders.value.length
})

const fetchData = async () => {
  try {
    const [orders, frozen] = await Promise.all([
      getOrderList(),
      getFrozenOrders()
    ])
    recentOrders.value = orders.slice(0, 5)
    frozenOrders.value = frozen
  } catch (e) {
    console.error(e)
  }
}

const openCreateOrder = () => {
  createOrderVisible.value = true
}

const goRecharge = () => {
  openRechargeDialog()
}

const goToOrders = (status = null) => {
  const query = status ? { status } : {}
  router.push({ path: '/orders', query })
}

const handleOrderCreated = (result) => {
  fetchData()
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
    fetchData()
  }
)

onMounted(() => {
  fetchData()
})
</script>

<style scoped>
.welcome-section {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.welcome-text h2 {
  font-size: 24px;
  font-weight: 600;
  color: #303133;
  margin: 0 0 8px 0;
}

.welcome-text p {
  font-size: 14px;
  color: #909399;
  margin: 0;
}

.quick-actions {
  display: flex;
  gap: 12px;
}

.stats-section {
  margin: 20px 0;
}

.stat-card {
  background: #fff;
  border-radius: 8px;
  padding: 24px;
  display: flex;
  align-items: center;
  gap: 20px;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
}

.stat-icon {
  width: 64px;
  height: 64px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #ecf5ff;
}

.frozen-card .stat-icon {
  background: #fdf6ec;
}

.order-card .stat-icon {
  background: #f0f9eb;
}

.stat-info .stat-label {
  font-size: 14px;
  color: #909399;
  margin-bottom: 8px;
}

.stat-info .stat-value {
  font-size: 28px;
  font-weight: 600;
  color: #303133;
}

.section-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 16px;
}

.section-header h3 {
  font-size: 18px;
  font-weight: 600;
  color: #303133;
  margin: 0;
  display: flex;
  align-items: center;
  gap: 8px;
}

.frozen-section {
  margin-bottom: 20px;
}

.frozen-list {
  margin-top: 12px;
}
</style>
