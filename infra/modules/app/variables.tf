variable "name" {
  type = string
}

variable "environment" {
  type = string
}

variable "vpc_id" {
  type = string
}

variable "vpc_cidr" {
  type = string
}

variable "public_subnet_ids" {
  type = list(string)
}

variable "app_subnet_ids" {
  type = list(string)
}

variable "kms_key_arn" {
  type = string
}

variable "image_tag" {
  description = "The version to run (an immutable tag in the registry, e.g. v1.0.0). Releases (17.2) change it."
  type        = string
}

variable "clamav_image" {
  type    = string
  default = "clamav/clamav:1.4"
}

variable "root_domain" {
  description = "wurk.co.za (staging: staging.wurk.co.za)"
  type        = string
}

variable "app_domain" {
  description = "app.wurk.co.za"
  type        = string
}

variable "certificate_arn" {
  description = "ACM certificate for the root domain and *.root (validated through Cloudflare DNS)"
  type        = string
}

variable "cloudflare_ipv4" {
  description = "Cloudflare's IPv4 ranges (cloudflare.com/ips): the only callers the load balancer accepts"
  type        = list(string)
}

variable "central_db_host" {
  type = string
}

variable "db_secret_arn" {
  type = string
}

variable "redis_host" {
  type = string
}

variable "redis_auth_token" {
  type      = string
  sensitive = true
}

variable "files_bucket" {
  type = string
}

variable "files_bucket_arn" {
  type = string
}

variable "files_public_url" {
  type = string
}

variable "logs_bucket" {
  type = string
}

variable "backup_targets" {
  description = "What Watchtower expects backups of (Sprint 18.4c), e.g. central, shard-1, files"
  type        = list(string)
}

variable "serve" {
  description = "Web containers: size (Fargate CPU units / MiB), how many, and requests per container before adding one"
  type = object({
    cpu               = number
    memory            = number
    min               = number
    max               = number
    requests_per_task = number
  })
}

variable "worker" {
  type = object({
    cpu    = number
    memory = number
    min    = number
    max    = number
  })
}

variable "ssr_count" {
  type    = number
  default = 2
}

variable "log_retention_days" {
  type    = number
  default = 30
}

variable "deletion_protection" {
  type    = bool
  default = true
}

variable "extra_environment" {
  description = "Further non-secret settings for the app"
  type        = map(string)
  default     = {}
}
