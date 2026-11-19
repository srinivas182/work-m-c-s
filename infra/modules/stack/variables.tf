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

variable "certificate_arn" {
  type = string
}

variable "files_domain" {
  type    = string
  default = null
}

variable "files_certificate_arn" {
  type    = string
  default = null
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

variable "cloudflare_ipv4" {
  description = "From https://www.cloudflare.com/ips-v4 — check before each apply (17.3 reads them from Cloudflare's API)"
  type        = list(string)
  default = [
    "173.245.48.0/20", "103.21.244.0/22", "103.22.200.0/22", "103.31.4.0/22", "141.101.64.0/18", "108.162.192.0/18",
    "190.93.240.0/20", "188.114.96.0/20", "197.234.240.0/22", "198.41.128.0/17", "162.158.0.0/15", "104.16.0.0/13",
    "104.24.0.0/14", "172.64.0.0/13", "131.0.72.0/22",
  ]
}
