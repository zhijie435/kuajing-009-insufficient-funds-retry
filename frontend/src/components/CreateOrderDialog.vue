<template>
  <el-dialog
    v-model="dialogVisible"
    title="创建订单"
    width="500px"
    @close="handleClose"
  >
    <el-form :model="form" ref="formRef" label-width="80px">
      <el-form-item label="订单标题" prop="title" :rules="[{ required: true, message: '请输入订单标题', trigger: 'blur' }]">
        <el-input v-model="form.title" placeholder="请输入订单标题" maxlength="100" />
      </el-form-item>
      <el-form-item label="订单金额" prop="amount" :rules="[{ required: true, message: '请输入订单金额', trigger: 'blur' }]">
        <el-input-number
          v-model="form.amount"
          :min="0.01"
          :precision="2"
          :step="10"
          :max="99999"
          style="width: 100%"
        />
      </el-form-item>
      <el-form-item label="订单描述">
        <el-input
          v-model="form.description"
          type="textarea"
          :rows="3"
          placeholder="请输入订单描述（选填）"
          maxlength="500"
          show-word-limit
        />
      </el-form-item>
    </el-form>

    <div class="balance-tip" v-if="walletInfo">
      <el-alert
        :title="balanceTip"
        :type="balanceType"
        :closable="false"
        show-icon
      >
      </el-alert>
    </div>

    <template #footer>
      <div class="footer-content">
        <div class="order-amount">
          <span>订单金额：</span>
          <span class="amount">¥{{ (form.amount || 0).toFixed(2) }}</span>
        </div>
        <div class="actions">
          <el-button @click="handleClose">取消</el-button>
          <el-button type="primary" :loading="loading" @click="handleSubmit">
            创建并支付
          </el-button>
        </div>
      </div>
    </template>
  </el-dialog>

  <InsufficientBalanceDialog
    v-model:visible="insufficientVisible"
    :order="frozenOrder"
    :wallet="walletInfo"
    @recharge="handleRecharge"
  />
</template>

<script setup>
import { ref, computed, inject, watch } from 'vue'
import { ElMessage } from 'element-plus'
import { createOrder } from '@/api/order'
import InsufficientBalanceDialog from './InsufficientBalanceDialog.vue'

const props = defineProps({
  visible: {
    type: Boolean,
    default: false
  }
})

const emit = defineEmits(['update:visible', 'success'])

const walletInfo = inject('walletInfo')
const openRechargeDialog = inject('openRechargeDialog')
const fetchWalletInfo = inject('fetchWalletInfo')
const notifyOrderRefresh = inject('notifyOrderRefresh')

const dialogVisible = computed({
  get: () => props.visible,
  set: (val) => emit('update:visible', val)
})

const formRef = ref(null)
const loading = ref(false)
const insufficientVisible = ref(false)
const frozenOrder = ref(null)

const form = ref({
  title: '',
  amount: null,
  description: ''
})

const balanceType = computed(() => {
  if (!walletInfo.value) return 'info'
  const available = walletInfo.value.available_balance || 0
  const amount = form.value.amount || 0
  if (amount === 0) return 'info'
  return available >= amount ? 'success' : 'warning'
})

const balanceTip = computed(() => {
  if (!walletInfo.value) return '正在查询余额...'
  const available = walletInfo.value.available_balance || 0
  const amount = form.value.amount || 0
  if (amount === 0) return `当前可用余额：¥${available.toFixed(2)}`
  if (available >= amount) {
    return `余额充足，当前可用余额：¥${available.toFixed(2)}`
  } else {
    const shortage = amount - available
    return `余额不足，还差 ¥${shortage.toFixed(2)}，创建后订单将被冻结`
  }
})

watch(() => props.visible, (val) => {
  if (val) {
    form.value = {
      title: '',
      amount: null,
      description: ''
    }
    formRef.value?.clearValidate()
    frozenOrder.value = null
    insufficientVisible.value = false
  }
})

const handleClose = () => {
  dialogVisible.value = false
}

const handleSubmit = async () => {
  if (!formRef.value) return

  try {
    await formRef.value.validate()
  } catch (e) {
    return
  }

  loading.value = true
  try {
    const res = await createOrder(form.value.amount, form.value.title, form.value.description)
    
    fetchWalletInfo()
    notifyOrderRefresh(res?.order?.id)

    if (res.success) {
      ElMessage.success('订单创建成功，支付完成')
      emit('success', res)
      handleClose()
    } else if (res.frozen) {
      frozenOrder.value = res.order
      insufficientVisible.value = true
      emit('success', res)
    }
  } catch (e) {
    console.error(e)
  } finally {
    loading.value = false
  }
}

const handleRecharge = (order) => {
  insufficientVisible.value = false
  const shortage = order ? (order.amount - (walletInfo.value?.available_balance || 0)) : 0
  openRechargeDialog(order.id, Math.max(0, shortage), order)
  handleClose()
}
</script>

<style scoped>
.balance-tip {
  margin-top: 10px;
}

.footer-content {
  display: flex;
  justify-content: space-between;
  align-items: center;
  width: 100%;
}

.order-amount {
  display: flex;
  align-items: baseline;
  gap: 4px;
}

.order-amount .amount {
  font-size: 22px;
  font-weight: 600;
  color: #f56c6c;
}
</style>
