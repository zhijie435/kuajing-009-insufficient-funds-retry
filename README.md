# CRM 客户跟进系统 - 余额充值重试链路

## 项目简介

基于 Vue + PHP 实现的余额不足充值重试链路系统，核心功能包括：
- 订单创建时余额检测
- 余额不足时自动冻结订单
- 充值后补款恢复流程
- 订单状态全链路追踪

## 技术栈

- **前端**: Vue 3 + Vite + Element Plus
- **后端**: PHP 7.4+ + PDO + SQLite
- **架构**: 前后端分离，RESTful API

## 项目结构

```
├── backend/                 # PHP 后端
│   ├── api/                 # API 接口
│   ├── config/              # 配置文件
│   ├── models/              # 数据模型
│   ├── services/            # 业务服务
│   └── database/            # 数据库文件
└── frontend/                # Vue 前端
    ├── src/
    │   ├── views/           # 页面
    │   ├── components/      # 组件
    │   ├── api/             # API 封装
    │   └── store/           # 状态管理
    └── ...
```

## 快速开始

### 后端启动

```bash
cd backend
php -S localhost:8000
```

### 前端启动

```bash
cd frontend
npm install
npm run dev
```

## 订单状态流转

```
pending(待支付) → frozen(已冻结) → paid(已支付) → completed(已完成)
                       ↓
                   cancelled(已取消)
```

### 状态说明

- **pending**: 订单创建，等待支付
- **frozen**: 余额不足，订单冻结，等待充值补款
- **paid**: 支付成功，待处理
- **completed**: 订单完成
- **cancelled**: 订单取消
