# Staging (Sprint 17.1): the same stack as production on smaller sizes, single-AZ, one NAT gateway, two shards.

terraform {
  required_version = ">= 1.8.0"
  required_providers {
    aws    = { source = "hashicorp/aws", version = "~> 5.80" }
    random = { source = "hashicorp/random", version = "~> 3.6" }
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

variable "account_id" {
  description = "WURK's staging AWS account (guards against applying to the wrong one)"
  type        = string
}

variable "image_tag" {
  type = string
}

variable "certificate_arn" {
  type = string
}

module "wurk" {
  source                = "../../modules/stack"
  environment           = "staging"
  root_domain           = "staging.wurk.co.za"
  certificate_arn       = var.certificate_arn
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
