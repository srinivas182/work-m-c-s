variable "environment" {
  description = "staging or production"
  type        = string

  validation {
    condition     = contains(["staging", "production"], var.environment)
    error_message = "staging or production."
  }
}

variable "root_domain" {
  type = string
}

variable "image_tag" {
  type = string
}

variable "vpc_cidr" {
  type = string
}

variable "single_nat_gateway" {
  type = bool
}

variable "multi_az" {
  type = bool
}

variable "backup_retention_days" {
  type = number
}

variable "deletion_protection" {
  type = bool
}

variable "databases" {
  type = map(object({
    instance_class = string
    storage_gb     = number
    max_storage_gb = number
  }))
}

variable "cache_node_type" {
  type = string
}

variable "cache_replicas" {
  type = number
}

variable "serve" {
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

variable "cloudflare_zone_id" {
  description = "Cloudflare zone of wurk.co.za (17.3)"
  type        = string
}

variable "github_repository" {
  description = "The repository whose release workflow may deploy here"
  type        = string
  default     = "srinivas182/work-m-c-s"
}

variable "create_github_oidc_provider" {
  type    = bool
  default = true
}

variable "ecr_pull_role_arns" {
  description = "Staging: production's deploy role, so it can copy the tested image"
  type        = list(string)
  default     = []
}

variable "source_repository_arn" {
  description = "Production: staging's registry"
  type        = string
  default     = null
}

variable "offsite_vault_arn" {
  description = "The locked vault in WURK's backups account (envs/backups output); null until it exists"
  type        = string
  default     = null
}

variable "alert_emails" {
  description = "Extra addresses for the cloud's alarms (17.5), given when applying"
  type        = list(string)
  default     = []
}
