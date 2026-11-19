# Production (Sprint 17.1): Multi-AZ databases with 35 days of point-in-time restore, a cache replica, a NAT gateway
# per zone, deletion protection on. Sizes are the launch sizes (decision F); shards are added as businesses grow.

terraform {
  required_version = ">= 1.8.0"
  required_providers {
    aws        = { source = "hashicorp/aws", version = "~> 5.80" }
    random     = { source = "hashicorp/random", version = "~> 3.6" }
    cloudflare = { source = "cloudflare/cloudflare", version = "~> 4.52" }
  }
  backend "s3" {
    region         = "af-south-1"
    key            = "production/terraform.tfstate"
    encrypt        = true
    dynamodb_table = "wurk-tofu-locks"
  }
}

provider "aws" {
  region              = "af-south-1"
  allowed_account_ids = [var.account_id]
  default_tags {
    tags = { Project = "wurk", Environment = "production", ManagedBy = "opentofu" }
  }
}

# CloudFront's certificates live in us-east-1.
provider "aws" {
  alias               = "us_east_1"
  region              = "us-east-1"
  allowed_account_ids = [var.account_id]
  default_tags {
    tags = { Project = "wurk", Environment = "production", ManagedBy = "opentofu" }
  }
}

# The token comes from CLOUDFLARE_API_TOKEN in the environment of whoever runs OpenTofu — never from a file.
provider "cloudflare" {}

variable "cloudflare_zone_id" {
  description = "wurk.co.za's Cloudflare zone"
  type        = string
}

variable "account_id" {
  description = "WURK's production AWS account"
  type        = string
}

variable "image_tag" {
  type = string
}

variable "staging_repository_arn" {
  description = "Staging's registry (output repository_arn there): production deploys the image staging tested"
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
  environment           = "production"
  source_repository_arn = var.staging_repository_arn
  root_domain           = "wurk.co.za"
  cloudflare_zone_id    = var.cloudflare_zone_id
  image_tag             = var.image_tag
  vpc_cidr              = "10.40.0.0/16"
  single_nat_gateway    = false
  multi_az              = true
  backup_retention_days = 35
  deletion_protection   = true

  databases = {
    central = { instance_class = "db.m6g.large", storage_gb = 50, max_storage_gb = 500 }
    shard_1 = { instance_class = "db.m6g.large", storage_gb = 100, max_storage_gb = 1000 }
    shard_2 = { instance_class = "db.m6g.large", storage_gb = 100, max_storage_gb = 1000 }
  }
  cache_node_type = "cache.m6g.large"
  cache_replicas  = 1

  serve  = { cpu = 2048, memory = 4096, min = 3, max = 20, requests_per_task = 600 }
  worker = { cpu = 1024, memory = 2048, min = 2, max = 10 }
}

output "wurk" {
  value     = module.wurk
  sensitive = false
}
