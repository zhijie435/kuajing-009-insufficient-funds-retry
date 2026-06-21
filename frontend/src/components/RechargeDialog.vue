<template>
  <el-dialog
    v-model="dialogVisible"
    :title="dialogTitle"
    width="480px"
    @close="handleClose"
  >
    <div v-if="orderInfo" class="shortage-tip">
      <el-alert
        :title="shortageTip"
        type="warning"
        :closable="false"
        show-icon
      >
      </el-alert>
    </div>

    <div class="balance-info mt-20">
      <div class="balance-item">
        <span class="label">当前可用余额</span>
        <span class="value">¥{{ walletInfo?.available_balance || 0 }}</span>
      </div>
      <div v-if="orderInfo" class="balance-item">
        <span class="label">订单金额</span>
        <span class="value">¥{{ orderInfo.amount }}</span>
      </div>
      <div v-if="orderInfo && shortage > 0" class="balance-item shortage">
        <span class="label">还差</span>
        <span class="value">¥{{ shortage.toFixed(2) }}</span>
      </div>
    </div>

    <div class="recharge-section mt-20">
      <div class="section-title">充值金额</div>
      <div class="quick-amounts">
        <el-button
          v-for="amt in quickAmounts"
          :key="amt"
          :type="rechargeAmount === amt ? 'primary' : 'default'"
          size="large"
          @click="rechargeAmount = amt"
        >
          ¥{{ amt }}
        </el-button>
      </div>
      <div class="custom-amount mt-10">
        <span class="prefix">¥</span>
        <el-input
          v-model.number="customAmount"
          type="number"
          placeholder="或输入自定义金额"
          @input="handleCustomAmountInput"
        />
      </div>
      <div v-if="suggestRecharge > 0" class="suggest-tip mt-10">
        <el-icon color="#e6a23c"><InfoFilled /></el-icon>
        <span>建议至少充值 ¥{{ suggestRecharge }} 以完成订单</span>
      </div>
    </div>

    <div class="payment-channel mt-20">
      <div class="section-title">支付方式</div>
      <div class="channel-list">
        <div
          v-for="channel in channels"
          :key="channel.value"
          class="channel-item"
          :class="{ active: selectedChannel === channel.value }"
          @click="selectedChannel = channel.value"
        >
          <el-icon :size="24" :color="channel.color">
            <component :is="channel.icon" />
          </el-icon>
          <span>{{ channel.label }}</span>
        </div>
      </div>
    </div>

    <template #footer>
      <div class="footer-content">
        <div class="total">
          <span>充值金额：</span>
          <span class="amount">¥{{ finalAmount.toFixed(2) }}</span>
        </div>
        <div class="actions">
          <el-button @click="handleClose">取消</el-button>
          <el-button type="primary" :loading="loading" @click="handleConfirm">
            {{ confirmButtonText }}
          </el-button>
        </div>
      </div>
    </template>
  </el-dialog>
</template>

<script setup>
import { ref, computed, watch, inject } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { rechargeWallet } from '@/api/wallet'
import { rechargeAndRetry, retryPayment } from '@/api/order'

const props = defineProps({
  visible: {
    type: Boolean,
    default: false
  },
  orderId: {
    type: [Number, String],
    default: null
  },
  shortage: {
    type: Number,
    default: 0
  },
  orderInfo: {
    type: Object,
    default: null
  }
})

const emit = defineEmits(['update:visible', 'success'])

const walletInfo = inject('walletInfo')
const fetchWalletInfo = inject('fetchWalletInfo')

const dialogVisible = computed({
  get: () => props.visible,
  set: (val) => emit('update:visible', val)
})

const rechargeAmount = ref(0)
const customAmount = ref(null)
const selectedChannel = ref('wechat')
const loading = ref(false)

const quickAmounts = [50, 100, 200, 500, 1000]

const channels = [
  { value: 'wechat', label: '微信支付', icon: 'ChatDotRound', color: '#07c160' },
  { value: 'alipay', label: '支付宝', icon: 'Aim', color: '#1677ff' }
]

const dialogTitle = computed(() => {
  if (props.orderId) {
    return '订单补款'
  }
  return '账户充值'
})

const confirmButtonText = computed(() => {
  if (props.orderId) {
    return '充值并支付'
  }
  return '确认充值'
})

const finalAmount = computed(() => {
  if (customAmount.value && customAmount.value > 0) {
    return Number(customAmount.value)
  }
  return rechargeAmount.value || 0
})

const suggestRecharge = computed(() => {
  if (props.shortage > 0) {
    return Math.ceil(props.shortage)
  }
  return 0
})

const shortageTip = computed(() => {
  if (props.orderInfo?.title) {
    return `订单「${props.orderInfo.title}」余额不足，请充值后重试`
  }
  return '余额不足，请充值后重试'
})

watch(() => props.visible, (val) => {
  if (val) {
    if (suggestRecharge.value > 0) {
      const defaultAmount = quickAmounts.find(a => a >= suggestRecharge.value) || suggestRecharge.value
      rechargeAmount.value = defaultAmount
    } else {
      rechargeAmount.value = 100
    }
    customAmount.value = null
  }
})

const handleCustomAmountInput = () => {
  if (customAmount.value && customAmount.value > 0) {
    rechargeAmount.value = 0
  }
}

const handleClose = () => {
  dialogVisible.value = false
}

const handleConfirm = async () => {
  if (finalAmount.value <= 0) {
    ElMessage.warning('请选择或输入充值金额')
    return
  }

  if (props.orderId) {
    await handleRechargeAndRetry()
  } else {
    await handleDirectRecharge()
  }
}

const handleDirectRecharge = async () => {
  loading.value = true
  try {
    await ElMessageBox.confirm(
      `确认充值 ¥${finalAmount.value.toFixed(2)}？`,
      '充值确认',
      {
        confirmButtonText: '确认充值',
        cancelButtonText: '再想想',
        type: 'info'
      }
    )

    await rechargeWallet(finalAmount.value, selectedChannel.value)
    ElMessage.success('充值成功')
    fetchWalletInfo()
    emit('success')
    handleClose()
  } catch (e) {
    if (e !== 'cancel') {
      console.error(e)
    }
  } finally {
    loading.value = false
  }
}

const handleRechargeAndRetry = async () => {
  loading.value = true
  try {
    await ElMessageBox.confirm(
      `确认充值 ¥${finalAmount.value.toFixed(2)} 并支付订单？`,
      '补款确认',
      {
        confirmButtonText: '充值并支付',
        cancelButtonText: '取消',
        type: 'warning'
      }
    )

    const res = await rechargeAndRetry(props.orderId, finalAmount.value)
    
    if (res.success) {
      ElMessage.success('充值成功，订单支付完成')
      fetchWalletInfo()
      emit('success', res)
      handleClose()
    } else if (res.frozen) {
      ElMessage.warning(`充值成功，但余额仍不足，还差 ¥${res.shortage.toFixed(2)}`)
      fetchWalletInfo()
      emit('success', res)
    }
  } catch (e) {
    if (e !== 'cancel') {
      console.error(e)
    }
  } finally {
    loading.value = false
  }
}
</script>

<style scoped>
.shortage-tip {
  margin-bottom: 10px;
}

.balance-info {
  background: #f5f7fa;
  border-radius: 8px;
  padding: 16px;
}

.balance-item {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 8px 0;
}

.balance-item .label {
  color: #909399;
  font-size: 14px;
}

.balance-item .value {
  font-size: 16px;
  font-weight: 500;
  color: #303133;
}

.balance-item.shortage .value {
  color: #e6a23c;
  font-weight: 600;
}

.section-title {
  font-size: 14px;
  font-weight: 500;
  color: #303133;
  margin-bottom: 12px;
}

.quick-amounts {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}

.quick-amounts .el-button {
  flex: 1;
  min-width: 80px;
}

.custom-amount {
  position: relative;
}

.custom-amount .prefix {
  position: absolute;
  left: 12px;
  top: 50%;
  transform: translateY(-50%);
  color: #606266;
  font-size: 16px;
  z-index: 1;
}

.custom-amount :deep(.el-input__wrapper) {
  padding-left: 28px;
}

.suggest-tip {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  color: #e6a23c;
}

.channel-list {
  display: flex;
  gap: 12px;
}

.channel-item {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  padding: 16px;
  border: 2px solid #e4e7ed;
  border-radius: 8px;
  cursor: pointer;
  transition: all 0.3s;
}

.channel-item:hover {
  border-color: #c0c4cc;
}

.channel-item.active {
  border-color: #409eff;
  background: #ecf5ff;
}

.channel-item span {
  font-size: 13px;
  color: #606266;
}

.footer-content {
  display: flex;
  justify-content: space-between;
  align-items: center;
  width: 100%;
}

.total {
  display: flex;
  align-items: baseline;
  gap: 4px;
}

.total .amount {
  font-size: 24px;
  font-weight: 600;
  color: #f56c6c;
}
</style>
