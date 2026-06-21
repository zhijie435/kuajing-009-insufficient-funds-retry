import axios from 'axios'
import { ElMessage } from 'element-plus'

const service = axios.create({
  baseURL: '/api',
  timeout: 15000
})

const ERROR_MESSAGES = {
  400: '请求参数错误',
  401: '未登录或登录已过期',
  403: '没有访问权限',
  404: '资源不存在',
  408: '请求超时，请稍后重试',
  409: '当前状态不允许此操作',
  422: '数据校验失败',
  500: '服务器内部错误',
  502: '网关错误',
  503: '服务不可用',
  504: '网关超时'
}

service.interceptors.response.use(
  response => {
    const res = response.data
    if (res.code !== 0) {
      const message = res.message || '请求失败'
      ElMessage.error(message)
      const error = new Error(message)
      error.code = res.code
      error.data = res.data || null
      error.businessError = true
      return Promise.reject(error)
    }
    return res.data
  },
  error => {
    let message = error.message || '网络错误'
    let errorCode = 'NETWORK_ERROR'
    let httpStatus = null

    if (error.response) {
      httpStatus = error.response.status
      if (ERROR_MESSAGES[httpStatus]) {
        message = ERROR_MESSAGES[httpStatus]
        if (error.response.data && error.response.data.message) {
          message = error.response.data.message
        }
      }
      errorCode = `HTTP_${httpStatus}`
    } else if (error.code === 'ECONNABORTED') {
      message = '请求超时，请检查网络后重试'
      errorCode = 'TIMEOUT'
    } else if (!window.navigator.onLine) {
      message = '网络连接已断开，请检查网络'
      errorCode = 'OFFLINE'
    }

    ElMessage.error(message)
    const wrappedError = new Error(message)
    wrappedError.originalError = error
    wrappedError.httpStatus = httpStatus
    wrappedError.errorCode = errorCode
    wrappedError.responseData = error.response?.data || null
    return Promise.reject(wrappedError)
  }
)

export default service
