import request from '@/utils/request'

export function createOrder(amount, title, description = '') {
  return request.post('/order.php?action=create', {
    amount,
    title,
    description
  })
}

export function getOrderList(status = null) {
  let url = '/order.php?action=list'
  if (status) {
    url += `&status=${status}`
  }
  return request.get(url)
}

export function getFrozenOrders() {
  return request.get('/order.php?action=frozen')
}

export function getOrderDetail(orderId) {
  return request.get(`/order.php?action=detail&id=${orderId}`)
}

export function getFrozenSummary() {
  return request.get('/order.php?action=frozen-summary')
}

export function retryPayment(orderId) {
  return request.post('/order.php?action=retry', {
    order_id: orderId
  })
}

export function rechargeAndRetry(orderId, rechargeAmount, channel = 'manual') {
  return request.post('/order.php?action=recharge-retry', {
    order_id: orderId,
    recharge_amount: rechargeAmount,
    channel
  })
}

export function cancelOrder(orderId) {
  return request.post('/order.php?action=cancel', {
    order_id: orderId
  })
}

export function freezeOrder(orderId, reason = '') {
  return request.post('/order.php?action=freeze', {
    order_id: orderId,
    reason
  })
}

export function unfreezeOrder(orderId) {
  return request.post('/order.php?action=unfreeze', {
    order_id: orderId
  })
}

export function completeOrder(orderId) {
  return request.post('/order.php?action=complete', {
    order_id: orderId
  })
}

export function batchFreezeOrders(orderIds, reason = '') {
  return request.post('/order.php?action=batch-freeze', {
    order_ids: orderIds,
    reason
  })
}

export function batchRetryPayment(orderIds) {
  return request.post('/order.php?action=batch-retry', {
    order_ids: orderIds
  })
}
