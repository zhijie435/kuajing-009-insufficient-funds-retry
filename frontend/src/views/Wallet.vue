<template>
  <div class="page-container">
    <div class="page-header">
      <h1 class="page-title">我的钱包</h1>
      <el-button type="primary" size="large" @click="openRecharge">
        <el-icon><TopUp /></el-icon>
        <span>充值</span>
      </el-button>
    </div>

    <div class="wallet-overview card">
      <div class="overview-main">
        <div class="balance-section">
          <div class="balance-label">可用余额</div>
          <div class="balance-amount">¥{{ walletInfo?.available_balance || 0 }}</div>
          <div class="balance-sub">
            总余额 ¥{{ walletInfo?.balance || 0 }}
            <span class="divider">|</span>
            冻结金额 ¥{{ walletInfo?.frozen_amount || 0 }}
          </div>
        </div>
        <div class="actions-section">
          <div class="action-item" @click="openRecharge">
            <div class="action-icon recharge">
            <el-icon size="24"><TopUp /></el-icon>
            </div>
            <span>充值</span>
          </div>
          <div class="action-item" @click="goToFrozenOrders">
            <div class="action-icon frozen">
              <el-icon size="24"><Lock /></el-icon>
            </div>
            <span>冻结订单</span>
          </div>
        </div>
      </div>
    </div>

    <div class="wallet-detail card">
      <el-tabs v-model="activeTab" class="record-tabs">
        <el-tab-pane label="交易明细" name="transactions" />
        <el-tab-pane label="充值记录" name="recharge" />
      </el-tabs>

      <div v-if="activeTab === 'transactions'" class="record-list">
        <template v-if="transactions.length > 0">
          <div
            v-for="item in transactions"
            :key="item.id"
            class="record-item"
          >
            <div class="record-icon" :class="getTxType(item.type)">
              <el-icon size="20">
                <component :is="getTxIcon(item.type)" />
              </el-icon>
            </div>
            <div class="record-info">
              <div class="record-title">{{ item.description }}</div>
              <div class="record-time">{{ item.created_at }}</div>
            </div>
            <div class="record-amount" :class="getTxClass(item.type)">
              {{ getTxSymbol(item.type) }}¥{{ item.amount }}
            </div>
          </div>
        </template>
        <el-empty v-else description="暂无交易记录" />
      </div>

      <div v-if="activeTab === 'recharge'" class="record-list">
        <template v-if="rechargeRecords.length > 0">
          <div
            v-for="item in rechargeRecords"
            :key="item.id"
            class="record-item"
          >
            <div class="record-icon recharge-icon">
              <el-icon size="20" color="#67c23a"><TopUp /></el-icon>
            </div>
            <div class="record-info">
              <div class="record-title">账户充值</div>
              <div class="record-time">{{ item.created_at }}</div>
            </div>
            <div class="record-amount amount-positive">
              +¥{{ item.amount }}
            </div>
          </div>
        </template>
        <el-empty v-else description="暂无充值记录" />
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, inject, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import { getTransactions, getRechargeRecords } from '@/api/wallet'

const router = useRouter()
const walletInfo = inject('walletInfo')
const openRechargeDialog = inject('openRechargeDialog')
const fetchWalletInfo = inject('fetchWalletInfo')

const activeTab = ref('transactions')
const transactions = ref([])
const rechargeRecords = ref([])

const openRecharge = () => {
  openRechargeDialog()
}

const goToFrozenOrders = () => {
  router.push({ path: '/orders', query: { status: 'frozen' } })
}

const fetchTransactions = async () => {
  try {
    transactions.value = await getTransactions(30)
  } catch (e) {
    console.error(e)
  }
}

const fetchRechargeRecords = async () => {
  try {
    rechargeRecords.value = await getRechargeRecords(30)
  } catch (e) {
    console.error(e)
  }
}

const getTxType = (type) => {
  const map = {
    recharge: 'recharge',
    payment: 'payment',
    refund: 'refund',
    freeze: 'frozen',
    unfreeze: 'unfreeze'
  }
  return map[type] || 'default'
}

const getTxIcon = (type) => {
  const map = {
    recharge: 'TopUp',
    payment: 'ShoppingCart',
    refund: 'RefreshLeft',
    freeze: 'Lock',
    unfreeze: 'Unlock'
  }
  return map[type] || 'Document'
}

const getTxClass = (type) => {
  const positiveTypes = ['recharge', 'refund', 'unfreeze']
  return positiveTypes.includes(type) ? 'amount-positive' : 'amount-negative'
}

const getTxSymbol = (type) => {
  const positiveTypes = ['recharge', 'refund', 'unfreeze']
  return positiveTypes.includes(type) ? '+' : '-'
}

const fetchData = () => {
  fetchTransactions()
  fetchRechargeRecords()
}

watch(
  () => walletInfo.value,
  () => {
    fetchData()
  }
)

onMounted(() => {
  fetchData()
  fetchWalletInfo()
})
</script>

<style scoped>
.wallet-overview {
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  color: #fff;
  padding: 0;
  overflow: hidden;
}

.overview-main {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 30px;
}

.balance-label {
  font-size: 14px;
  opacity: 0.85;
  margin-bottom: 12px;
}

.balance-amount {
  font-size: 42px;
  font-weight: 700;
  margin-bottom: 8px;
}

.balance-sub {
  font-size: 13px;
  opacity: 0.75;
}

.divider {
  margin: 0 8px;
}

.actions-section {
  display: flex;
  gap: 30px;
}

.action-item {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  cursor: pointer;
  opacity: 0.9;
  transition: opacity 0.3s;
}

.action-item:hover {
  opacity: 1;
}

.action-icon {
  width: 56px;
  height: 56px;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.2);
  display: flex;
  align-items: center;
  justify-content: center;
}

.action-icon.recharge {
  background: rgba(103, 194, 58, 0.3);
}

.action-icon.frozen {
  background: rgba(230, 162, 60, 0.3);
}

.action-item span {
  font-size: 13px;
}

.record-tabs :deep(.el-tabs__header) {
  margin: 0 0 16px 0;
}

.record-list {
  min-height: 300px;
}

.record-item {
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 16px 0;
  border-bottom: 1px solid #f2f6fc;
}

.record-item:last-child {
  border-bottom: none;
}

.record-icon {
  width: 44px;
  height: 44px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.record-icon.recharge {
  background: #f0f9eb;
  color: #67c23a;
}

.record-icon.payment {
  background: #fef0f0;
  color: #f56c6c;
}

.record-icon.frozen {
  background: #fdf6ec;
  color: #e6a23c;
}

.record-icon.unfreeze {
  background: #ecf5ff;
  color: #409eff;
}

.record-icon.refund {
  background: #f0f9eb;
  color: #67c23a;
}

.record-icon.recharge-icon {
  background: #f0f9eb;
  color: #67c23a;
}

.record-info {
  flex: 1;
  min-width: 0;
}

.record-title {
  font-size: 15px;
  color: #303133;
  margin-bottom: 4px;
}

.record-time {
  font-size: 12px;
  color: #c0c4cc;
}

.record-amount {
  font-size: 18px;
  font-weight: 600;
  flex-shrink: 0;
}

.amount-positive {
  color: #67c23a;
}

.amount-negative {
  color: #f56c6c;
}
</style>
