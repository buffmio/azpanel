<?php

return array (
  0 =>
  array (
    'method' => 'GET',
    'path' => '/',
    'handler' => 'Auth/index',
  ),
  1 =>
  array (
    'method' => 'HEAD',
    'path' => '/',
    'handler' => 'Auth/index',
  ),
  2 =>
  array (
    'method' => 'GET',
    'path' => '/admin',
    'handler' => 'AdminDashboard/index',
  ),
  3 =>
  array (
    'method' => 'RESOURCE',
    'path' => '/admin/ann',
    'handler' => 'AdminAnn',
  ),
  4 =>
  array (
    'method' => 'GET',
    'path' => '/admin/log/login',
    'handler' => 'AdminLog/login',
  ),
  5 =>
  array (
    'method' => 'GET',
    'path' => '/admin/log/resize',
    'handler' => 'AdminLog/resize',
  ),
  6 =>
  array (
    'method' => 'GET',
    'path' => '/admin/log/task',
    'handler' => 'AdminLog/task',
  ),
  7 =>
  array (
    'method' => 'GET',
    'path' => '/admin/log/task/:id',
    'handler' => 'AdminLog/taskDetails',
  ),
  8 =>
  array (
    'method' => 'GET',
    'path' => '/admin/log/traffic',
    'handler' => 'AdminLog/traffic',
  ),
  9 =>
  array (
    'method' => 'GET',
    'path' => '/admin/log/verify',
    'handler' => 'AdminLog/verify',
  ),
  10 =>
  array (
    'method' => 'GET',
    'path' => '/admin/setting',
    'handler' => 'AdminSetting/baseIndex',
  ),
  11 =>
  array (
    'method' => 'PUT',
    'path' => '/admin/setting',
    'handler' => 'AdminSetting/baseSave',
  ),
  12 =>
  array (
    'method' => 'GET',
    'path' => '/admin/setting/custom',
    'handler' => 'AdminSetting/customIndex',
  ),
  13 =>
  array (
    'method' => 'PUT',
    'path' => '/admin/setting/custom',
    'handler' => 'AdminSetting/customSave',
  ),
  14 =>
  array (
    'method' => 'GET',
    'path' => '/admin/setting/email',
    'handler' => 'AdminSetting/emailIndex',
  ),
  15 =>
  array (
    'method' => 'PUT',
    'path' => '/admin/setting/email',
    'handler' => 'AdminSetting/emailSave',
  ),
  16 =>
  array (
    'method' => 'POST',
    'path' => '/admin/setting/email/test',
    'handler' => 'AdminSetting/emailPushTest',
  ),
  17 =>
  array (
    'method' => 'GET',
    'path' => '/admin/setting/resolv',
    'handler' => 'AdminSetting/resolvIndex',
  ),
  18 =>
  array (
    'method' => 'PUT',
    'path' => '/admin/setting/resolv',
    'handler' => 'AdminSetting/resolvSave',
  ),
  19 =>
  array (
    'method' => 'GET',
    'path' => '/admin/setting/telegram',
    'handler' => 'AdminSetting/telegramIndex',
  ),
  20 =>
  array (
    'method' => 'PUT',
    'path' => '/admin/setting/telegram',
    'handler' => 'AdminSetting/telegramSave',
  ),
  21 =>
  array (
    'method' => 'POST',
    'path' => '/admin/setting/telegram/test',
    'handler' => 'AdminSetting/telegramPushTest',
  ),
  22 =>
  array (
    'method' => 'RESOURCE',
    'path' => '/admin/user',
    'handler' => 'AdminUser',
  ),
  23 =>
  array (
    'method' => 'GET',
    'path' => '/admin/user/assets/:id',
    'handler' => 'AdminUser/userAssets',
  ),
  24 =>
  array (
    'method' => 'PATCH',
    'path' => '/admin/user/remark/:id',
    'handler' => 'AdminUser/remark',
  ),
  25 =>
  array (
    'method' => 'GET',
    'path' => '/admin/user/report',
    'handler' => 'AdminUser/userReport',
  ),
  26 =>
  array (
    'method' => 'GET',
    'path' => '/forget',
    'handler' => 'Auth/forgetIndex',
  ),
  27 =>
  array (
    'method' => 'POST',
    'path' => '/forget',
    'handler' => 'Auth/resetPassword',
  ),
  28 =>
  array (
    'method' => 'POST',
    'path' => '/forget/code',
    'handler' => 'Auth/forgetCode',
  ),
  29 =>
  array (
    'method' => 'GET',
    'path' => '/login',
    'handler' => 'Auth/index',
  ),
  30 =>
  array (
    'method' => 'HEAD',
    'path' => '/login',
    'handler' => 'Auth/index',
  ),
  31 =>
  array (
    'method' => 'POST',
    'path' => '/login',
    'handler' => 'Auth/login',
  ),
  32 =>
  array (
    'method' => 'POST',
    'path' => '/logout',
    'handler' => 'Auth/logout',
  ),
  33 =>
  array (
    'method' => 'POST',
    'path' => '/proxy/test',
    'handler' => 'ProxyController/test',
  ),
  34 =>
  array (
    'method' => 'GET',
    'path' => '/register',
    'handler' => 'Auth/registerIndex',
  ),
  35 =>
  array (
    'method' => 'POST',
    'path' => '/register',
    'handler' => 'Auth/publicRegister',
  ),
  36 =>
  array (
    'method' => 'POST',
    'path' => '/register/code',
    'handler' => 'Auth/registerCode',
  ),
  37 =>
  array (
    'method' => 'GET',
    'path' => '/share',
    'handler' => 'Share/getShare',
  ),
  38 =>
  array (
    'method' => 'GET',
    'path' => '/user',
    'handler' => 'UserDashboard/index',
  ),
  39 =>
  array (
    'method' => 'RESOURCE',
    'path' => '/user/azure',
    'handler' => 'UserAzure',
  ),
  40 =>
  array (
    'method' => 'POST',
    'path' => '/user/azure/cost/:id',
    'handler' => 'UserAzure/estimatedCost',
  ),
  41 =>
  array (
    'method' => 'DELETE',
    'path' => '/user/azure/disabled',
    'handler' => 'UserAzure/deleteAzureDisabledSubscription',
  ),
  42 =>
  array (
    'method' => 'POST',
    'path' => '/user/azure/quota/:id',
    'handler' => 'UserAzure/queryAccountQuota',
  ),
  43 =>
  array (
    'method' => 'POST',
    'path' => '/user/azure/refresh',
    'handler' => 'UserAzure/refreshAllAzureSubscriptionStatus',
  ),
  44 =>
  array (
    'method' => 'POST',
    'path' => '/user/azure/refresh/:id',
    'handler' => 'UserAzure/refreshAzureSubscriptionStatus',
  ),
  45 =>
  array (
    'method' => 'DELETE',
    'path' => '/user/azure/resources',
    'handler' => 'UserAzure/deleteResourceGroup',
  ),
  46 =>
  array (
    'method' => 'GET',
    'path' => '/user/azure/resources/:id',
    'handler' => 'UserAzure/readResourceGroupsList',
  ),
  47 =>
  array (
    'method' => 'GET',
    'path' => '/user/azure/resources/:id/:name',
    'handler' => 'UserAzure/readResourceGroup',
  ),
  48 =>
  array (
    'method' => 'POST',
    'path' => '/user/azure/search',
    'handler' => 'UserAzure/searchAccount',
  ),
  49 =>
  array (
    'method' => 'POST',
    'path' => '/user/azure/share',
    'handler' => 'UserAzure/processShare',
  ),
  50 =>
  array (
    'method' => 'PUT',
    'path' => '/user/azure/share',
    'handler' => 'UserAzure/shareAccount',
  ),
  51 =>
  array (
    'method' => 'POST',
    'path' => '/user/azure/update/:id',
    'handler' => 'UserAzure/updateAzureSubscriptionResources',
  ),
  52 =>
  array (
    'method' => 'GET',
    'path' => '/user/docs',
    'handler' => 'UserDashboard/docs',
  ),
  53 =>
  array (
    'method' => 'GET',
    'path' => '/user/license',
    'handler' => 'UserDashboard/license',
  ),
  54 =>
  array (
    'method' => 'GET',
    'path' => '/user/login',
    'handler' => 'UserDashboard/loginLog',
  ),
  55 =>
  array (
    'method' => 'GET',
    'path' => '/user/profile',
    'handler' => 'UserDashboard/profile',
  ),
  56 =>
  array (
    'method' => 'PUT',
    'path' => '/user/profile/notify',
    'handler' => 'UserDashboard/saveNotify',
  ),
  57 =>
  array (
    'method' => 'PUT',
    'path' => '/user/profile/passwd',
    'handler' => 'UserDashboard/savePasswd',
  ),
  58 =>
  array (
    'method' => 'PUT',
    'path' => '/user/profile/personalise',
    'handler' => 'UserDashboard/savePersonalise',
  ),
  59 =>
  array (
    'method' => 'PUT',
    'path' => '/user/profile/refresh',
    'handler' => 'UserDashboard/saveRefresh',
  ),
  60 =>
  array (
    'method' => 'GET',
    'path' => '/user/profile/sshkey',
    'handler' => 'UserDashboard/createSshKey',
  ),
  61 =>
  array (
    'method' => 'PUT',
    'path' => '/user/profile/sshkey',
    'handler' => 'UserDashboard/resetSshKey',
  ),
  62 =>
  array (
    'method' => 'GET',
    'path' => '/user/progress/:uuid',
    'handler' => 'UserTask/ajaxQuery',
  ),
  63 =>
  array (
    'method' => 'GET',
    'path' => '/user/recycle',
    'handler' => 'UserDashboard/recycle',
  ),
  64 =>
  array (
    'method' => 'RESOURCE',
    'path' => '/user/server/azure',
    'handler' => 'UserAzureServer',
  ),
  65 =>
  array (
    'method' => 'PATCH',
    'path' => '/user/server/azure/:action/:uuid',
    'handler' => 'UserAzureServer/status',
  ),
  66 =>
  array (
    'method' => 'GET',
    'path' => '/user/server/azure/:id/chart/[:gap]',
    'handler' => 'UserAzureServer/chart',
  ),
  67 =>
  array (
    'method' => 'POST',
    'path' => '/user/server/azure/available',
    'handler' => 'UserAzureServer/available',
  ),
  68 =>
  array (
    'method' => 'POST',
    'path' => '/user/server/azure/change/:uuid',
    'handler' => 'UserAzureServer/change',
  ),
  69 =>
  array (
    'method' => 'POST',
    'path' => '/user/server/azure/check/:ipv4',
    'handler' => 'UserAzureServer/check',
  ),
  70 =>
  array (
    'method' => 'PUT',
    'path' => '/user/server/azure/credential/:uuid',
    'handler' => 'UserAzureServer/credential',
  ),
  71 =>
  array (
    'method' => 'DELETE',
    'path' => '/user/server/azure/destroy/:uuid',
    'handler' => 'UserAzureServer/destroy',
  ),
  72 =>
  array (
    'method' => 'GET',
    'path' => '/user/server/azure/firewall/:uuid',
    'handler' => 'UserAzureServer/firewall',
  ),
  73 =>
  array (
    'method' => 'POST',
    'path' => '/user/server/azure/firewall/:uuid',
    'handler' => 'UserAzureServer/createFirewallRule',
  ),
  74 =>
  array (
    'method' => 'DELETE',
    'path' => '/user/server/azure/firewall/:uuid/:name',
    'handler' => 'UserAzureServer/deleteFirewallRule',
  ),
  75 =>
  array (
    'method' => 'PUT',
    'path' => '/user/server/azure/firewall/:uuid/:name',
    'handler' => 'UserAzureServer/updateFirewallRule',
  ),
  76 =>
  array (
    'method' => 'POST',
    'path' => '/user/server/azure/price',
    'handler' => 'UserAzureServer/price',
  ),
  77 =>
  array (
    'method' => 'PUT',
    'path' => '/user/server/azure/redisk/:uuid',
    'handler' => 'UserAzureServer/redisk',
  ),
  78 =>
  array (
    'method' => 'POST',
    'path' => '/user/server/azure/refresh/:uuid',
    'handler' => 'UserAzureServer/refresh',
  ),
  79 =>
  array (
    'method' => 'PUT',
    'path' => '/user/server/azure/reimage/:uuid',
    'handler' => 'UserAzureServer/reimage',
  ),
  80 =>
  array (
    'method' => 'POST',
    'path' => '/user/server/azure/remark/:uuid',
    'handler' => 'UserAzureServer/remark',
  ),
  81 =>
  array (
    'method' => 'DELETE',
    'path' => '/user/server/azure/remove/:uuid',
    'handler' => 'UserAzureServer/delete',
  ),
  82 =>
  array (
    'method' => 'PUT',
    'path' => '/user/server/azure/resize/:uuid',
    'handler' => 'UserAzureServer/resize',
  ),
  83 =>
  array (
    'method' => 'RESOURCE',
    'path' => '/user/server/azure/rule',
    'handler' => 'UserAzureServerRule',
  ),
  84 =>
  array (
    'method' => 'PUT',
    'path' => '/user/server/azure/rule/:uuid',
    'handler' => 'UserAzureServer/update',
  ),
  85 =>
  array (
    'method' => 'GET',
    'path' => '/user/server/azure/rule/log',
    'handler' => 'UserAzureServerRule/log',
  ),
  86 =>
  array (
    'method' => 'POST',
    'path' => '/user/server/azure/search',
    'handler' => 'UserAzureServer/search',
  ),
  87 =>
  array (
    'method' => 'POST',
    'path' => '/user/server/azure/sync/:uuid',
    'handler' => 'UserAzureServer/sync',
  ),
  88 =>
  array (
    'method' => 'GET',
    'path' => '/user/share',
    'handler' => 'UserDashboard/shareList',
  ),
);
