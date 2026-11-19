variable "environment" {
  type = string
}

variable "zone_id" {
  description = "Cloudflare zone of wurk.co.za"
  type        = string
}

variable "root_domain" {
  description = "wurk.co.za, or staging.wurk.co.za"
  type        = string
}

variable "load_balancer_dns" {
  description = "The load balancer (null until it exists)"
  type        = string
  default     = null
}

variable "files_cloudfront_domain" {
  type    = string
  default = null
}

variable "manage_zone" {
  description = "Own the zone's TLS settings and the custom-hostname fallback origin (production only)"
  type        = bool
  default     = false
}
