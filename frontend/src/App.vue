<template>
  <div id="app">
    <el-container style="min-height: 100vh">
      <el-header class="app-header">
        <div class="header-content">
          <div class="logo">
            <el-icon size="28" color="#409eff"><CustomerService /></el-icon>
            <span class="title">CRM客户跟进系统</span>
          </div>
          <el-menu
            mode="horizontal"
            :default-active="activeMenu"
            class="header-menu"
            router
            background-color="transparent"
            text-color="#606266"
            active-text-color="#409eff"
          >
            <el-menu-item index="/dashboard">
              <el-icon><Odometer /></el-icon>
              <span>工作台</span>
            </el-menu-item>
            <el-menu-item index="/orders">
              <el-icon><List /></el-icon>
              <span>订单管理</span>
            </el-menu-item>
            <el-menu-item index="/wallet">
              <el-icon><Wallet /></el-icon>
              <span>我的钱包</span>
            </el-menu-item>
          </el-menu>
        </div>
      </el-header>

      <el-main class="app-main">
        <router-view />
      </el-main>
    </el-container>

    <RechargeDialog
      v-model:visible="rechargeVisible"
      :order-id="rechargeOrderId"
      :shortage="rechargeShortage"
      :order-info="rechargeOrderInfo"
      @success="handleRechargeSuccess"
    />
  </div>
</template>

<script setup>
import { ref, computed, onMounted, provide, reactive } from 'vue'
import { useRoute } from 'vue-router'
import { ElMessage } from 'element-plus'
import { getWalletInfo } from '@/api/wallet'
import RechargeDialog from '@/components/RechargeDialog.vue'

const route = useRoute()
const rechargeVisible = ref(false)
const rechargeOrderId = ref(null)
const rechargeShortage = ref(0)
const rechargeOrderInfo = ref(null)

const walletInfo = ref(null)
const userInfo = ref(null)

const orderRefreshSignal = reactive({
  version: 0,
  changedOrderId: null
})

const activeMenu = computed(() => route.path)

const fetchWalletInfo = async () => {
  try {
    const res = await getWalletInfo()
    walletInfo.value = res.wallet
    userInfo.value = res.user
  } catch (e) {
    console.error(e)
  }
}

const notifyOrderRefresh = (orderId = null) => {
  orderRefreshSignal.version++
  orderRefreshSignal.changedOrderId = orderId
}

const openRechargeDialog = (orderId = null, shortage = 0, orderInfo = null) => {
  rechargeOrderId.value = orderId
  rechargeShortage.value = shortage
  rechargeOrderInfo.value = orderInfo
  rechargeVisible.value = true
}

const handleRechargeSuccess = (result) => {
  fetchWalletInfo()
  const changedOrderId = result?.order?.id || rechargeOrderId.value
  notifyOrderRefresh(changedOrderId)
  rechargeOrderInfo.value = null
}

provide('walletInfo', walletInfo)
provide('userInfo', userInfo)
provide('fetchWalletInfo', fetchWalletInfo)
provide('openRechargeDialog', openRechargeDialog)
provide('orderRefreshSignal', orderRefreshSignal)
provide('notifyOrderRefresh', notifyOrderRefresh)

onMounted(() => {
  fetchWalletInfo()
})
</script>

<style scoped>
.app-header {
  background: #fff;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
  padding: 0;
  height: 60px;
}

.header-content {
  max-width: 1200px;
  margin: 0 auto;
  display: flex;
  align-items: center;
  height: 100%;
  padding: 0 20px;
}

.logo {
  display: flex;
  align-items: center;
  margin-right: 40px;
}

.title {
  font-size: 18px;
  font-weight: 600;
  color: #303133;
  margin-left: 10px;
}

.header-menu {
  flex: 1;
  border-bottom: none;
}

.app-main {
  background-color: #f5f7fa;
  padding: 20px;
}
</style>
