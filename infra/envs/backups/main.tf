# WURK's backups account (Sprint 17.4, decision C): one locked vault in Cape Town that production's and staging's
# backups are copied into. The vault lock (compliance mode, after a 3-day grace period to correct mistakes) means no
# one — not an administrator of this account, not someone who takes over production — can delete or shorten the
# copies before their time. The data never leaves South Africa.

terraform {
  required_version = ">= 1.8.0"
  required_providers {
    aws = { source = "hashicorp/aws", version = "~> 5.80" }
  }
  backend "s3" {
    region         = "af-south-1"
    key            = "backups/terraform.tfstate"
    encrypt        = true
    dynamodb_table = "wurk-tofu-locks"
  }
}

provider "aws" {
  region              = "af-south-1"
  allowed_account_ids = [var.account_id]
  default_tags {
    tags = { Project = "wurk", Environment = "backups", ManagedBy = "opentofu" }
  }
}

variable "account_id" {
  description = "WURK's backups AWS account"
  type        = string
}

variable "source_account_ids" {
  description = "Production's and staging's accounts, allowed to copy into the vault"
  type        = list(string)
}

variable "organization_id" {
  description = "The AWS Organization the accounts belong to (o-…)"
  type        = string
}

data "aws_caller_identity" "this" {}

resource "aws_kms_key" "vault" {
  description         = "WURK off-site backups"
  enable_key_rotation = true
  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      { Sid = "Account", Effect = "Allow", Principal = { AWS = "arn:aws:iam::${data.aws_caller_identity.this.account_id}:root" }, Action = "kms:*", Resource = "*" },
      {
        Sid       = "SourceAccountsCopyIn"
        Effect    = "Allow"
        Principal = { AWS = [for id in var.source_account_ids : "arn:aws:iam::${id}:root"] }
        Action    = ["kms:Encrypt", "kms:ReEncrypt*", "kms:GenerateDataKey*", "kms:DescribeKey", "kms:CreateGrant"]
        Resource  = "*"
      },
    ]
  })
}

resource "aws_backup_vault" "offsite" {
  name        = "wurk-offsite"
  kms_key_arn = aws_kms_key.vault.arn
}

resource "aws_backup_vault_policy" "offsite" {
  backup_vault_name = aws_backup_vault.offsite.name
  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Sid       = "OrganizationCopiesIn"
      Effect    = "Allow"
      Principal = "*"
      Action    = "backup:CopyIntoBackupVault"
      Resource  = "*"
      Condition = { StringEquals = { "aws:PrincipalOrgID" = var.organization_id } }
    }]
  })
}

resource "aws_backup_vault_lock_configuration" "offsite" {
  backup_vault_name   = aws_backup_vault.offsite.name
  min_retention_days  = 7
  max_retention_days  = 400
  changeable_for_days = 3 # after this, compliance mode: the lock itself can't be removed
}

output "offsite_vault_arn" {
  description = "Pass to production and staging as offsite_vault_arn"
  value       = aws_backup_vault.offsite.arn
}
