# Azure VM Management Design

Date: 2026-06-20

## Scope

Add Azure VM operations for:

- Reinstalling a VM to a selected image while preserving the VM resource, resource group, network interface, public IP, and size where Azure supports it.
- Rolling back a failed reinstall to the original OS disk when the failure happens after replacement begins.
- Cleaning up replaced or temporary reinstall resources after a successful reinstall.
- Resetting VM login credentials for Linux and Windows VMs.
- Managing full Azure Network Security Group security rules for a VM.
- Removing the AWS feature surface from the application while retaining existing database tables and migrations.

The implementation stays inside the existing ThinkPHP structure and follows the current `UserAzureServer` + `AzureApi` pattern.

## Non-Goals

- Do not drop or migrate away existing AWS database tables.
- Do not add SSH key injection to the credential reset flow in the first version.
- Do not introduce a cloud-provider abstraction layer.
- Do not redesign the global UI.

## Architecture

Extend the existing Azure VM module instead of adding a separate subsystem.

Routes added to `route/app.php`:

- `PUT /user/server/azure/reimage/:uuid`
- `PUT /user/server/azure/credential/:uuid`
- `GET /user/server/azure/firewall/:uuid`
- `POST /user/server/azure/firewall/:uuid`
- `PUT /user/server/azure/firewall/:uuid/:name`
- `DELETE /user/server/azure/firewall/:uuid/:name`

`app/controller/UserAzureServer.php` will own:

- Current-user ownership checks for each VM operation.
- Request validation and user-facing error messages.
- Task creation and progress updates for long-running operations.
- Refreshing local VM and network snapshots after Azure changes.

`app/controller/AzureApi.php` will own Azure REST calls:

- VM reimage.
- VM extension create/update for credential reset.
- Network interface update.
- Network security group create/get.
- Security rule list/create/update/delete.

The VM detail page remains the main management surface. The VM list page may expose shortcuts to the detail page, but rule editing and reimage forms live on the detail page to keep the table menu manageable.

## Reimage Flow

The VM detail page adds a "Reinstall System" card with:

- Target image from `AzureList::images()`.
- Admin username.
- Admin password.
- Confirmation text that the OS disk data will be replaced.

Server flow:

1. Load `AzureServer` by `vm_id` and `user_id`.
2. Validate image key against `AzureList::images()`.
3. Validate username and password with the same policy used during VM creation.
4. Create a `UserTask` named `重装虚拟机系统`.
5. Stop and deallocate the VM if needed.
6. Read and store the current OS disk ID, name, storage account type, delete option, image metadata, and VM model in the task parameters.
7. Create a replacement OS disk from the selected image.
8. Update the VM model so the OS disk points to the replacement disk and the requested `osProfile` credentials.
9. Start the VM and poll instance status until Azure reports a stable running state or a timeout is reached.
10. Refresh VM details, instance details, OS offer/SKU, disk details, status, and timestamps in `azure_server`.
11. Delete the replaced original OS disk and any temporary reinstall resources after the replacement VM has started successfully.
12. End the task and return a JSON result.

Rollback behavior:

- If validation fails before disk replacement starts, no rollback is needed.
- If Azure rejects replacement disk creation, keep the original VM unchanged.
- If the VM update or startup fails after the replacement disk is attached, detach the replacement disk, reattach the original OS disk using the stored VM model data, start the VM, refresh local details, and mark the task as failed with rollback status.
- If rollback also fails, keep the task failed and return both the original error and rollback error so an operator can repair the VM in Azure.

Cleanup behavior:

- On success, delete the original OS disk that was replaced.
- Delete replacement attempt artifacts that are no longer attached.
- Do not delete the current attached OS disk, NIC, public IP, NSG, resource group, or data disks.

The implementation uses explicit OS disk replacement instead of relying only on Azure VM Reimage. This is required so the application can keep a known rollback point and delete replaced resources after success.

## Credential Reset Flow

The VM detail page adds a "Reset Credentials" card with:

- OS type: Linux or Windows.
- Username.
- New password.

Server flow:

1. Load `AzureServer` by `vm_id` and `user_id`.
2. Validate username and password.
3. For Linux, deploy or update the `VMAccessForLinux` extension.
4. For Windows, deploy or update the `VMAccessAgent` extension.
5. Return success after Azure accepts the extension operation.

The new password is not stored in the application database or task parameters. Errors from Azure are returned to the user.

## Firewall Rule Management

Firewall management targets the Azure NSG attached to the VM's primary network interface.

NSG discovery:

1. Decode `AzureServer.network_details`.
2. If the primary NIC already has `networkSecurityGroup.id`, use it.
3. If no NSG is attached, create `{vm_name}_security` in the VM resource group and update the NIC to attach it.
4. Refresh and store `network_details`.

The firewall page or dialog lists:

- Custom security rules from the NSG.
- Azure default rules as read-only rows when available.

Supported fields for create/update:

- Name.
- Direction: `Inbound` or `Outbound`.
- Protocol: `Tcp`, `Udp`, `Icmp`, or `*`.
- Access: `Allow` or `Deny`.
- Priority: integer `100-4096`, unique within the NSG.
- Source address prefix.
- Source port range.
- Destination address prefix.
- Destination port range.
- Description, limited to Azure's accepted length.

Validation:

- Rule name must be non-empty and contain only letters, numbers, underscores, dots, or hyphens.
- Priority must be between `100` and `4096`.
- Priority must not duplicate an existing rule unless updating that same rule.
- Port ranges accept `*`, a single port, or a range like `1000-2000`.
- Address prefixes accept `*`, CIDR, IP/range strings, or Azure service tag strings. Azure remains the source of truth for final validation.

Delete operations require confirmation in the UI. Azure default rules are not deletable.

## AWS Removal

Remove the user-visible and callable AWS feature surface:

- Delete AWS routes from `route/app.php`.
- Remove AWS menu sections from `app/view/user/header.html`.
- Delete AWS controllers:
  - `app/controller/UserAws.php`
  - `app/controller/UserAwsServer.php`
  - `app/controller/AwsApi.php`
  - `app/controller/AwsList.php`
- Delete AWS model:
  - `app/model/Aws.php`
- Delete AWS views:
  - `app/view/user/aws/`
- Remove `aws/aws-sdk-php` from `composer.json`.

Keep AWS database assets:

- Keep `database/migrations/20230811011410_aws_account_table.php`.
- Do not drop existing `aws` data.

After removal, a repository search for AWS route paths should show no callable `/user/aws` or `/user/server/aws` paths.

## Error Handling

All new actions return the existing `Tools::msg()` JSON shape.

Long-running reimage errors are also written to the matching `UserTask` using `UserTask::end(..., true, ...)`.

Reimage failures include rollback status. The user-facing message must distinguish:

- Failure before replacement started.
- Failure after replacement started and rollback succeeded.
- Failure after replacement started and rollback failed.

Azure exception handling should prefer the response body when available. If no response body exists, use the exception message.

Ownership failures render the existing user reject page or return a failure JSON response depending on whether the route is page-based or AJAX-based.

## Testing

Static verification:

- Run `php -l` on changed PHP files.
- Run `composer dump-autoload`.
- Search for remaining AWS route and menu references.
- Check that new route URLs match frontend AJAX calls.

Manual browser verification:

- VM detail page loads.
- Reimage, credential reset, and firewall forms render without overlapping controls.
- AJAX requests use the intended HTTP methods.
- Progress dialog works for reimage.

Azure integration verification requires a real Azure account and VM. If credentials are unavailable in the development environment, implementation verification will stop at syntax, autoload, route, and UI checks.
