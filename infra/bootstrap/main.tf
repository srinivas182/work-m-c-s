# Run once per AWS account, before anything else (infra/README.md): the encrypted, versioned bucket that keeps
# OpenTofu's state and the table that locks it, so two people (or CI runs) never change an environment at once.
# Its own state stays local — commit nothing from here except this file.

terraform {
  required_version = ">= 1.8.0"
  required_providers {
    aws = { source = "hashicorp/aws", version = "~> 5.80" }
  }
}

provider "aws" {
  region              = "af-south-1"
  allowed_account_ids = [var.account_id]
  default_tags {
    tags = { Project = "wurk", ManagedBy = "opentofu", Purpose = "tofu-state" }
  }
}

variable "account_id" {
  type = string
}

data "aws_caller_identity" "this" {}

resource "aws_kms_key" "state" {
  description         = "WURK OpenTofu state"
  enable_key_rotation = true
  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Sid       = "Account"
      Effect    = "Allow"
      Principal = { AWS = "arn:aws:iam::${data.aws_caller_identity.this.account_id}:root" }
      Action    = "kms:*"
      Resource  = "*"
    }]
  })
}

resource "aws_s3_bucket" "state" {
  #checkov:skip=CKV_AWS_144:state stays in South Africa
  #checkov:skip=CKV2_AWS_62:no events on state
  #checkov:skip=CKV_AWS_18:access to state is logged by CloudTrail
  bucket = "wurk-tofu-state-${var.account_id}"
  lifecycle {
    prevent_destroy = true
  }
}

resource "aws_s3_bucket_versioning" "state" {
  bucket = aws_s3_bucket.state.id
  versioning_configuration {
    status = "Enabled"
  }
}

resource "aws_s3_bucket_server_side_encryption_configuration" "state" {
  bucket = aws_s3_bucket.state.id
  rule {
    apply_server_side_encryption_by_default {
      sse_algorithm     = "aws:kms"
      kms_master_key_id = aws_kms_key.state.arn
    }
    bucket_key_enabled = true
  }
}

resource "aws_s3_bucket_public_access_block" "state" {
  bucket                  = aws_s3_bucket.state.id
  block_public_acls       = true
  ignore_public_acls      = true
  block_public_policy     = true
  restrict_public_buckets = true
}

resource "aws_s3_bucket_lifecycle_configuration" "state" {
  bucket = aws_s3_bucket.state.id
  rule {
    id     = "old-state-versions"
    status = "Enabled"
    filter {}
    noncurrent_version_expiration {
      noncurrent_days = 365
    }
    abort_incomplete_multipart_upload {
      days_after_initiation = 7
    }
  }
}

resource "aws_dynamodb_table" "locks" {
  name         = "wurk-tofu-locks"
  billing_mode = "PAY_PER_REQUEST"
  hash_key     = "LockID"

  attribute {
    name = "LockID"
    type = "S"
  }

  point_in_time_recovery {
    enabled = true
  }

  server_side_encryption {
    enabled     = true
    kms_key_arn = aws_kms_key.state.arn
  }
}

output "state_bucket" {
  value = aws_s3_bucket.state.bucket
}
