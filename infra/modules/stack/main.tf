# One WURK environment (Sprint 17.1): everything staging and production share, sized by their variables.

terraform {
  required_providers {
    aws    = { source = "hashicorp/aws", version = "~> 5.80" }
    random = { source = "hashicorp/random", version = "~> 3.6" }
  }
}

data "aws_caller_identity" "this" {}
data "aws_region" "this" {}

locals {
  name = "wurk-${var.environment}"
}

# One key per environment encrypts its databases, cache, files, secrets, registry and logs.
resource "aws_kms_key" "this" {
  description             = "WURK ${var.environment}"
  enable_key_rotation     = true
  deletion_window_in_days = 30
  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Sid       = "Account"
        Effect    = "Allow"
        Principal = { AWS = "arn:aws:iam::${data.aws_caller_identity.this.account_id}:root" }
        Action    = "kms:*"
        Resource  = "*"
      },
      {
        Sid       = "CloudWatchLogs"
        Effect    = "Allow"
        Principal = { Service = "logs.${data.aws_region.this.name}.amazonaws.com" }
        Action    = ["kms:Encrypt", "kms:Decrypt", "kms:ReEncrypt*", "kms:GenerateDataKey*", "kms:Describe*"]
        Resource  = "*"
        Condition = { ArnLike = { "kms:EncryptionContext:aws:logs:arn" = "arn:aws:logs:${data.aws_region.this.name}:${data.aws_caller_identity.this.account_id}:*" } }
      },
      {
        Sid       = "CloudFrontReadsFiles"
        Effect    = "Allow"
        Principal = { Service = "cloudfront.amazonaws.com" }
        Action    = ["kms:Decrypt"]
        Resource  = "*"
        Condition = { StringEquals = { "aws:SourceAccount" = data.aws_caller_identity.this.account_id } }
      },
    ]
  })
}

resource "aws_kms_alias" "this" {
  name          = "alias/${local.name}"
  target_key_id = aws_kms_key.this.key_id
}

module "network" {
  source             = "../network"
  name               = local.name
  region             = data.aws_region.this.name
  cidr               = var.vpc_cidr
  single_nat_gateway = var.single_nat_gateway
  kms_key_arn        = aws_kms_key.this.arn
}

module "files" {
  source                 = "../files"
  name                   = local.name
  environment            = var.environment
  kms_key_arn            = aws_kms_key.this.arn
  public_domain          = var.files_domain
  public_certificate_arn = var.files_certificate_arn
}

module "database" {
  source                = "../database"
  name                  = local.name
  environment           = var.environment
  vpc_id                = module.network.vpc_id
  subnet_ids            = module.network.data_subnet_ids
  app_security_group_id = module.app.app_security_group_id
  kms_key_arn           = aws_kms_key.this.arn
  databases             = var.databases
  multi_az              = var.multi_az
  backup_retention_days = var.backup_retention_days
  deletion_protection   = var.deletion_protection
}

module "cache" {
  source                  = "../cache"
  name                    = local.name
  vpc_id                  = module.network.vpc_id
  subnet_ids              = module.network.data_subnet_ids
  app_security_group_id   = module.app.app_security_group_id
  kms_key_arn             = aws_kms_key.this.arn
  node_type               = var.cache_node_type
  replicas                = var.cache_replicas
  snapshot_retention_days = var.multi_az ? 7 : 1
}

module "app" {
  source              = "../app"
  name                = local.name
  environment         = var.environment
  vpc_id              = module.network.vpc_id
  vpc_cidr            = var.vpc_cidr
  public_subnet_ids   = module.network.public_subnet_ids
  app_subnet_ids      = module.network.app_subnet_ids
  kms_key_arn         = aws_kms_key.this.arn
  image_tag           = var.image_tag
  root_domain         = var.root_domain
  app_domain          = "app.${var.root_domain}"
  certificate_arn     = var.certificate_arn
  cloudflare_ipv4     = var.cloudflare_ipv4
  central_db_host     = module.database.endpoints["central"]
  db_secret_arn       = module.database.secret_arn
  redis_host          = module.cache.primary_endpoint
  redis_auth_token    = module.cache.auth_token
  files_bucket        = module.files.bucket
  files_bucket_arn    = module.files.bucket_arn
  files_public_url    = module.files.public_url
  logs_bucket         = module.files.logs_bucket
  backup_targets      = concat([for k in keys(var.databases) : replace(k, "_", "-")], ["files"])
  serve               = var.serve
  worker              = var.worker
  deletion_protection = var.deletion_protection
}
