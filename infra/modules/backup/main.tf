# Backups (Sprint 17.4, decision C), on top of each database's own point-in-time restore and the files bucket's
# versioning:
# - AWS Backup takes a daily snapshot of every database and a backup of the files bucket, kept `retention_days`.
# - Each recovery point is copied to the vault in WURK's separate backups account (same region: the data stays in
#   South Africa). That vault is locked — nobody in the production account, not even an administrator, can delete
#   those copies.
# - Every finished backup is reported to WURK (`system:backup-taken`, a one-off release task started by EventBridge),
#   so Admin → System shows it and Watchtower raises a problem when one is late.

terraform {
  required_providers {
    aws = { source = "hashicorp/aws", version = "~> 5.80" }
  }
}

data "aws_region" "this" {}
data "aws_caller_identity" "this" {}

resource "aws_backup_vault" "this" {
  name        = var.name
  kms_key_arn = var.kms_key_arn
}

resource "aws_iam_role" "backup" {
  name = "${var.name}-backup"
  assume_role_policy = jsonencode({
    Version   = "2012-10-17"
    Statement = [{ Effect = "Allow", Principal = { Service = "backup.amazonaws.com" }, Action = "sts:AssumeRole" }]
  })
}

resource "aws_iam_role_policy_attachment" "backup" {
  for_each = toset([
    "arn:aws:iam::aws:policy/service-role/AWSBackupServiceRolePolicyForBackup",
    "arn:aws:iam::aws:policy/service-role/AWSBackupServiceRolePolicyForRestores",
    "arn:aws:iam::aws:policy/AWSBackupServiceRolePolicyForS3Backup",
    "arn:aws:iam::aws:policy/AWSBackupServiceRolePolicyForS3Restore",
  ])
  role       = aws_iam_role.backup.name
  policy_arn = each.value
}

resource "aws_backup_plan" "this" {
  name = var.name

  rule {
    rule_name         = "daily"
    target_vault_name = aws_backup_vault.this.name
    schedule          = "cron(0 23 * * ? *)" # 01:00 SAST
    start_window      = 60
    completion_window = 360

    lifecycle {
      delete_after = var.retention_days
    }

    dynamic "copy_action" {
      for_each = var.offsite_vault_arn == null ? [] : [1]
      content {
        destination_vault_arn = var.offsite_vault_arn
        lifecycle {
          delete_after = var.offsite_retention_days
        }
      }
    }
  }
}

resource "aws_backup_selection" "this" {
  name         = var.name
  plan_id      = aws_backup_plan.this.id
  iam_role_arn = aws_iam_role.backup.arn
  resources    = concat(var.database_arns, [var.files_bucket_arn])
}

# --- Report each finished backup to WURK ------------------------------------------------------------------------------

resource "aws_cloudwatch_event_rule" "backup_done" {
  name        = "${var.name}-backup-done"
  description = "A WURK backup finished: record it (system:backup-taken)"
  event_pattern = jsonencode({
    source        = ["aws.backup"]
    "detail-type" = ["Backup Job State Change"]
    detail        = { state = ["COMPLETED"], backupVaultName = [aws_backup_vault.this.name] }
  })
}

resource "aws_iam_role" "events" {
  name = "${var.name}-events"
  assume_role_policy = jsonencode({
    Version   = "2012-10-17"
    Statement = [{ Effect = "Allow", Principal = { Service = "events.amazonaws.com" }, Action = "sts:AssumeRole" }]
  })
}

resource "aws_iam_role_policy" "events" {
  role = aws_iam_role.events.id
  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Effect    = "Allow"
        Action    = "ecs:RunTask"
        Resource  = "arn:aws:ecs:${data.aws_region.this.name}:${data.aws_caller_identity.this.account_id}:task-definition/${var.release_family}:*"
        Condition = { ArnEquals = { "ecs:cluster" = var.cluster_arn } }
      },
      { Effect = "Allow", Action = "iam:PassRole", Resource = var.task_role_arns, Condition = { StringEquals = { "iam:PassedToService" = "ecs-tasks.amazonaws.com" } } },
    ]
  })
}

resource "aws_cloudwatch_event_target" "record" {
  rule     = aws_cloudwatch_event_rule.backup_done.name
  arn      = var.cluster_arn
  role_arn = aws_iam_role.events.arn

  ecs_target {
    task_definition_arn = "arn:aws:ecs:${data.aws_region.this.name}:${data.aws_caller_identity.this.account_id}:task-definition/${var.release_family}"
    launch_type         = "FARGATE"
    task_count          = 1
    network_configuration {
      subnets          = var.app_subnet_ids
      security_groups  = [var.app_security_group_id]
      assign_public_ip = false
    }
  }

  input_transformer {
    input_paths = {
      resource = "$.detail.resourceArn"
      point    = "$.detail.recoveryPointArn"
      size     = "$.detail.backupSizeInBytes"
      type     = "$.detail.resourceType"
    }
    input_template = <<-EOT
      {"containerOverrides": [{"name": "release", "command": ["php", "artisan", "system:backup-taken", "<resource>", "--kind=snapshot", "--size=<size>", "--location=<point>", "--by=aws-backup <type>"]}]}
    EOT
  }
}
