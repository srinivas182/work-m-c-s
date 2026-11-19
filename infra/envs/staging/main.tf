# Staging (Sprint 17.1): the same stack as production on smaller sizes, single-AZ, one NAT gateway, two shards.

terraform {
  required_version = ">= 1.8.0"
  required_providers {
    aws        = { source = "hashicorp/aws", version = "~> 5.80" }
    random     = { source = "hashicorp/random", version = "~> 3.6" }
    cloudflare = { source = "cloudflare/cloudflare", version = "~> 4.52" }
  }
  backend "s3" {
    region         = "af-south-1"
    key            = "staging/terraform.tfstate"
    encrypt        = true
    dynamodb_table = "wurk-tofu-locks"
    # bucket: passed at `tofu init -backend-config=bucket=...` (infra/README.md)
  }
}

provider "aws" {
  region              = "af-south-1"
  allowed_account_ids = [var.account_id]
  default_tags {
    tags = { Project = "wurk", Environment = "staging", ManagedBy = "opentofu" }
  }
}

# CloudFront's certificates live in us-east-1.
provider "aws" {
  alias               = "us_east_1"
  region              = "us-east-1"
  allowed_account_ids = [var.account_id]
  default_tags {
    tags = { Project = "wurk", Environment = "staging", ManagedBy = "opentofu" }
  }
}

# The token comes from CLOUDFLARE_API_TOKEN in the environment of whoever runs OpenTofu — never from a file.
provider "cloudflare" {}

variable "cloudflare_zone_id" {
  description = "wurk.co.za's Cloudflare zone"
  type        = string
}

variable "account_id" {
  description = "WURK's staging AWS account (guards against applying to the wrong one)"
  type        = string
}

variable "image_tag" {
  type = string
}

variable "production_deploy_role_arn" {
  description = "Production's deploy role (output deploy_role_arn there), allowed to pull tested images from here"
  type        = string
  default     = null
}

module "wurk" {
  providers = {
    aws           = aws
    aws.us_east_1 = aws.us_east_1
    cloudflare    = cloudflare
  }
  source                = "../../modules/stack"
  environment           = "staging"
  ecr_pull_role_arns    = var.production_deploy_role_arn == null ? [] : [var.production_deploy_role_arn]
  root_domain           = "staging.wurk.co.za"
  cloudflare_zone_id    = var.cloudflare_zone_id
  image_tag             = var.image_tag
  vpc_cidr              = "10.41.0.0/16"
  single_nat_gateway    = true
  multi_az              = false
  backup_retention_days = 7
  deletion_protection   = false

  databases = {
    central = { instance_class = "db.t4g.small", storage_gb = 20, max_storage_gb = 100 }
    shard_1 = { instance_class = "db.t4g.small", storage_gb = 20, max_storage_gb = 100 }
    shard_2 = { instance_class = "db.t4g.small", storage_gb = 20, max_storage_gb = 100 }
  }
  cache_node_type = "cache.t4g.small"
  cache_replicas  = 0

  serve  = { cpu = 1024, memory = 2048, min = 1, max = 4, requests_per_task = 400 }
  worker = { cpu = 512, memory = 1024, min = 1, max = 3 }
}

output "wurk" {
  value = module.wurk
}
