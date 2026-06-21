import request from '@/utils/request'

export function getWalletInfo() {
  return request.get('/wallet.php?action=info')
}

export function rechargeWallet(amount, channel = 'manual') {
  return request.post('/wallet.php?action=recharge', {
    amount,
    channel
  })
}

export function getTransactions(limit = 20) {
  return request.get(`/wallet.php?action=transactions&limit=${limit}`)
}

export function getRechargeRecords(limit = 20) {
  return request.get(`/wallet.php?action=recharge-records&limit=${limit}`)
}
