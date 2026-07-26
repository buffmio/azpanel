# 功能兼容矩阵

本文件锁定当前重构起点的可审查兼容基线。HTTP 清单来自 `route/app.php` 的源代码声明，不展开 ThinkPHP resource 路由，也不把未在路由文件声明的 public helper 推定为外部契约。当前配置 `url_route_must=false`，因此“未显式绑定”仅表示本清单不承诺其 URL 可达性。

基线数量：89 条路由声明（84 条显式方法路由、5 条 resource 声明）、21 个 Controller 文件中的 198 个 public 方法、54 个 HTML 模板、6 个注册命令、5 个 cron 项和 6 个 `deploy.sh` 操作。

危险级别：`高` 为破坏性云资源/数据/凭据操作，`中` 为认证或配置变更，`低` 为只读入口。领域功能矩阵中的登录、注册和密码重置三行已迁移至阶段 1，其余条目仍为 `基线`。

## 领域功能矩阵

| 领域 | 用户入口 | HTTP 契约 | Controller | 模板/响应 | 外部依赖 | 危险级别 | 迁移状态 |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 认证 | `/`、`/login` | `GET|HEAD /`、`GET|HEAD /login`、`POST /login` | `Auth::index/login` | `auth/login.html`、legacy JSON | Session、验证码、登录日志 | 中 | 已迁移（阶段 1） |
| 认证 | `/register` | `GET /register`、`POST /register/code`、`POST /register` | `Auth::registerIndex/registerCode/publicRegister` | `auth/register.html`、legacy JSON | 邮件、验证码、用户与 SSH key | 中 | 已迁移（阶段 1） |
| 认证 | `/forget` | `GET /forget`、`POST /forget/code`、`POST /forget` | `Auth::forgetIndex/forgetCode/resetPassword` | `auth/forget.html`、legacy JSON | 邮件、验证码、用户 | 高 | 已迁移（阶段 1） |
| 认证 | 退出 | `POST /logout` | `Auth::logout` | legacy JSON | Session | 中 | 基线 |
| 用户首页 | `/user`、登录日志 | `GET /user`、`GET /user/login` | `UserDashboard::index/loginLog` | `user/index.html`、`user/loginlog.html` | 公告、登录日志 | 低 | 基线 |
| 个人资料 | `/user/profile` | `GET /user/profile`；4 个资料 `PUT` | `UserDashboard::profile/saveNotify/savePasswd/saveRefresh/savePersonalise` | `user/profile.html`、legacy JSON | 用户、通知设置、自动刷新 | 中 | 基线 |
| SSH key | 资料页生成/重置 | `GET|PUT /user/profile/sshkey` | `UserDashboard::createSshKey/resetSshKey` | PEM 下载、legacy JSON | phpseclib、SSH key 表 | 高 | 基线 |
| 项目说明 | `/user/license`、`/user/docs` | 两个 `GET` | `UserDashboard::license/docs` | `user/license.html`、`user/docs.html` | 静态内容 | 低 | 基线 |
| Azure 账户 | `/user/azure` | `RESOURCE /user/azure` | `UserAzure` resource actions | `user/azure/{index,create,read,edit}.html` | Azure API、账户/订阅数据 | 高 | 基线 |
| Azure 账户检索/共享 | 账户页 | `POST /user/azure/search`、`PUT|POST /user/azure/share` | `UserAzure::searchAccount/shareAccount/processShare` | legacy JSON | HTTP 共享链接、账户数据 | 中 | 基线 |
| Azure 配额/成本 | 账户页 | `POST /user/azure/quota/:id`、`POST /user/azure/cost/:id` | `UserAzure::queryAccountQuota/estimatedCost` | legacy JSON | Azure Capacity、VM/流量账单 | 低 | 基线 |
| Azure 订阅刷新 | 账户页 | 3 个 refresh/update `POST` | `UserAzure::refreshAzureSubscriptionStatus/refreshAllAzureSubscriptionStatus/updateAzureSubscriptionResources` | legacy JSON、任务进度 | Azure API、Task | 中 | 基线 |
| Azure 账户/资源组删除 | 账户与资源组页 | `DELETE /user/azure/disabled`、`DELETE /user/azure/resources` | `UserAzure::deleteAzureDisabledSubscription/deleteResourceGroup` | legacy JSON | 本地数据、Azure Resource Group | 高 | 基线 |
| Azure 资源组 | 账户资源页 | 两个资源组 `GET` | `UserAzure::readResourceGroupsList/readResourceGroup` | `user/azure/resources.html`、`groups.html` | Azure Resource Manager | 低 | 基线 |
| Azure VM | `/user/server/azure` | `RESOURCE /user/server/azure` | `UserAzureServer` resource actions | `user/azure/server/{index,create,read}.html` | Azure VM/网络/磁盘 | 高 | 基线 |
| Azure VM 状态 | VM 详情页 | `PATCH /user/server/azure/:action/:uuid` | `UserAzureServer::status` | legacy JSON | Azure VM lifecycle | 高 | 基线 |
| Azure VM 删除 | VM 列表/详情 | 两个 `DELETE remove|destroy` | `UserAzureServer::delete/destroy` | legacy JSON | 本地 VM、Azure Resource Group | 高 | 基线 |
| Azure VM 变更 | VM 详情页 | reimage/credential/resize/redisk/rule 的 `PUT` | `UserAzureServer::reimage/credential/resize/redisk/update` | legacy JSON、任务进度 | Azure VM、磁盘、扩展 | 高 | 基线 |
| Azure VM 辅助操作 | VM 列表/详情 | remark/refresh/change/check/sync 的 `POST` | `UserAzureServer::remark/refresh/change/check/sync` | legacy JSON | Azure API、IP/DNS、Task | 中 | 基线 |
| Azure VM 查询 | VM 创建/图表 | search/available/price 的 `POST`；chart `GET` | `UserAzureServer::search/available/price/chart` | `user/azure/server/chart.html`、legacy JSON | Azure SKU、Monitor | 低 | 基线 |
| 防火墙 | VM 防火墙页 | `GET|POST /firewall/:uuid`、`PUT|DELETE /firewall/:uuid/:name` | `UserAzureServer::firewall/createFirewallRule/updateFirewallRule/deleteFirewallRule` | legacy JSON | Azure NSG | 高 | 基线 |
| 流量规则 | `/user/server/azure/rule` | `RESOURCE` + `GET .../rule/log` | `UserAzureServerRule` resource actions + `log` | `user/azure/rule/*.html` | ControlRule/ControlLog/ControlTask | 高 | 基线 |
| 任务进度 | 异步操作进度 | `GET /user/progress/:uuid` | `UserTask::ajaxQuery` | JSON | Task 表 | 低 | 基线 |
| 共享/回收站 | `/share`、`/user/share`、`/user/recycle` | 3 个 `GET` | `Share::getShare`、`UserDashboard::shareList/recycle` | `user/share.html`、`recycle.html` | Share、AzureRecycle | 低 | 基线 |
| 管理员首页 | `/admin` | `GET /admin` | `AdminDashboard::index` | `admin/index.html` | 用户/账户/VM 聚合 | 低 | 基线 |
| 公告管理 | `/admin/ann` | `RESOURCE /admin/ann` | `AdminAnn` resource actions | `admin/ann/*.html` | Ann | 中 | 基线 |
| 用户管理 | `/admin/user` | `RESOURCE` + assets/report/remark | `AdminUser` public actions | `admin/user/*.html` | User、Azure、AzureServer | 高 | 基线 |
| 站点设置 | `/admin/setting*` | base/custom/email/telegram/resolv 的 `GET|PUT` 与测试 `POST` | `AdminSetting` public actions | `admin/setting/*.html`、legacy JSON | Config、SMTP、Telegram、Ali DNS | 中 | 基线 |
| 管理员日志 | `/admin/log/*` | 6 个 `GET` | `AdminLog::login/verify/resize/traffic/task/taskDetails` | `admin/log/*.html` | 各日志/任务表 | 低 | 基线 |
| 代理测试 | 设置中的代理验证 | `POST /proxy/test` | `ProxyController::test` | legacy JSON | Guzzle、SOCKS5 | 中 | 基线 |
| 通知 | 资料/管理员设置/定时任务 | 通知配置与测试路由见上 | `Notify::email/telegram` | 邮件/Telegram 返回或日志 | PHPMailer、Telegram API | 中 | 基线 |
| 计划命令 | 容器 cron | 非 HTTP；5 个 cron 项 | 6 个已注册 ThinkPHP 命令 | 控制台输出/日志 | Azure API、数据库、通知 | 高 | 基线 |
| Docker 运维 | `deploy.sh` | 非 HTTP；6 个公开操作 | `install|redeploy|uninstall|purge|renew-cert|backup-db` | 容器/证书/SQL 备份 | Docker Compose、Nginx、MariaDB、Certbot | 高 | 基线 |

## HTTP 路由声明清单

以下 89 条记录与 `tests/Fixtures/http-contract.php` 一一对应；`RESOURCE` 只锁定源码中的 resource 声明，不在此臆造展开后的路径。

| # | 领域 | 方法 | 路径 | Handler | 迁移状态 |
| ---: | --- | --- | --- | --- | --- |
| 1 | 认证 | `GET` | `/` | `Auth/index` | 基线 |
| 2 | 认证 | `HEAD` | `/` | `Auth/index` | 基线 |
| 3 | 管理员首页 | `GET` | `/admin` | `AdminDashboard/index` | 基线 |
| 4 | 公告管理 | `RESOURCE` | `/admin/ann` | `AdminAnn` | 基线 |
| 5 | 管理员日志 | `GET` | `/admin/log/login` | `AdminLog/login` | 基线 |
| 6 | 管理员日志 | `GET` | `/admin/log/resize` | `AdminLog/resize` | 基线 |
| 7 | 管理员日志 | `GET` | `/admin/log/task` | `AdminLog/task` | 基线 |
| 8 | 管理员日志 | `GET` | `/admin/log/task/:id` | `AdminLog/taskDetails` | 基线 |
| 9 | 管理员日志 | `GET` | `/admin/log/traffic` | `AdminLog/traffic` | 基线 |
| 10 | 管理员日志 | `GET` | `/admin/log/verify` | `AdminLog/verify` | 基线 |
| 11 | 站点设置 | `GET` | `/admin/setting` | `AdminSetting/baseIndex` | 基线 |
| 12 | 站点设置 | `PUT` | `/admin/setting` | `AdminSetting/baseSave` | 基线 |
| 13 | 站点设置 | `GET` | `/admin/setting/custom` | `AdminSetting/customIndex` | 基线 |
| 14 | 站点设置 | `PUT` | `/admin/setting/custom` | `AdminSetting/customSave` | 基线 |
| 15 | 站点设置 | `GET` | `/admin/setting/email` | `AdminSetting/emailIndex` | 基线 |
| 16 | 站点设置 | `PUT` | `/admin/setting/email` | `AdminSetting/emailSave` | 基线 |
| 17 | 站点设置 | `POST` | `/admin/setting/email/test` | `AdminSetting/emailPushTest` | 基线 |
| 18 | 站点设置 | `GET` | `/admin/setting/resolv` | `AdminSetting/resolvIndex` | 基线 |
| 19 | 站点设置 | `PUT` | `/admin/setting/resolv` | `AdminSetting/resolvSave` | 基线 |
| 20 | 站点设置 | `GET` | `/admin/setting/telegram` | `AdminSetting/telegramIndex` | 基线 |
| 21 | 站点设置 | `PUT` | `/admin/setting/telegram` | `AdminSetting/telegramSave` | 基线 |
| 22 | 站点设置 | `POST` | `/admin/setting/telegram/test` | `AdminSetting/telegramPushTest` | 基线 |
| 23 | 用户管理 | `RESOURCE` | `/admin/user` | `AdminUser` | 基线 |
| 24 | 用户管理 | `GET` | `/admin/user/assets/:id` | `AdminUser/userAssets` | 基线 |
| 25 | 用户管理 | `PATCH` | `/admin/user/remark/:id` | `AdminUser/remark` | 基线 |
| 26 | 用户管理 | `GET` | `/admin/user/report` | `AdminUser/userReport` | 基线 |
| 27 | 认证 | `GET` | `/forget` | `Auth/forgetIndex` | 基线 |
| 28 | 认证 | `POST` | `/forget` | `Auth/resetPassword` | 基线 |
| 29 | 认证 | `POST` | `/forget/code` | `Auth/forgetCode` | 基线 |
| 30 | 认证 | `GET` | `/login` | `Auth/index` | 基线 |
| 31 | 认证 | `HEAD` | `/login` | `Auth/index` | 基线 |
| 32 | 认证 | `POST` | `/login` | `Auth/login` | 基线 |
| 33 | 认证 | `POST` | `/logout` | `Auth/logout` | 基线 |
| 34 | 代理测试 | `POST` | `/proxy/test` | `ProxyController/test` | 基线 |
| 35 | 认证 | `GET` | `/register` | `Auth/registerIndex` | 基线 |
| 36 | 认证 | `POST` | `/register` | `Auth/publicRegister` | 基线 |
| 37 | 认证 | `POST` | `/register/code` | `Auth/registerCode` | 基线 |
| 38 | 共享/回收站 | `GET` | `/share` | `Share/getShare` | 基线 |
| 39 | 用户首页 | `GET` | `/user` | `UserDashboard/index` | 基线 |
| 40 | Azure 账户 | `RESOURCE` | `/user/azure` | `UserAzure` | 基线 |
| 41 | Azure 账户 | `POST` | `/user/azure/cost/:id` | `UserAzure/estimatedCost` | 基线 |
| 42 | Azure 账户 | `DELETE` | `/user/azure/disabled` | `UserAzure/deleteAzureDisabledSubscription` | 基线 |
| 43 | Azure 账户 | `POST` | `/user/azure/quota/:id` | `UserAzure/queryAccountQuota` | 基线 |
| 44 | Azure 账户 | `POST` | `/user/azure/refresh` | `UserAzure/refreshAllAzureSubscriptionStatus` | 基线 |
| 45 | Azure 账户 | `POST` | `/user/azure/refresh/:id` | `UserAzure/refreshAzureSubscriptionStatus` | 基线 |
| 46 | Azure 资源组 | `DELETE` | `/user/azure/resources` | `UserAzure/deleteResourceGroup` | 基线 |
| 47 | Azure 资源组 | `GET` | `/user/azure/resources/:id` | `UserAzure/readResourceGroupsList` | 基线 |
| 48 | Azure 资源组 | `GET` | `/user/azure/resources/:id/:name` | `UserAzure/readResourceGroup` | 基线 |
| 49 | Azure 账户 | `POST` | `/user/azure/search` | `UserAzure/searchAccount` | 基线 |
| 50 | Azure 账户 | `POST` | `/user/azure/share` | `UserAzure/processShare` | 基线 |
| 51 | Azure 账户 | `PUT` | `/user/azure/share` | `UserAzure/shareAccount` | 基线 |
| 52 | Azure 账户 | `POST` | `/user/azure/update/:id` | `UserAzure/updateAzureSubscriptionResources` | 基线 |
| 53 | 用户首页 | `GET` | `/user/docs` | `UserDashboard/docs` | 基线 |
| 54 | 用户首页 | `GET` | `/user/license` | `UserDashboard/license` | 基线 |
| 55 | 用户首页 | `GET` | `/user/login` | `UserDashboard/loginLog` | 基线 |
| 56 | 个人资料 | `GET` | `/user/profile` | `UserDashboard/profile` | 基线 |
| 57 | 个人资料 | `PUT` | `/user/profile/notify` | `UserDashboard/saveNotify` | 基线 |
| 58 | 个人资料 | `PUT` | `/user/profile/passwd` | `UserDashboard/savePasswd` | 基线 |
| 59 | 个人资料 | `PUT` | `/user/profile/personalise` | `UserDashboard/savePersonalise` | 基线 |
| 60 | 个人资料 | `PUT` | `/user/profile/refresh` | `UserDashboard/saveRefresh` | 基线 |
| 61 | 个人资料 | `GET` | `/user/profile/sshkey` | `UserDashboard/createSshKey` | 基线 |
| 62 | 个人资料 | `PUT` | `/user/profile/sshkey` | `UserDashboard/resetSshKey` | 基线 |
| 63 | 任务进度 | `GET` | `/user/progress/:uuid` | `UserTask/ajaxQuery` | 基线 |
| 64 | 共享/回收站 | `GET` | `/user/recycle` | `UserDashboard/recycle` | 基线 |
| 65 | Azure VM | `RESOURCE` | `/user/server/azure` | `UserAzureServer` | 基线 |
| 66 | Azure VM | `PATCH` | `/user/server/azure/:action/:uuid` | `UserAzureServer/status` | 基线 |
| 67 | Azure VM | `GET` | `/user/server/azure/:id/chart/[:gap]` | `UserAzureServer/chart` | 基线 |
| 68 | Azure VM | `POST` | `/user/server/azure/available` | `UserAzureServer/available` | 基线 |
| 69 | Azure VM | `POST` | `/user/server/azure/change/:uuid` | `UserAzureServer/change` | 基线 |
| 70 | Azure VM | `POST` | `/user/server/azure/check/:ipv4` | `UserAzureServer/check` | 基线 |
| 71 | Azure VM | `PUT` | `/user/server/azure/credential/:uuid` | `UserAzureServer/credential` | 基线 |
| 72 | Azure VM | `DELETE` | `/user/server/azure/destroy/:uuid` | `UserAzureServer/destroy` | 基线 |
| 73 | 防火墙 | `GET` | `/user/server/azure/firewall/:uuid` | `UserAzureServer/firewall` | 基线 |
| 74 | 防火墙 | `POST` | `/user/server/azure/firewall/:uuid` | `UserAzureServer/createFirewallRule` | 基线 |
| 75 | 防火墙 | `DELETE` | `/user/server/azure/firewall/:uuid/:name` | `UserAzureServer/deleteFirewallRule` | 基线 |
| 76 | 防火墙 | `PUT` | `/user/server/azure/firewall/:uuid/:name` | `UserAzureServer/updateFirewallRule` | 基线 |
| 77 | Azure VM | `POST` | `/user/server/azure/price` | `UserAzureServer/price` | 基线 |
| 78 | Azure VM | `PUT` | `/user/server/azure/redisk/:uuid` | `UserAzureServer/redisk` | 基线 |
| 79 | Azure VM | `POST` | `/user/server/azure/refresh/:uuid` | `UserAzureServer/refresh` | 基线 |
| 80 | Azure VM | `PUT` | `/user/server/azure/reimage/:uuid` | `UserAzureServer/reimage` | 基线 |
| 81 | Azure VM | `POST` | `/user/server/azure/remark/:uuid` | `UserAzureServer/remark` | 基线 |
| 82 | Azure VM | `DELETE` | `/user/server/azure/remove/:uuid` | `UserAzureServer/delete` | 基线 |
| 83 | Azure VM | `PUT` | `/user/server/azure/resize/:uuid` | `UserAzureServer/resize` | 基线 |
| 84 | 流量规则 | `RESOURCE` | `/user/server/azure/rule` | `UserAzureServerRule` | 基线 |
| 85 | 流量规则 | `PUT` | `/user/server/azure/rule/:uuid` | `UserAzureServer/update` | 基线 |
| 86 | 流量规则 | `GET` | `/user/server/azure/rule/log` | `UserAzureServerRule/log` | 基线 |
| 87 | Azure VM | `POST` | `/user/server/azure/search` | `UserAzureServer/search` | 基线 |
| 88 | Azure VM | `POST` | `/user/server/azure/sync/:uuid` | `UserAzureServer/sync` | 基线 |
| 89 | 共享/回收站 | `GET` | `/user/share` | `UserDashboard/shareList` | 基线 |
## Controller public 方法盘点

每个 public 方法仅按当前源码归档。`显式` 来自普通路由声明；`resource` 仅标记 Controller 中实际存在的约定动作；其余方法记为“未显式绑定”，不推定为 HTTP 功能。`static` 后缀用于区分内部静态 API。

| Controller | public 方法（完整） | 当前 `route/app.php` 绑定 | 迁移状态 |
| --- | --- | --- | --- |
| `AdminAnn` | `AdminAnn::index`<br>`AdminAnn::create`<br>`AdminAnn::save`<br>`AdminAnn::edit`<br>`AdminAnn::update`<br>`AdminAnn::delete` | resource `/admin/ann` → 当前存在 `index`、`create`、`save`、`edit`、`update`、`delete` | 基线 |
| `AdminBase` | `AdminBase::initialize` | 未显式绑定：`initialize` | 基线 |
| `AdminDashboard` | `AdminDashboard::index` | 显式 `index` → `GET /admin` | 基线 |
| `AdminLog` | `AdminLog::login`<br>`AdminLog::verify`<br>`AdminLog::resize`<br>`AdminLog::traffic`<br>`AdminLog::task`<br>`AdminLog::taskDetails` | 显式 `login` → `GET /admin/log/login`<br>显式 `verify` → `GET /admin/log/verify`<br>显式 `resize` → `GET /admin/log/resize`<br>显式 `traffic` → `GET /admin/log/traffic`<br>显式 `task` → `GET /admin/log/task`<br>显式 `taskDetails` → `GET /admin/log/task/:id` | 基线 |
| `AdminSetting` | `AdminSetting::baseIndex`<br>`AdminSetting::baseSave`<br>`AdminSetting::emailIndex`<br>`AdminSetting::emailSave`<br>`AdminSetting::emailPushTest`<br>`AdminSetting::telegramPushTest`<br>`AdminSetting::telegramIndex`<br>`AdminSetting::telegramSave`<br>`AdminSetting::customIndex`<br>`AdminSetting::customSave`<br>`AdminSetting::resolvIndex`<br>`AdminSetting::resolvSave` | 显式 `baseIndex` → `GET /admin/setting`<br>显式 `baseSave` → `PUT /admin/setting`<br>显式 `emailIndex` → `GET /admin/setting/email`<br>显式 `emailSave` → `PUT /admin/setting/email`<br>显式 `emailPushTest` → `POST /admin/setting/email/test`<br>显式 `telegramPushTest` → `POST /admin/setting/telegram/test`<br>显式 `telegramIndex` → `GET /admin/setting/telegram`<br>显式 `telegramSave` → `PUT /admin/setting/telegram`<br>显式 `customIndex` → `GET /admin/setting/custom`<br>显式 `customSave` → `PUT /admin/setting/custom`<br>显式 `resolvIndex` → `GET /admin/setting/resolv`<br>显式 `resolvSave` → `PUT /admin/setting/resolv` | 基线 |
| `AdminUser` | `AdminUser::index`<br>`AdminUser::create`<br>`AdminUser::update`<br>`AdminUser::edit`<br>`AdminUser::save`<br>`AdminUser::remark`<br>`AdminUser::delete`<br>`AdminUser::userAssets`<br>`AdminUser::userReport` | 显式 `remark` → `PATCH /admin/user/remark/:id`<br>显式 `userAssets` → `GET /admin/user/assets/:id`<br>显式 `userReport` → `GET /admin/user/report`<br>resource `/admin/user` → 当前存在 `index`、`create`、`update`、`edit`、`save`、`delete` | 基线 |
| `Ali` | `Ali::count [static]`<br>`Ali::createOrUpdate [static]`<br>`Ali::search [static]`<br>`Ali::create [static]` | 未显式绑定：`count`、`createOrUpdate`、`search`、`create` | 基线 |
| `Auth` | `Auth::index`<br>`Auth::login`<br>`Auth::logout`<br>`Auth::registerIndex`<br>`Auth::registerCode`<br>`Auth::publicRegister`<br>`Auth::forgetIndex`<br>`Auth::forgetCode`<br>`Auth::resetPassword` | 显式 `index` → `GET /`、`HEAD /`、`GET /login`、`HEAD /login`<br>显式 `login` → `POST /login`<br>显式 `logout` → `POST /logout`<br>显式 `registerIndex` → `GET /register`<br>显式 `registerCode` → `POST /register/code`<br>显式 `publicRegister` → `POST /register`<br>显式 `forgetIndex` → `GET /forget`<br>显式 `forgetCode` → `POST /forget/code`<br>显式 `resetPassword` → `POST /forget` | 基线 |
| `AzureApi` | `AzureApi::getAzureAccessToken [static]`<br>`AzureApi::getToken [static]`<br>`AzureApi::getAzureSubscription [static]`<br>`AzureApi::registerMainAzureProviders [static]`<br>`AzureApi::getAzureResourceGroupsList [static]`<br>`AzureApi::getAzureResourceGroup [static]`<br>`AzureApi::getAzureValidResourceGroupsList [static]`<br>`AzureApi::getAzureNetworkInterfacesDetails [static]`<br>`AzureApi::getAzureVirtualMachineStatus [static]`<br>`AzureApi::getAzureVirtualMachines [static]`<br>`AzureApi::readAzureVirtualMachinesList [static]`<br>`AzureApi::manageVirtualMachine [static]`<br>`AzureApi::virtualMachinesDeallocate [static]`<br>`AzureApi::deleteAzureResourcesGroup [static]`<br>`AzureApi::deleteAzureResourcesGroupByUrl [static]`<br>`AzureApi::createAzureResourceGroup [static]`<br>`AzureApi::createNetworkSecurityGroups [static]`<br>`AzureApi::createAzurePublicNetworkIpv4 [static]`<br>`AzureApi::createAzurePublicNetworkIpv6 [static]`<br>`AzureApi::countAzurePublicNetworkIpv4 [static]`<br>`AzureApi::createAzureVirtualNetwork [static]`<br>`AzureApi::createAzureVirtualNetworkSubnets [static]`<br>`AzureApi::createAzureVirtualNetworkInterfaces [static]`<br>`AzureApi::createAzureVm [static]`<br>`AzureApi::getVirtualMachineStatistics [static]`<br>`AzureApi::virtualMachinesResize [static]`<br>`AzureApi::virtualMachinesRedisk [static]`<br>`AzureApi::getQuota [static]`<br>`AzureApi::getDisks [static]`<br>`AzureApi::getResourceSkusList [static]`<br>`AzureApi::getVirtualMachine [static]`<br>`AzureApi::createManagedDiskFromImage [static]`<br>`AzureApi::deleteManagedDisk [static]`<br>`AzureApi::getManagedDisk [static]`<br>`AzureApi::updateVirtualMachineOsDisk [static]`<br>`AzureApi::updateLinuxVmAccess [static]`<br>`AzureApi::updateWindowsVmAccess [static]`<br>`AzureApi::runLinuxShellCommand [static]`<br>`AzureApi::getNetworkSecurityGroup [static]`<br>`AzureApi::createNetworkSecurityGroup [static]`<br>`AzureApi::updateNetworkInterface [static]`<br>`AzureApi::listSecurityRules [static]`<br>`AzureApi::saveSecurityRule [static]`<br>`AzureApi::deleteSecurityRule [static]`<br>`AzureApi::deleteVirtualMachine [static]`<br>`AzureApi::createVirtualMachineAttached [static]` | 未显式绑定：`getAzureAccessToken`、`getToken`、`getAzureSubscription`、`registerMainAzureProviders`、`getAzureResourceGroupsList`、`getAzureResourceGroup`、`getAzureValidResourceGroupsList`、`getAzureNetworkInterfacesDetails`、`getAzureVirtualMachineStatus`、`getAzureVirtualMachines`、`readAzureVirtualMachinesList`、`manageVirtualMachine`、`virtualMachinesDeallocate`、`deleteAzureResourcesGroup`、`deleteAzureResourcesGroupByUrl`、`createAzureResourceGroup`、`createNetworkSecurityGroups`、`createAzurePublicNetworkIpv4`、`createAzurePublicNetworkIpv6`、`countAzurePublicNetworkIpv4`、`createAzureVirtualNetwork`、`createAzureVirtualNetworkSubnets`、`createAzureVirtualNetworkInterfaces`、`createAzureVm`、`getVirtualMachineStatistics`、`virtualMachinesResize`、`virtualMachinesRedisk`、`getQuota`、`getDisks`、`getResourceSkusList`、`getVirtualMachine`、`createManagedDiskFromImage`、`deleteManagedDisk`、`getManagedDisk`、`updateVirtualMachineOsDisk`、`updateLinuxVmAccess`、`updateWindowsVmAccess`、`runLinuxShellCommand`、`getNetworkSecurityGroup`、`createNetworkSecurityGroup`、`updateNetworkInterface`、`listSecurityRules`、`saveSecurityRule`、`deleteSecurityRule`、`deleteVirtualMachine`、`createVirtualMachineAttached` | 基线 |
| `AzureList` | `AzureList::images [static]`<br>`AzureList::sizes [static]`<br>`AzureList::locations [static]`<br>`AzureList::defaultPersonalise [static]`<br>`AzureList::diskSizes [static]`<br>`AzureList::diskTiers [static]` | 未显式绑定：`images`、`sizes`、`locations`、`defaultPersonalise`、`diskSizes`、`diskTiers` | 基线 |
| `Ip` | `Ip::__construct`<br>`Ip::__destruct`<br>`Ip::checkIp`<br>`Ip::getLong4`<br>`Ip::getLong3`<br>`Ip::getInfo`<br>`Ip::getArea`<br>`Ip::ip2addr` | 未显式绑定：`__construct`、`__destruct`、`checkIp`、`getLong4`、`getLong3`、`getInfo`、`getArea`、`ip2addr` | 基线 |
| `Notify` | `Notify::email [static]`<br>`Notify::telegram [static]` | 未显式绑定：`email`、`telegram` | 基线 |
| `ProxyController` | `ProxyController::test` | 显式 `test` → `POST /proxy/test` | 基线 |
| `Share` | `Share::getShare` | 显式 `getShare` → `GET /share` | 基线 |
| `Tools` | `Tools::encryption [static]`<br>`Tools::isIpv4 [static]`<br>`Tools::ipInfo [static]`<br>`Tools::msg [static]`<br>`Tools::emailCheck [static]`<br>`Tools::getClientIp [static]`<br>`Tools::getUnixTimestamp [static]`<br>`Tools::verifyHcaptcha [static]`<br>`Tools::getMailAddress [static]`<br>`Tools::getJsonContent [static]` | 未显式绑定：`encryption`、`isIpv4`、`ipInfo`、`msg`、`emailCheck`、`getClientIp`、`getUnixTimestamp`、`verifyHcaptcha`、`getMailAddress`、`getJsonContent` | 基线 |
| `UserAzure` | `UserAzure::index`<br>`UserAzure::searchAccount`<br>`UserAzure::shareAccount`<br>`UserAzure::processShare`<br>`UserAzure::create`<br>`UserAzure::read`<br>`UserAzure::edit`<br>`UserAzure::discern [static]`<br>`UserAzure::save`<br>`UserAzure::update`<br>`UserAzure::delete`<br>`UserAzure::getEarliestTime [static]`<br>`UserAzure::estimatedCost [static]`<br>`UserAzure::refreshTheResourceStatusUnderTheAccount [static]`<br>`UserAzure::refreshAzureSubscriptionStatus`<br>`UserAzure::refreshAllAzureSubscriptionStatus`<br>`UserAzure::updateAzureSubscriptionResources`<br>`UserAzure::deleteAzureDisabledSubscription`<br>`UserAzure::deleteResourceGroup`<br>`UserAzure::readResourceGroupsList`<br>`UserAzure::readResourceGroup`<br>`UserAzure::queryAccountQuota` | 显式 `searchAccount` → `POST /user/azure/search`<br>显式 `shareAccount` → `PUT /user/azure/share`<br>显式 `processShare` → `POST /user/azure/share`<br>显式 `estimatedCost` → `POST /user/azure/cost/:id`<br>显式 `refreshAzureSubscriptionStatus` → `POST /user/azure/refresh/:id`<br>显式 `refreshAllAzureSubscriptionStatus` → `POST /user/azure/refresh`<br>显式 `updateAzureSubscriptionResources` → `POST /user/azure/update/:id`<br>显式 `deleteAzureDisabledSubscription` → `DELETE /user/azure/disabled`<br>显式 `deleteResourceGroup` → `DELETE /user/azure/resources`<br>显式 `readResourceGroupsList` → `GET /user/azure/resources/:id`<br>显式 `readResourceGroup` → `GET /user/azure/resources/:id/:name`<br>显式 `queryAccountQuota` → `POST /user/azure/quota/:id`<br>resource `/user/azure` → 当前存在 `index`、`create`、`read`、`edit`、`save`、`update`、`delete`<br>未显式绑定：`discern`、`getEarliestTime`、`refreshTheResourceStatusUnderTheAccount` | 基线 |
| `UserAzureServer` | `UserAzureServer::index`<br>`UserAzureServer::create`<br>`UserAzureServer::update`<br>`UserAzureServer::save`<br>`UserAzureServer::read`<br>`UserAzureServer::delete`<br>`UserAzureServer::destroy`<br>`UserAzureServer::remark`<br>`UserAzureServer::resize`<br>`UserAzureServer::redisk`<br>`UserAzureServer::status`<br>`UserAzureServer::refresh [static]`<br>`UserAzureServer::change`<br>`UserAzureServer::check`<br>`UserAzureServer::sync`<br>`UserAzureServer::processGeneralData [static]`<br>`UserAzureServer::processNetworkData [static]`<br>`UserAzureServer::chart`<br>`UserAzureServer::search`<br>`UserAzureServer::available`<br>`UserAzureServer::price`<br>`UserAzureServer::reimage`<br>`UserAzureServer::credential`<br>`UserAzureServer::firewall`<br>`UserAzureServer::createFirewallRule`<br>`UserAzureServer::updateFirewallRule`<br>`UserAzureServer::deleteFirewallRule` | 显式 `update` → `PUT /user/server/azure/rule/:uuid`<br>显式 `delete` → `DELETE /user/server/azure/remove/:uuid`<br>显式 `destroy` → `DELETE /user/server/azure/destroy/:uuid`<br>显式 `remark` → `POST /user/server/azure/remark/:uuid`<br>显式 `resize` → `PUT /user/server/azure/resize/:uuid`<br>显式 `redisk` → `PUT /user/server/azure/redisk/:uuid`<br>显式 `status` → `PATCH /user/server/azure/:action/:uuid`<br>显式 `refresh` → `POST /user/server/azure/refresh/:uuid`<br>显式 `change` → `POST /user/server/azure/change/:uuid`<br>显式 `check` → `POST /user/server/azure/check/:ipv4`<br>显式 `sync` → `POST /user/server/azure/sync/:uuid`<br>显式 `chart` → `GET /user/server/azure/:id/chart/[:gap]`<br>显式 `search` → `POST /user/server/azure/search`<br>显式 `available` → `POST /user/server/azure/available`<br>显式 `price` → `POST /user/server/azure/price`<br>显式 `reimage` → `PUT /user/server/azure/reimage/:uuid`<br>显式 `credential` → `PUT /user/server/azure/credential/:uuid`<br>显式 `firewall` → `GET /user/server/azure/firewall/:uuid`<br>显式 `createFirewallRule` → `POST /user/server/azure/firewall/:uuid`<br>显式 `updateFirewallRule` → `PUT /user/server/azure/firewall/:uuid/:name`<br>显式 `deleteFirewallRule` → `DELETE /user/server/azure/firewall/:uuid/:name`<br>resource `/user/server/azure` → 当前存在 `index`、`create`、`update`、`save`、`read`、`delete`<br>未显式绑定：`processGeneralData`、`processNetworkData` | 基线 |
| `UserAzureServerRule` | `UserAzureServerRule::index`<br>`UserAzureServerRule::create`<br>`UserAzureServerRule::read`<br>`UserAzureServerRule::save`<br>`UserAzureServerRule::edit`<br>`UserAzureServerRule::update`<br>`UserAzureServerRule::delete`<br>`UserAzureServerRule::log` | 显式 `log` → `GET /user/server/azure/rule/log`<br>resource `/user/server/azure/rule` → 当前存在 `index`、`create`、`read`、`save`、`edit`、`update`、`delete` | 基线 |
| `UserBase` | `UserBase::initialize` | 未显式绑定：`initialize` | 基线 |
| `UserDashboard` | `UserDashboard::index`<br>`UserDashboard::recycle`<br>`UserDashboard::shareList`<br>`UserDashboard::loginLog`<br>`UserDashboard::profile`<br>`UserDashboard::savePasswd`<br>`UserDashboard::saveNotify`<br>`UserDashboard::savePersonalise`<br>`UserDashboard::saveRefresh`<br>`UserDashboard::createSshKey`<br>`UserDashboard::resetSshKey`<br>`UserDashboard::license`<br>`UserDashboard::docs` | 显式 `index` → `GET /user`<br>显式 `recycle` → `GET /user/recycle`<br>显式 `shareList` → `GET /user/share`<br>显式 `loginLog` → `GET /user/login`<br>显式 `profile` → `GET /user/profile`<br>显式 `savePasswd` → `PUT /user/profile/passwd`<br>显式 `saveNotify` → `PUT /user/profile/notify`<br>显式 `savePersonalise` → `PUT /user/profile/personalise`<br>显式 `saveRefresh` → `PUT /user/profile/refresh`<br>显式 `createSshKey` → `GET /user/profile/sshkey`<br>显式 `resetSshKey` → `PUT /user/profile/sshkey`<br>显式 `license` → `GET /user/license`<br>显式 `docs` → `GET /user/docs` | 基线 |
| `UserTask` | `UserTask::create [static]`<br>`UserTask::query [static]`<br>`UserTask::ajaxQuery [static]`<br>`UserTask::update [static]`<br>`UserTask::end [static]` | 显式 `ajaxQuery` → `GET /user/progress/:uuid`<br>未显式绑定：`create`、`query`、`update`、`end` | 基线 |

### Resource 声明与现有动作差异

ThinkPHP resource 约定动作按 `index/create/save/read/edit/update/delete` 核对。下表只记录源码差异，不宣称缺失动作可用。

| Resource 声明 | Controller 中现有约定动作 | 缺失约定动作 | 迁移状态 |
| --- | --- | --- | --- |
| `/admin/ann` → `AdminAnn` | `index`、`create`、`save`、`edit`、`update`、`delete` | `read` | 基线 |
| `/admin/user` → `AdminUser` | `index`、`create`、`save`、`edit`、`update`、`delete` | `read` | 基线 |
| `/user/azure` → `UserAzure` | `index`、`create`、`save`、`read`、`edit`、`update`、`delete` | 无 | 基线 |
| `/user/server/azure` → `UserAzureServer` | `index`、`create`、`save`、`read`、`update`、`delete` | `edit` | 基线 |
| `/user/server/azure/rule` → `UserAzureServerRule` | `index`、`create`、`save`、`read`、`edit`、`update`、`delete` | 无 | 基线 |
## HTML 模板清单

共 54 个 `.html` 文件。无直接 `View::fetch` 引用的文件仅按现状登记，不推定用户入口。

| # | 模板 | 当前所有者/用途 | 迁移状态 |
| ---: | --- | --- | --- |
| 1 | `app/view/admin/ann/create.html` | `AdminAnn::create` | 基线 |
| 2 | `app/view/admin/ann/edit.html` | `AdminAnn::edit` | 基线 |
| 3 | `app/view/admin/ann/index.html` | `AdminAnn::index` | 基线 |
| 4 | `app/view/admin/footer.html` | 管理员布局 partial | 基线 |
| 5 | `app/view/admin/header.html` | 管理员布局 partial | 基线 |
| 6 | `app/view/admin/index.html` | `AdminDashboard::index` | 基线 |
| 7 | `app/view/admin/log/login.html` | `AdminLog::login` | 基线 |
| 8 | `app/view/admin/log/resize.html` | `AdminLog::resize` | 基线 |
| 9 | `app/view/admin/log/task.html` | `AdminLog::task` | 基线 |
| 10 | `app/view/admin/log/task_details.html` | `AdminLog::taskDetails` | 基线 |
| 11 | `app/view/admin/log/traffic.html` | `AdminLog::traffic` | 基线 |
| 12 | `app/view/admin/log/verify.html` | `AdminLog::verify` | 基线 |
| 13 | `app/view/admin/setting/custom.html` | `AdminSetting::customIndex` | 基线 |
| 14 | `app/view/admin/setting/email.html` | `AdminSetting::emailIndex` | 基线 |
| 15 | `app/view/admin/setting/index.html` | `AdminSetting::baseIndex` | 基线 |
| 16 | `app/view/admin/setting/resolv.html` | `AdminSetting::resolvIndex` | 基线 |
| 17 | `app/view/admin/setting/telegram.html` | `AdminSetting::telegramIndex` | 基线 |
| 18 | `app/view/admin/user/assets.html` | `AdminUser::userAssets` | 基线 |
| 19 | `app/view/admin/user/create.html` | `AdminUser::create` | 基线 |
| 20 | `app/view/admin/user/edit.html` | `AdminUser::edit` | 基线 |
| 21 | `app/view/admin/user/index.html` | `AdminUser::index` | 基线 |
| 22 | `app/view/admin/user/read.html` | 无直接 `View::fetch` 引用（不推定入口） | 基线 |
| 23 | `app/view/admin/user/report.html` | `AdminUser::userReport` | 基线 |
| 24 | `app/view/auth/footer.html` | 认证布局 partial | 基线 |
| 25 | `app/view/auth/forget.html` | `Auth::forgetIndex` | 基线 |
| 26 | `app/view/auth/header.html` | 认证布局 partial | 基线 |
| 27 | `app/view/auth/login.html` | `Auth::index` | 基线 |
| 28 | `app/view/auth/register.html` | `Auth::registerIndex` | 基线 |
| 29 | `app/view/user/azure/create.html` | `UserAzure::create` | 基线 |
| 30 | `app/view/user/azure/edit.html` | `UserAzure::edit` | 基线 |
| 31 | `app/view/user/azure/groups.html` | `UserAzure::readResourceGroup` | 基线 |
| 32 | `app/view/user/azure/index.html` | `UserAzure::index` | 基线 |
| 33 | `app/view/user/azure/read.html` | `UserAzure::read` | 基线 |
| 34 | `app/view/user/azure/resources.html` | `UserAzure::readResourceGroupsList` | 基线 |
| 35 | `app/view/user/azure/rule/create.html` | `UserAzureServerRule::create` | 基线 |
| 36 | `app/view/user/azure/rule/edit.html` | `UserAzureServerRule::edit` | 基线 |
| 37 | `app/view/user/azure/rule/index.html` | `UserAzureServerRule::index` | 基线 |
| 38 | `app/view/user/azure/rule/read.html` | `UserAzureServerRule::read` | 基线 |
| 39 | `app/view/user/azure/rule/traffic.html` | `UserAzureServerRule::log` | 基线 |
| 40 | `app/view/user/azure/server/chart.html` | `UserAzureServer::chart` | 基线 |
| 41 | `app/view/user/azure/server/create.html` | `UserAzureServer::create` | 基线 |
| 42 | `app/view/user/azure/server/index.html` | `UserAzureServer::index` | 基线 |
| 43 | `app/view/user/azure/server/read.html` | `UserAzureServer::read` | 基线 |
| 44 | `app/view/user/docs.html` | `UserDashboard::docs` | 基线 |
| 45 | `app/view/user/footer.html` | 用户布局 partial | 基线 |
| 46 | `app/view/user/header.html` | 用户布局 partial | 基线 |
| 47 | `app/view/user/index.html` | `UserDashboard::index` | 基线 |
| 48 | `app/view/user/license.html` | `UserDashboard::license` | 基线 |
| 49 | `app/view/user/loginlog.html` | `UserDashboard::loginLog` | 基线 |
| 50 | `app/view/user/profile.html` | `UserDashboard::profile` | 基线 |
| 51 | `app/view/user/recycle.html` | `UserDashboard::recycle` | 基线 |
| 52 | `app/view/user/reject.html` | 多个用户资源动作的拒绝响应 | 基线 |
| 53 | `app/view/user/share.html` | `UserDashboard::shareList` | 基线 |
| 54 | `app/view/user/traffic.html` | 无直接 `View::fetch` 引用（不推定入口） | 基线 |

## 控制台命令与计划任务

| 注册命令 | 公开动作 | 调度/入口 | 主要副作用 | 危险级别 | 迁移状态 |
| --- | --- | --- | --- | --- | --- |
| `tools` | `--action statisticsTraffic`、`--action setVersion --newVersion ...` | `statisticsTraffic` 每日 00:00；`setVersion` 手工 | 写流量统计或版本配置 | 中 | 基线 |
| `createAdmin` | `--email`、`--passwd` | 手工；`deploy.sh install` 可调用 | 创建管理员与默认个性化设置 | 高 | 基线 |
| `closeTimeoutTask` | 无参数 | 每小时整点 | 关闭超时 Task | 中 | 基线 |
| `trafficControlStop` | 无参数 | 每小时整点 | 按流量规则停止 VM、写日志并创建恢复任务 | 高 | 基线 |
| `trafficControlStart` | 无参数 | 每 5 分钟 | 启动到期 VM、写日志并通知 | 高 | 基线 |
| `autoRefreshAccount` | 无参数 | 每小时整点 | 刷新订阅状态、更新 VM 状态并可发 Telegram | 中 | 基线 |

cron 来源为 `docker/cron/azpanel`；注册来源为 `config/console.php`。通知辅助入口为 `Notify::email` 与 `Notify::telegram`，均未在 `route/app.php` 直接绑定。

## Docker 运行与运维操作

| 入口 | 当前行为 | 保留/删除边界 | 危险级别 | 迁移状态 |
| --- | --- | --- | --- | --- |
| `docker compose`：`app` | PHP 8.3 FPM、Supervisor、cron；bind mount 源码与 PHP/Supervisor 配置 | 容器可重建；应用运行目录在项目 bind mount | 中 | 基线 |
| `docker compose`：`web` | Nginx 1.27 Alpine，HTTP/HTTPS，挂载证书与 ACME webroot | 容器可重建；证书在项目目录 | 中 | 基线 |
| `docker compose`：`db` | MariaDB 10.11 Jammy，healthcheck，named volume `azpanel-db` | volume 是持久数据边界 | 高 | 基线 |
| `bash deploy.sh install`（默认） | 生成环境文件、选择 HTTP/证书模式、构建并启动、安装依赖，可导 SQL/migrate/seed/建管理员 | 创建/覆盖生成配置并写数据库 | 高 | 基线 |
| `bash deploy.sh redeploy` | 重建 `app` 与 `web` 并重新安装依赖 | 明确保留数据库卷与配置 | 中 | 基线 |
| `bash deploy.sh uninstall` | `docker compose down` | 保留数据库卷、证书与配置 | 中 | 基线 |
| `bash deploy.sh purge` | 输入 `PURGE` 后 down volume/local image，并删除环境、运行 Nginx 配置及证书目录 | 删除数据库卷与生成配置；源码不删除 | 高 | 基线 |
| `bash deploy.sh renew-cert` | Certbot renew、复制证书并 reload Nginx | 修改证书文件 | 中 | 基线 |
| `bash deploy.sh backup-db` | `mariadb-dump` 到 `backups/azpanel-时间戳.sql` | 新增备份，不修改数据库 | 低 | 基线 |

## 基线维护规则

- 任何有意改变路由方法、路径或 Handler 的工作，必须先取得明确批准，再更新 `tests/Fixtures/http-contract.php`。
- 新增、删除或改名 Controller public 方法、模板、命令、cron 或 Docker 运维操作时，同步更新本矩阵。
- 对未显式绑定的方法与无直接引用模板，不把“文件存在”描述为“用户可达”；后续只可在运行验证后提升为受支持契约。
