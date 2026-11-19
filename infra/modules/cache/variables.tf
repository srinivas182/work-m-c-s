variable "name" {
  type = string
}

variable "vpc_id" {
  type = string
}

variable "subnet_ids" {
  description = "The data subnets"
  type        = list(string)
}

variable "app_security_group_id" {
  type = string
}

variable "kms_key_arn" {
  type = string
}

variable "node_type" {
  type = string
}

variable "replicas" {
  description = "Read replicas in other zones (1 in production for failover, 0 in staging)"
  type        = number
}

variable "engine_version" {
  type    = string
  default = "8.0"
}

variable "snapshot_retention_days" {
  type    = number
  default = 1
}
