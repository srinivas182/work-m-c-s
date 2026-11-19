# One WURK environment (Sprint 17.1): everything staging and production share, sized by their variables.

terraform {
  required_providers {
    aws        = { source = "hashicorp/aws", version = "~> 5.80", configuration_aliases = [aws.us_east_1] }
    random     = { source = "hashicorp/random", version = "~> 3.6" }
    cloudflare = { source = "cloudflare/cloudflare", version = "~> 4.52" }
  }
}

data "aws_caller_identity" "this" {}
data "aws_region" "this" {}

locals {
  name = "wurk-${var.environment}"
  # WURK's backups account (17.4): copies of this environment's backups are made there, so it may use this key.
  backup_account = var.offsite_vault_arn == null ? null : split(":", var.offsite_vault_arn)[4]
}

# One key per environment encrypts its databases, cache, files, secrets, registry and logs.
resource "aws_kms_key" "this" {
  description             = "WURK ${var.environment}"
  enable_key_rotation     = true
  deletion_window_in_days = 30
  policy = jsonencode({
    Version = "2012-10-17"
    Statement = concat(local.backup_account == null ? [] : [{
      Sid       = "BackupsAccountCopies"
      Effect    = "Allow"
      Principal = { AWS = "arn:aws:iam::${local.backup_account}:root" }
      Action    = ["kms:Decrypt", "kms:DescribeKey", "kms:CreateGrant", "kms:ReEncrypt*", "kms:GenerateDataKey*"]
      Resource  = "*"
      }], [
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
    ])
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

module "edge" {
  source                  = "../edge"
  environment             = var.environment
  zone_id                 = var.cloudflare_zone_id
  root_domain             = var.root_domain
  load_balancer_dns       = module.app.load_balancer_dns
  files_cloudfront_domain = module.files.cloudfront_domain
  manage_zone             = var.environment == "production"
  providers = {
    aws           = aws
    aws.us_east_1 = aws.us_east_1
  }
}

module "files" {
  source                 = "../files"
  name                   = local.name
  environment            = var.environment
  kms_key_arn            = aws_kms_key.this.arn
  public_domain          = "files.${var.root_domain}"
  public_certificate_arn = module.edge.files_certificate_arn
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
  source            = "../app"
  name              = local.name
  environment       = var.environment
  vpc_id            = module.network.vpc_id
  vpc_cidr          = var.vpc_cidr
  public_subnet_ids = module.network.public_subnet_ids
  app_subnet_ids    = module.network.app_subnet_ids
  kms_key_arn       = aws_kms_key.this.arn
  image_tag         = var.image_tag
  root_domain       = var.root_domain
  app_domain        = "app.${var.root_domain}"
  certificate_arn   = module.edge.certificate_arn
  cloudflare_ipv4   = module.edge.cloudflare_ipv4
  extra_environment = {
    CLOUDFLARE_ZONE_ID        = var.cloudflare_zone_id # 17.3: owners' own domains (the token is in wurk/<env>/app)
    WURK_CUSTOM_DOMAIN_TARGET = module.edge.custom_domain_target
    WURK_CUSTOM_DOMAIN_ORIGIN = "origin.${var.root_domain}"
    WATCHTOWER_ALARM_TOPIC    = module.monitoring.topic_arn # 17.5: the cloud's alarms (the token is in wurk/<env>/app)
  }
  alarm_token         = module.monitoring.token
  central_db_host     = module.database.endpoints["central"]
  db_secret_arn       = module.database.secret_arn
  redis_host          = module.cache.primary_endpoint
  redis_auth_token    = module.cache.auth_token
  files_bucket        = module.files.bucket
  files_bucket_arn    = module.files.bucket_arn
  files_public_url    = module.files.public_url
  logs_bucket         = module.files.logs_bucket
  backup_targets      = concat(values(module.database.instance_ids), [module.files.bucket]) # as AWS Backup names them (17.4)
  serve               = var.serve
  worker              = var.worker
  deletion_protection = var.deletion_protection
  ecr_pull_role_arns  = var.ecr_pull_role_arns
}

module "deploy" {
  source                = "../deploy"
  name                  = local.name
  environment           = var.environment
  github_repository     = var.github_repository
  create_oidc_provider  = var.create_github_oidc_provider
  cluster_name          = module.app.cluster_name
  repository_arn        = module.app.repository_arn
  kms_key_arn           = aws_kms_key.this.arn
  task_role_arns        = module.app.task_role_arns
  database_arns         = values(module.database.instance_arns)
  source_repository_arn = var.source_repository_arn
}

module "backup" {
  source                 = "../backup"
  name                   = local.name
  kms_key_arn            = aws_kms_key.this.arn
  retention_days         = var.backup_retention_days
  offsite_vault_arn      = var.offsite_vault_arn
  offsite_retention_days = var.environment == "production" ? 90 : 14
  database_arns          = values(module.database.instance_arns)
  files_bucket_arn       = module.files.bucket_arn
  cluster_arn            = module.app.cluster_arn
  release_family         = module.app.release_task_definition
  task_role_arns         = module.app.task_role_arns
  app_subnet_ids         = module.app.app_subnet_ids
  app_security_group_id  = module.app.app_security_group_id
}

module "monitoring" {
  source                   = "../monitoring"
  name                     = local.name
  app_domain               = "app.${var.root_domain}"
  kms_key_arn              = aws_kms_key.this.arn
  alert_emails             = var.alert_emails
  load_balancer_arn_suffix = module.app.load_balancer_arn_suffix
  target_group_arn_suffix  = module.app.target_group_arn_suffix
  cluster_name             = module.app.cluster_name
  cache_id                 = module.cache.replication_group_id
  database_ids             = module.database.instance_ids
}
