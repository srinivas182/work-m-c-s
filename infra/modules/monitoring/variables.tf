variable "name" {
  type = string
}

variable "app_domain" {
  description = "app.<root>, where WURK receives its alarms"
  type        = string
}

variable "kms_key_arn" {
  type = string
}

variable "alert_emails" {
  description = "Also email alarms here, for when WURK itself can't (give with -var/TF_VAR when applying; never commit)"
  type        = list(string)
  default     = []
}

variable "load_balancer_arn_suffix" {
  type = string
}

variable "target_group_arn_suffix" {
  type = string
}

variable "cluster_name" {
  type = string
}

variable "cache_id" {
  type = string
}

variable "database_ids" {
  description = "central, shard_1, … => instance identifier"
  type        = map(string)
}
