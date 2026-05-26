# Azure VM NSG、系统重装与 Panel 单容器化设计

## 背景

当前项目是 ThinkPHP 8 面板，已有 Azure VM 创建、启停、重启、调整规格、扩盘、换 IP、流量控制规则等能力。现有 VM 创建流程只在 IPv6 场景创建并绑定 NSG，且默认规则包含全放入站；这不适合作为安全默认值，也无法在创建后通过面板维护 NSG 规则。

本次只针对 Azure VM 增强，不改 AWS 功能。

## 目标

1. Azure VM 创建时始终创建并绑定 NSG。
2. Azure VM 创建时可选择 NSG 预设：全放端口、常用端口、只 SSH/RDP。
3. Azure VM 详情页支持 Azure NSG 入站/出站规则查看、新增、编辑、删除。
4. Azure VM 支持系统重装。
5. 系统重装成功后删除原系统磁盘；失败时保留原系统磁盘。
6. Panel 做成单独应用容器，用户自行配置 nginx 和 MySQL/MariaDB。
7. Panel 容器内默认使用 supercronic 跑固定定时任务。

## 非目标

1. 不支持 AWS VM 的系统重装或防火墙规则管理。
2. 不在项目中内置 nginx 容器。
3. 不在项目中内置 MySQL/MariaDB 容器。
4. 不做批量 NSG 模板、跨 VM 批量套用规则。
5. 不改变现有用户、账户、流量控制规则的数据模型，除非实现时确实需要最小字段补充。

## Azure VM 创建时 NSG 预设

创建页增加“防火墙规则”配置，提供三种预设：

1. 全放端口
   - 入站允许全部协议、全部源、全部目标端口。
   - 出站允许全部。
   - 用于兼容旧版开放体验。

2. 常用端口
   - Linux 镜像：入站允许 `22/TCP`、`80/TCP`、`443/TCP`。
   - Windows 镜像：入站允许 `3389/TCP`、`80/TCP`、`443/TCP`。
   - 出站允许全部。
   - 默认选项。

3. 只 SSH/RDP
   - Linux 镜像：入站只允许 `22/TCP`。
   - Windows 镜像：入站只允许 `3389/TCP`。
   - 出站允许全部。

创建流程调整为：

1. 创建资源组。
2. 创建 NSG，并按预设生成 securityRules。
3. 创建公网 IPv4，按现有逻辑可选 IPv6。
4. 创建虚拟网络和子网。
5. 创建网卡并绑定 NSG。
6. 创建 VM。
7. 同步 VM 列表和详情缓存。

现有 IPv6 分支不再是创建 NSG 的唯一入口。无论是否创建 IPv6，都创建并绑定 NSG。

UI 约束：

1. 新增的创建页控件必须遵循原版页面风格，继续使用现有 MDUI 表单、`mdui-select`、栅格布局和 AJAX 提交方式。
2. 不引入新的前端框架、组件库或独立视觉风格。
3. 控件文案、按钮样式、提示方式应和现有创建 VM 页面保持一致。

## VM 详情页 NSG 规则管理

VM 详情页增加“防火墙规则”区域，读取当前 VM 网卡关联的 NSG，展示自定义规则和必要属性：

1. 名称。
2. 方向：Inbound 或 Outbound。
3. 协议：TCP、UDP、ICMP、`*`。
4. 源地址前缀或前缀列表。
5. 源端口或端口列表。
6. 目标地址前缀或前缀列表。
7. 目标端口或端口列表。
8. 动作：Allow 或 Deny。
9. 优先级。
10. 描述。

支持操作：

1. 新增规则。
2. 编辑规则。
3. 删除规则。
4. 刷新规则列表。

权限与归属检查沿用现有 Azure VM 详情页逻辑：用户只能操作自己账户下的 VM。

兼容旧 VM：

1. 如果 VM 网卡已有 NSG，直接管理该 NSG。
2. 如果 VM 网卡没有 NSG，用户首次进入规则管理或保存规则时，后端创建一个 NSG 并绑定到该 VM 网卡。
3. 创建并绑定 NSG 后刷新 `network_details`，保证后续页面读取到最新关联关系。

错误处理：

1. Azure API 返回错误时，把 Azure 错误消息返回给前端。
2. 删除不存在的规则视为失败并提示刷新。
3. 优先级冲突、规则名冲突由后端预校验；Azure 仍返回冲突时继续透传错误。

UI 约束：

1. 新增防火墙规则区域必须遵循原版 VM 详情页风格，优先复用现有卡片、表格、按钮、图标、弹窗和 snackbar 提示方式。
2. 表格、按钮颜色、间距、输入框和选择框不得设计成新的视觉体系。
3. 移动端表现沿用现有页面处理方式，表格内容较宽时使用现有可滚动表格风格。

## Azure VM 系统重装

VM 详情页增加“系统重装”操作。用户选择：

1. 目标系统镜像。
2. 管理员用户名。
3. 密码或 SSH key。
4. 可选初始化脚本。

重装流程：

1. 读取并记录当前 VM 的 OS Disk 名称和资源 ID。
2. Deallocate 当前 VM。
3. 删除 VM 计算资源，保留资源组、网卡、公网 IP、NSG。
4. 使用新镜像重新创建同名 VM，并绑定原网卡。
5. 等待 VM 进入 running 状态。
6. 刷新 `vm_details`、`instance_details`、`disk_details`、`network_details`、IP 地址、系统镜像字段。
7. 新 VM 创建并启动成功后，删除原 OS Disk。
8. 结束任务并返回成功。

失败处理：

1. 如果新 VM 未创建成功或未启动成功，不删除原 OS Disk。
2. 如果删除旧 OS Disk 失败，重装仍视为主要操作成功，但任务结果中记录清理失败信息，提示用户手动检查。
3. 任务过程接入现有 `UserTask` 进度机制，前端使用现有进度查询接口。

安全约束：

1. 重装必须确认 VM 属于当前用户。
2. 密码复杂度沿用创建 VM 时的校验规则。
3. Windows 镜像和 Linux 镜像沿用当前创建流程里的用户名、磁盘大小、镜像兼容性校验。

UI 约束：

1. 系统重装入口必须遵循原版 VM 详情页操作区风格，使用现有按钮、确认提示、表单和任务进度交互。
2. 重装属于高风险操作，确认提示的视觉表现应沿用现有销毁、扩盘等危险操作的交互习惯。
3. 不做新的向导式 UI 或大幅重排详情页。

## Panel 单容器化

容器只承载 panel 应用运行层，不包含 nginx 和数据库。

容器包含：

1. PHP-FPM。
2. PHP CLI。
3. Composer 依赖。
4. 项目代码。
5. 项目需要的 PHP 扩展。
6. supercronic。

用户外部负责：

1. nginx。
2. MySQL 或 MariaDB。
3. 数据库创建和 SQL 导入。
4. nginx 到容器 `9000` 端口的 FastCGI 配置。

交付文件：

1. `Dockerfile`。
2. `.env.docker.example`。
3. `docker/entrypoint.sh`。
4. `docker/supercronic.cron`。
5. `docker/nginx-example.conf`。
6. `README.md` 容器化部署说明。

容器启动行为：

1. 检查 `.env` 是否存在。
2. 确保 `runtime/` 可写。
3. 启动 `php-fpm`。
4. 启动 supercronic，执行固定定时任务。

固定定时任务：

```cron
0 0 * * * php /app/think tools --action statisticsTraffic
0 * * * * php /app/think autoRefreshAccount
0 * * * * php /app/think closeTimeoutTask
0 * * * * php /app/think trafficControlStop
*/5 * * * * php /app/think trafficControlStart
```

nginx 示例只作为参考，不作为容器编排的一部分。示例应指向用户挂载或部署的项目 `public/` 目录，并把 PHP 请求转发到 panel 容器的 `9000`。

## 测试策略

后端逻辑优先补充单元或集成级测试，覆盖：

1. NSG 预设规则生成。
2. NSG 规则输入校验。
3. 系统重装流程中旧 OS Disk 删除只发生在新 VM 成功后。
4. 旧 VM 无 NSG 时会创建并绑定 NSG。

无法直接访问真实 Azure 的测试使用可替换的 API 调用边界或集中 helper 测试请求体生成逻辑。涉及真实 Azure API 的路径保留人工验证步骤。

容器化验证：

1. 构建镜像成功。
2. 容器启动后 `php -v`、`php think` 可执行。
3. `php-fpm` 监听 `9000`。
4. supercronic 加载固定任务文件。
5. README 中的 nginx FastCGI 示例与容器路径一致。

## 实施顺序

1. 抽出 Azure NSG 规则请求体生成和校验逻辑。
2. 调整 VM 创建流程，接入 NSG 预设。
3. 增加 NSG 规则管理 API 和 VM 详情页 UI。
4. 增加系统重装 API 和 VM 详情页 UI。
5. 增加单 panel 容器化文件和中文部署文档。
6. 运行测试和可执行验证。
