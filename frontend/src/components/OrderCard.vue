<template>
  <div class="order-card" :class="`status-${order.status}`">
    <div class="order-header">
      <div class="order-title">
        <el-icon v-if="order.status === 'frozen'" color="#e6a23c" size="20">
          <WarningFilled />
        </el-icon>
        <el-icon v-else-if="order.status === 'paid'" color="#67c23a" size="20">
          <CircleCheckFilled />
        </el-icon>
        <el-icon v-else-if="order.status === 'completed'" color="#409eff" size="20">
          <Finished />
        </el-icon>
        <el-icon v-else-if="order.status === 'cancelled'" color="#909399" size="20">
          <CircleCloseFilled />
        </el-icon>
        <el-icon v-else color="#909399" size="20">
          <Clock />
        </el-icon>
        <span class="title-text">{{ order.title }}</span>
      </div>
      <el-tag :type="statusType" effect="light" size="small">
        {{ statusText }}
      </el-tag>
    </div>

    <div class="order-body">
      <div v-if="order.description" class="order-desc">
        {{ order.description }}
      </div>
      <div class="order-info">
        <div class="info-item">
          <span class="label">订单号</span>
          <span class="value">{{ order.order_no }}</span>
        </div>
        <div class="info-item">
          <span class="label">金额</span>
          <span class="value amount">¥{{ order.amount }}</span>
        </div>
      </div>
    </div>

    <div v-if="order.status === 'frozen' && order.frozen_reason" class="frozen-reason">
      <el-icon color="#e6a23c"><InfoFilled /></el-icon>
      <span>{{ order.frozen_reason }}</span>
    </div>

    <div class="order-footer">
      <div class="created-time">
        <el-icon size="14"><Timer /></el-icon>
        <span>{{ formatTime(order.created_at) }}</span>
      </div>
      <div class="actions">
        <slot name="actions" :order="order">
          <template v-if="order.status === 'frozen'">
            <el-button size="small" @click="$emit('cancel', order)">
              取消订单
            </el-button>
            <el-button type="primary" size="small" @click="$emit('retry', order)">
              立即补款
            </el-button>
          </template>
          <template v-else-if="order.status === 'pending'">
            <el-button size="small" @click="$emit('cancel', order)">
              取消订单
            </el-button>
            <el-button type="primary" size="small" @click="$emit('pay', order)">
              去支付
            </el-button>
          </template>
          <template v-else-if="order.status === 'paid'">
            <el-button type="success" size="small" @click="$emit('complete', order)">
              确认完成
            </el-button>
          </template>
        </slot>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  order: {
    type: Object,
    required: true
  }
})

defineEmits(['pay', 'retry', 'cancel', 'complete', 'detail'])

const statusType = computed(() => {
  const map = {
    pending: 'info',
    frozen: 'warning',
    paid: 'success',
    completed: 'primary',
    cancelled: 'info'
  }
  return map[props.order.status] || 'info'
})

const statusText = computed(() => {
  const map = {
    pending: '待支付',
    frozen: '已冻结',
    paid: '已支付',
    completed: '已完成',
    cancelled: '已取消'
  }
  return map[props.order.status] || props.order.status
})

const formatTime = (time) => {
  if (!time) return '-'
  return time
}
</script>

<style scoped>
.order-card {
  background: #fff;
  border-radius: 8px;
  padding: 20px;
  margin-bottom: 16px;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
  transition: all 0.3s;
  border-left: 4px solid transparent;
}

.order-card:hover {
  box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
}

.order-card.status-frozen {
  border-left-color: #e6a23c;
  background: linear-gradient(to right, #fdf6ec 0%, #fff 10%);
}

.order-card.status-paid {
  border-left-color: #67c23a;
}

.order-card.status-completed {
  border-left-color: #409eff;
}

.order-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 16px;
}

.order-title {
  display: flex;
  align-items: center;
  gap: 8px;
}

.title-text {
  font-size: 16px;
  font-weight: 600;
  color: #303133;
}

.order-body {
  margin-bottom: 16px;
}

.order-desc {
  color: #606266;
  font-size: 14px;
  margin-bottom: 12px;
  padding-bottom: 12px;
  border-bottom: 1px dashed #ebeef5;
}

.order-info {
  display: flex;
  gap: 30px;
}

.info-item {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.info-item .label {
  font-size: 12px;
  color: #909399;
}

.info-item .value {
  font-size: 14px;
  color: #303133;
}

.info-item .amount {
  font-size: 18px;
  font-weight: 600;
  color: #f56c6c;
}

.frozen-reason {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 10px 14px;
  background: #fdf6ec;
  border-radius: 4px;
  margin-bottom: 16px;
  font-size: 13px;
  color: #e6a23c;
}

.order-footer {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding-top: 12px;
  border-top: 1px solid #f2f6fc;
}

.created-time {
  display: flex;
  align-items: center;
  gap: 4px;
  font-size: 12px;
  color: #c0c4cc;
}

.actions {
  display: flex;
  gap: 8px;
}
</style>
