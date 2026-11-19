variable "name" {
  description = "Name prefix, e.g. wurk-production"
  type        = string
}

variable "environment" {
  description = "staging or production"
  type        = string
}

variable "vpc_id" {
  type = string
}

variable "subnet_ids" {
  description = "The data subnets"
  type        = list(string)
}

variable "app_security_group_id" {
  description = "WURK's containers, the only ones allowed to connect"
  type        = string
}

variable "kms_key_arn" {
  type = string
}

variable "databases" {
  description = "central plus one entry per shard (shard_1, shard_2, …), each with its size"
  type = map(object({
    instance_class = string
    storage_gb     = number
    max_storage_gb = number
  }))

  validation {
    condition     = contains(keys(var.databases), "central") && length(var.databases) >= 2
    error_message = "Give the central database and at least one shard."
  }
}

variable "engine_version" {
  type    = string
  default = "8.0"
}

variable "multi_az" {
  description = "A standby in a second zone (production)"
  type        = bool
}

variable "backup_retention_days" {
  description = "Point-in-time restore window: 35 in production, 7 in staging"
  type        = number

  validation {
    condition     = var.backup_retention_days >= 7 && var.backup_retention_days <= 35
    error_message = "Keep from 7 to 35 days."
  }
}

variable "deletion_protection" {
  type    = bool
  default = true
}
