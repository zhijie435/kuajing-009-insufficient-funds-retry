<template>
  <el-dialog
    v-model="dialogVisible"
    :title="dialogTitle"
    width="480px"
    :close-on-click-modal="false"
    :close-on-press-escape="!loading"
    @close="handleClose"
  >
    <template v-if="retryCount > 0" #header="{ close }">
      <div class="dialog-header-custom">
        <span class="retry-badge">已充值 {{ retryCount }} 次</span>
        <span class="dialog-title-text">{{ dialogTitle }}</span>
        <el-dialog__headerbtn :dialogRef="dialogRef" @click="handleClose" />
      </div>
    </template>

    <div v-if="latestResult" class="result-alert">
      <el-alert
        :title="latestResult.message || shortageTip"
        :type="latestResult.success ? 'success' : displayTipType"
        :closable="false"
        show-icon
      >
        <template v-if="latestResult.wallet" #default>
          <div class="result-detail">
            <div class="result-row">
              <span>充值后可用余额：</span>
              <b>¥{{ latestResult.wallet.available_balance?.toFixed(2) || '0.00' }}</b>
            </div>
            <div v-if="latestResult.shortage > 0" class="result-row shortage">
              <span>仍需补款：</span>
              <b>¥{{ latestResult.shortage.toFixed(2) }}</b>
            </div>
          </div>
        </template>
      </el-alert>
    </div>
    <div v-else-if="displayOrderInfo" class="shortage-tip">
      <el-alert
        :title="shortageTip"
        :type="displayTipType"
        :closable="false"
        show-icon
      >
      </el-alert>
    </div>

    <div class="balance-info mt-20">
      <div class="balance-item">
        <span class="label">当前可用余额</span>
        <span class="value">¥{{ walletInfo?.available_balance?.toFixed(2) || '0.00' }}</span>
      </div>
      <div v-if="displayOrderInfo" class="balance-item">
        <span class="label">订单金额</span>
        <span class="value">¥{{ displayOrderInfo.amount?.toFixed(2) || '0.00' }}</span>
      </div>
      <div v-if="displayOrderInfo && displayShortage > 0" class="balance-item shortage">
        <span class="label">差额</span>
        <span class="value">¥{{ displayShortage.toFixed(2) }}</span>
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
          @click="handleSelectQuickAmount(amt)"
          :disabled="loading"
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
          :disabled="loading"
          @input="handleCustomAmountInput"
        />
      </div>
      <div v-if="suggestRecharge > 0" class="suggest-tip mt-10">
        <el-icon color="#e6a23c"><InfoFilled /></el-icon>
        <span>建议至少充值 <b>¥{{ suggestRecharge }}</b> 以完成订单</span>
        <el-button
          v-if="!loading && suggestRecharge !== rechargeAmount && !customAmount"
          link
          type="primary"
          size="small"
          @click="rechargeAmount = suggestRecharge"
        >
          设为充值金额
        </el-button>
      </div>
    </div>

    <div class="payment-channel mt-20">
      <div class="section-title">支付方式</div>
      <div class="channel-list">
        <div
          v-for="channel in channels"
          :key="channel.value"
          class="channel-item"
          :class="{ active: selectedChannel === channel.value, disabled: loading }"
          @click="!loading && (selectedChannel = channel.value)"
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
          <el-button :disabled="loading" @click="handleClose">取消</el-button>
          <el-button
            type="primary"
            :loading="loading"
            @click="handleConfirm"
            :disabled="finalAmount <= 0"
          >
            {{ confirmButtonText }}
          </el-button>
        </div>
      </div>
    </template>
  </el-dialog>
</template>

<script setup>
import { ref, computed, watch, inject, getCurrentInstance } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { rechargeWallet } from '@/api/wallet'
import { rechargeAndRetry } from '@/api/order'

const { proxy } = getCurrentInstance()

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

const emit = defineEmits(['update:visible', 'success', 'close'])

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
const retryCount = ref(0)

const currentShortage = ref(0)
const currentOrderInfo = ref(null)
const latestResult = ref(null)

const dialogRef = computed(() => proxy?.$refs?.dialogRef || null)

const quickAmounts = [50, 100, 200, 500, 1000]

const channels = [
  { value: 'wechat', label: '微信支付', icon: 'ChatDotRound', color: '#07c160' },
  { value: 'alipay', label: '支付宝', icon: 'Aim', color: '#1677ff' }
]

const dialogTitle = computed(() => {
  if (props.orderId) {
    return retryCount.value > 0 ? '继续补款' : '订单补款'
  }
  return '账户充值'
})

const confirmButtonText = computed(() => {
  if (loading.value) return '处理中...'
  if (props.orderId) {
    return retryCount.value > 0 ? '继续充值并支付' : '充值并支付'
  }
  return '确认充值'
})

const finalAmount = computed(() => {
  if (customAmount.value && customAmount.value > 0) {
    const num = Number(customAmount.value)
    return isFinite(num) && num > 0 ? num : 0
  }
  return rechargeAmount.value || 0
})

const displayShortage = computed(() => {
  if (latestResult.value?.shortage > 0) {
    return latestResult.value.shortage
  }
  if (currentShortage.value > 0) {
    return currentShortage.value
  }
  return props.shortage > 0 ? props.shortage : 0
})

const displayOrderInfo = computed(() => {
  return latestResult.value?.order || currentOrderInfo.value || props.orderInfo
})

const suggestRecharge = computed(() => {
  if (latestResult.value?.suggest_recharge > 0) {
    return latestResult.value.suggest_recharge
  }
  if (displayShortage.value > 0) {
    return Math.ceil(displayShortage.value)
  }
  return 0
})

const shortageTip = computed(() => {
  if (latestResult.value?.frozen) {
    return latestResult.value.message || `充值成功，但余额仍不足，请继续充值`
  }
  if (latestResult.value?.success) {
    return '充值并支付成功'
  }
  if (displayOrderInfo.value?.title) {
    return `订单「${displayOrderInfo.value.title}」余额不足，请充值后重试`
  }
  return '余额不足，请充值后重试'
})

const displayTipType = computed(() => {
  if (latestResult.value?.success) return 'success'
  if (latestResult.value?.frozen) return 'warning'
  return 'warning'
})

watch(() => props.visible, (val) => {
  if (val) {
    retryCount.value = 0
    currentShortage.value = props.shortage
    currentOrderInfo.value = props.orderInfo
    latestResult.value = null
    customAmount.value = null

    const shortage = suggestRecharge.value
    if (shortage > 0) {
      const defaultAmount = quickAmounts.find(a => a >= shortage) || shortage
      rechargeAmount.value = defaultAmount
    } else {
      rechargeAmount.value = 100
    }
  }
})

const handleSelectQuickAmount = (amt) => {
  rechargeAmount.value = amt
  customAmount.value = null
}

const handleCustomAmountInput = () => {
  if (customAmount.value && customAmount.value > 0) {
    rechargeAmount.value = 0
  }
}

const handleClose = () => {
  if (loading.value) return
  dialogVisible.value = false
  emit('close')
}

const handleConfirm = async () => {
  if (finalAmount.value <= 0) {
    ElMessage.warning('请选择或输入充值金额')
    return
  }
  if (finalAmount.value > 9999999.99) {
    ElMessage.warning('充值金额超出限制')
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
        type: 'info',
        closeOnClickModal: false
      }
    )

    await rechargeWallet(finalAmount.value, selectedChannel.value)
    ElMessage.success('充值成功')
    await fetchWalletInfo()
    emit('success')
    handleClose()
  } catch (e) {
    if (e === 'cancel' || e === 'close') return
    if (e.businessError) return
    if (e.httpStatus || e.errorCode) return
    console.error('[DirectRecharge]', e)
  } finally {
    loading.value = false
  }
}

const handleRechargeAndRetry = async () => {
  loading.value = true
  try {
    const confirmMsg = retryCount.value > 0
      ? `确认继续充值 ¥${finalAmount.value.toFixed(2)} 并支付订单？`
      : `确认充值 ¥${finalAmount.value.toFixed(2)} 并支付订单？`

    await ElMessageBox.confirm(confirmMsg, '补款确认', {
      confirmButtonText: retryCount.value > 0 ? '继续充值并支付' : '充值并支付',
      cancelButtonText: '取消',
      type: 'warning',
      closeOnClickModal: false
    })

    const res = await rechargeAndRetry(props.orderId, finalAmount.value, selectedChannel.value)

    retryCount.value++
    await fetchWalletInfo()

    if (res.success) {
      ElMessage.success(res.message || '充值成功，订单支付完成')
      latestResult.value = null
      emit('success', res)
      handleClose()
      return
    }

    if (res.frozen) {
      latestResult.value = {
        ...res,
        message: res.message || '充值成功，但余额仍不足，请继续充值'
      }
      currentShortage.value = res.shortage
      currentOrderInfo.value = res.order

      const nextSuggest = res.suggest_recharge > 0
        ? res.suggest_recharge
        : (quickAmounts.find(a => a >= res.shortage) || Math.ceil(res.shortage))

      rechargeAmount.value = nextSuggest
      customAmount.value = null

      ElMessage.warning(res.message || '余额仍不足，请继续充值')
      emit('success', res)
      return
    }

    latestResult.value = res
    ElMessage.warning(res.message || '处理失败，请稍后重试')
  } catch (e) {
    if (e === 'cancel' || e === 'close') return
    if (e.businessError) return
    if (e.httpStatus || e.errorCode) return
    console.error('[RechargeAndRetry]', e)
  } finally {
    loading.value = false
  }
}
</script>

<style scoped>
.dialog-header-custom {
  display: flex;
  align-items: center;
  gap: 12px;
  padding-right: 30px;
  position: relative;
}

.dialog-header-custom :deep(.el-dialog__headerbtn) {
  position: absolute;
  right: 0;
  top: 50%;
  transform: translateY(-50%);
}

.dialog-title-text {
  font-size: 18px;
  font-weight: 600;
  color: #303133;
}

.retry-badge {
  display: inline-block;
  padding: 2px 10px;
  background: linear-gradient(135deg, #f56c6c 0%, #e6a23c 100%);
  color: #fff;
  border-radius: 10px;
  font-size: 12px;
  font-weight: 500;
}

.result-alert {
  margin-bottom: 4px;
}

.result-detail {
  margin-top: 8px;
  padding-top: 8px;
  border-top: 1px dashed rgba(230, 162, 60, 0.3);
}

.result-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 3px 0;
  font-size: 13px;
  color: #606266;
}

.result-row b {
  color: #303133;
}

.result-row.shortage b {
  color: #e6a23c;
  font-size: 15px;
}

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
  flex-wrap: wrap;
}

.suggest-tip b {
  color: #e6a23c;
  font-size: 14px;
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
  user-select: none;
}

.channel-item:hover:not(.disabled) {
  border-color: #c0c4cc;
}

.channel-item.active {
  border-color: #409eff;
  background: #ecf5ff;
}

.channel-item.disabled {
  opacity: 0.5;
  cursor: not-allowed;
  background: #f5f7fa;
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

.actions {
  display: flex;
  gap: 10px;
}
</style>
