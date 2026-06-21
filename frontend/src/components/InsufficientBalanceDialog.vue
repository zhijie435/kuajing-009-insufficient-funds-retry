<template>
  <el-dialog
    v-model="dialogVisible"
    title="余额不足"
    width="440px"
    :close-on-click-modal="false"
    :close-on-press-escape="false"
  >
    <div class="insufficient-content">
      <div class="icon-wrapper">
        <el-icon size="64" color="#e6a23c"><WarningFilled /></el-icon>
      </div>
      
      <div class="tip-text">
        <h3>可用余额不足</h3>
        <p>您的账户余额不足以支付此订单，请充值后再试</p>
      </div>

      <div class="amount-detail">
        <div class="detail-row">
          <span class="label">订单金额</span>
          <span class="value">¥{{ order?.amount || 0 }}</span>
        </div>
        <div class="detail-row">
          <span class="label">可用余额</span>
          <span class="value balance">¥{{ wallet?.available_balance || 0 }}</span>
        </div>
        <div class="detail-row shortage">
          <span class="label">还差</span>
          <span class="value">¥{{ shortage.toFixed(2) }}</span>
        </div>
      </div>

      <div class="order-info">
        <el-icon size="14" color="#909399"><Document /></el-icon>
        <span>订单：{{ order?.title }}</span>
      </div>
    </div>

    <template #footer>
      <div class="footer-actions">
        <el-button @click="handleCancelOrder">取消订单</el-button>
        <el-button type="primary" size="large" @click="handleRecharge">
          <el-icon><TopUp /></el-icon>
          <span>立即充值</span>
        </el-button>
      </div>
    </template>
  </el-dialog>
</template>

<script setup>
import { computed, inject } from 'vue'
import { ElMessageBox } from 'element-plus'
import { cancelOrder } from '@/api/order'

const props = defineProps({
  visible: {
    type: Boolean,
    default: false
  },
  order: {
    type: Object,
    default: null
  },
  wallet: {
    type: Object,
    default: null
  }
})

const emit = defineEmits(['update:visible', 'recharge', 'cancel'])

const fetchWalletInfo = inject('fetchWalletInfo')

const dialogVisible = computed({
  get: () => props.visible,
  set: (val) => emit('update:visible', val)
})

const shortage = computed(() => {
  if (!props.order || !props.wallet) return 0
  return Math.max(0, props.order.amount - props.wallet.available_balance)
})

const handleCancelOrder = async () => {
  try {
    await ElMessageBox.confirm(
      '确定要取消此订单吗？取消后订单将无法恢复。',
      '确认取消',
      {
        confirmButtonText: '确定取消',
        cancelButtonText: '再想想',
        type: 'warning'
      }
    )

    if (props.order) {
      await cancelOrder(props.order.id)
      emit('cancel', props.order)
    }

    dialogVisible.value = false
  } catch (e) {
    if (e !== 'cancel') {
      console.error(e)
    }
  }
}

const handleRecharge = () => {
  emit('recharge', props.order)
}
</script>

<style scoped>
.insufficient-content {
  text-align: center;
  padding: 10px 0;
}

.icon-wrapper {
  margin-bottom: 20px;
}

.tip-text h3 {
  font-size: 20px;
  font-weight: 600;
  color: #303133;
  margin-bottom: 8px;
}

.tip-text p {
  font-size: 14px;
  color: #909399;
  margin: 0;
}

.amount-detail {
  background: #f5f7fa;
  border-radius: 8px;
  padding: 16px 20px;
  margin: 20px 0;
  text-align: left;
}

.detail-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 8px 0;
}

.detail-row .label {
  font-size: 14px;
  color: #606266;
}

.detail-row .value {
  font-size: 16px;
  font-weight: 500;
  color: #303133;
}

.detail-row .balance {
  color: #909399;
}

.detail-row.shortage {
  border-top: 1px dashed #e4e7ed;
  margin-top: 8px;
  padding-top: 12px;
}

.detail-row.shortage .value {
  color: #e6a23c;
  font-weight: 600;
  font-size: 18px;
}

.order-info {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  font-size: 13px;
  color: #909399;
}

.footer-actions {
  display: flex;
  justify-content: flex-end;
  gap: 12px;
  width: 100%;
}
</style>
